<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching;

use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Webconsulting\DocxEditor\PageSync\Configuration\PageSyncSettings;
use Webconsulting\DocxEditor\PageSync\Matching\Event\ModifyContentTypeProposalsEvent;
use Webconsulting\DocxEditor\PageSync\Matching\Jev\JevContentTypeChooser;
use Webconsulting\DocxEditor\PageSync\Segmentation\Part;

/**
 * Finds the content element type that fits each part.
 *
 * 1. Every rule scores every allowed type; the best score per type counts.
 * 2. Listeners of ModifyContentTypeProposalsEvent may re-rank.
 * 3. Where the best types are too close for the structure to decide, Jev chooses among them —
 *    if its answer clears the confidence threshold. Otherwise the best structural type stays
 *    and the part is flagged for review, as is any part whose best fit is poor.
 */
final readonly class ContentTypeMatcher
{
    /** Proposals kept per part for the plan (the editor can pick any allowed type anyway). */
    private const int KEPT_PROPOSALS = 8;

    /** Types within this distance of the best score are contenders. */
    private const float CONTENDER_WINDOW = 0.15;

    /** A structural choice this clear needs no second opinion. */
    private const float CLEAR_MARGIN = 0.05;

    /** Below this fit a part is always flagged for review. */
    private const float POOR_FIT = 0.5;

    private const float MINIMUM_CONTENDER_SCORE = 0.3;

    /**
     * @param iterable<MappingRuleInterface> $rules
     */
    public function __construct(
        #[AutowireIterator(MappingRuleInterface::TAG)]
        private iterable $rules,
        private EventDispatcherInterface $eventDispatcher,
        private JevContentTypeChooser $jev,
        private PageSyncSettings $settings,
    ) {}

    /**
     * @param list<Part> $parts
     * @param array<string, ContentTypeCandidate> $candidates Keyed by CType
     *
     * @return list<PartMatch>
     */
    public function match(array $parts, array $candidates, MatchContext $context): array
    {
        $matches = [];
        foreach ($parts as $part) {
            $matches[] = $this->structuralMatch($part, $candidates, $context);
        }

        $questions = [];
        foreach ($matches as $match) {
            $contenders = $this->contenders($match);
            if (count($contenders) >= 2) {
                $questions[] = ['part' => $match->part, 'contenders' => $contenders];
            }
        }
        $verdicts = $context->useJev && $questions !== [] ? $this->jev->choose($questions, $candidates, $context) : [];

        $result = [];
        foreach ($matches as $match) {
            $best = $match->proposals[0] ?? null;
            if ($best === null) {
                $result[] = $match;
                continue;
            }
            $verdict = $verdicts[$match->part->id] ?? null;
            $contested = count($this->contenders($match)) >= 2;
            $jevChoice = $verdict !== null && $verdict->confident && $verdict->choice !== null ? $match->proposal($verdict->choice) : null;
            if ($verdict !== null && $jevChoice !== null) {
                $result[] = $match->withChoice($jevChoice, $verdict->confidence, PartMatch::BY_JEV, $jevChoice->score < self::POOR_FIT, $verdict);
                continue;
            }
            // Jev unavailable, not asked, below its threshold, or choosing something not on offer.
            $needsReview = $best->score < self::POOR_FIT || $contested;
            $result[] = $match->withChoice($best, $match->confidence, PartMatch::BY_STRUCTURE, $needsReview, $verdict);
        }

        return $result;
    }

    /**
     * @param array<string, ContentTypeCandidate> $candidates
     */
    public function structuralMatch(Part $part, array $candidates, MatchContext $context): PartMatch
    {
        $excluded = $this->settings->excludedContentTypes();
        /** @var array<string, Proposal> $best */
        $best = [];
        foreach ($candidates as $cType => $candidate) {
            if (in_array($cType, $excluded, true)) {
                continue;
            }
            foreach ($this->rules as $rule) {
                $proposal = $rule->propose($part->shape, $candidate);
                if ($proposal === null || $proposal->cType !== $cType) {
                    continue;
                }
                if (!isset($best[$cType]) || $proposal->rank() > $best[$cType]->rank()) {
                    $best[$cType] = $proposal;
                }
            }
        }
        $proposals = self::sorted(array_values($best));

        $event = new ModifyContentTypeProposalsEvent($part, $proposals, $candidates, $context);
        $this->eventDispatcher->dispatch($event);
        $proposals = self::sorted(array_values(array_filter(
            $event->getProposals(),
            static fn(Proposal $proposal): bool => isset($candidates[$proposal->cType]) && !in_array($proposal->cType, $excluded, true),
        )));
        $proposals = array_slice($proposals, 0, self::KEPT_PROPOSALS);

        return new PartMatch(
            part: $part,
            proposals: $proposals,
            chosen: $proposals[0] ?? null,
            confidence: self::structuralConfidence($proposals),
            needsReview: ($proposals[0] ?? null) === null || $proposals[0]->score < self::POOR_FIT,
        );
    }

    /**
     * @return list<Proposal>
     */
    private function contenders(PartMatch $match): array
    {
        $best = $match->proposals[0] ?? null;
        $second = $match->proposals[1] ?? null;
        if ($best === null || $second === null || $best->rank() - $second->rank() >= self::CLEAR_MARGIN) {
            return [];
        }

        return $match->contenders(self::CONTENDER_WINDOW, $this->settings->jevMaxCandidates(), self::MINIMUM_CONTENDER_SCORE);
    }

    /**
     * How sure the structure is: the best fit, discounted when the runner-up is close.
     *
     * @param list<Proposal> $proposals
     */
    private static function structuralConfidence(array $proposals): float
    {
        $best = $proposals[0] ?? null;
        if ($best === null) {
            return 0.0;
        }
        $second = $proposals[1] ?? null;
        $margin = $second === null ? 1.0 : $best->rank() - $second->rank();

        return round($best->score * min(1.0, 0.5 + $margin / 0.2), 4);
    }

    /**
     * Best first; equal scores keep the core types first, then alphabetical order, so the
     * outcome never depends on the order types were registered in.
     *
     * @param list<Proposal> $proposals
     *
     * @return list<Proposal>
     */
    private static function sorted(array $proposals): array
    {
        usort($proposals, static function (Proposal $a, Proposal $b): int {
            $byScore = round($b->rank(), 6) <=> round($a->rank(), 6);
            if ($byScore !== 0) {
                return $byScore;
            }
            $byCore = (int)in_array($b->cType, ContentTypeCandidate::CORE_TYPES, true) <=> (int)in_array($a->cType, ContentTypeCandidate::CORE_TYPES, true);

            return $byCore !== 0 ? $byCore : strcmp($a->cType, $b->cType);
        });

        return $proposals;
    }
}

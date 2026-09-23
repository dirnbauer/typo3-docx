<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching\Jev;

use Webconsulting\DocxEditor\PageSync\Configuration\PageSyncSettings;
use Webconsulting\DocxEditor\PageSync\Matching\ContentTypeCandidate;
use Webconsulting\DocxEditor\PageSync\Matching\MatchContext;
use Webconsulting\DocxEditor\PageSync\Matching\Proposal;
use Webconsulting\DocxEditor\PageSync\Schema\ElementShapeFactory;
use Webconsulting\DocxEditor\PageSync\Segmentation\Part;
use Webconsulting\WebconJev\Client\Dto\QuestionType;
use Webconsulting\WebconJev\Domain\Model\Criterion;
use Webconsulting\WebconJev\Domain\Model\Decision;
use Webconsulting\WebconJev\Domain\Model\DecisionQuestion;
use Webconsulting\WebconJev\Service\DecisionRunner;

/**
 * Lets Jev (webcon_jev) pick the content element type for parts the structure alone cannot
 * decide.
 *
 * The structural matcher has already narrowed the allowed types down to a few contenders per
 * part; Jev gets one choice question per part — which of these types presents it best — with
 * the contenders as options and the parts' structure and text as state. Parts of one document
 * share requests as far as the size limits allow. The decision is not stored: it is built for
 * the document and run through webcon_jev's DecisionRunner, which handles the switch, the token,
 * the budget, the cache and the run log, and falls back instead of failing.
 *
 * webcon_jev is optional. Without it the runner is null and every part keeps its structural
 * choice.
 */
final readonly class JevContentTypeChooser
{
    public const string RUN_CONTEXT = 'docx_editor_page_import';
    public const string DECISION_IDENTIFIER = 'docx_editor.content_type';

    /** Jev accepts 32k of state and 64k per request; stay clear of both. */
    private const int MAX_STATE_CHARACTERS = 28_000;
    private const int MAX_REQUEST_CHARACTERS = 56_000;
    private const int EXCERPT_LENGTH = 500;
    private const int CRITERION_LENGTH = 400;

    public function __construct(
        private PageSyncSettings $settings,
        private ElementShapeFactory $shapes,
        private ?DecisionRunner $runner = null,
    ) {}

    public function isAvailable(): bool
    {
        return $this->runner !== null && $this->settings->jevEnabled();
    }

    public function unavailableReason(): string
    {
        return $this->runner === null ? 'webcon_jev is not installed' : 'Jev is switched off in the docx_editor settings';
    }

    /**
     * @param list<array{part: Part, contenders: list<Proposal>}> $questions Parts with at least two contenders each
     * @param array<string, ContentTypeCandidate> $candidates Keyed by CType
     *
     * @return array<string, JevVerdict> Keyed by part id
     */
    public function choose(array $questions, array $candidates, MatchContext $context): array
    {
        $questions = array_values(array_filter($questions, static fn(array $question): bool => count($question['contenders']) >= 2));
        if ($questions === []) {
            return [];
        }
        if (!$this->isAvailable() || $this->runner === null) {
            $verdicts = [];
            foreach ($questions as $question) {
                $verdicts[$question['part']->id] = JevVerdict::unavailable($this->unavailableReason());
            }

            return $verdicts;
        }

        $verdicts = [];
        foreach ($this->batches($questions, $candidates, $context) as $batch) {
            $verdicts += $this->ask($batch['questions'], $batch['state'], $context, $this->runner);
        }

        return $verdicts;
    }

    /**
     * @param list<array{part: Part, contenders: list<Proposal>}> $questions
     * @param array<string, ContentTypeCandidate> $candidates
     *
     * @return list<array{questions: list<DecisionQuestion>, state: array<string, mixed>}>
     */
    private function batches(array $questions, array $candidates, MatchContext $context): array
    {
        $document = array_filter([
            'title' => $context->documentTitle,
            'language' => $context->languageTag,
        ], static fn(string $value): bool => $value !== '');

        $batches = [];
        $current = ['questions' => [], 'parts' => []];
        $stateSize = 0;
        $requestSize = 0;
        foreach ($questions as $position => $question) {
            $part = $question['part'];
            $partState = [
                'position' => $position + 1,
                'structure' => $part->shape->describe(),
                'heading' => $part->shape->headingText(),
                'text' => $part->shape->excerpt(self::EXCERPT_LENGTH),
            ];
            $decisionQuestion = $this->question($part, $question['contenders'], $candidates);
            $partSize = strlen((string)json_encode($partState));
            $questionSize = strlen((string)json_encode($decisionQuestion->toArray()));
            if ($current['questions'] !== [] && ($stateSize + $partSize > self::MAX_STATE_CHARACTERS || $requestSize + $partSize + $questionSize > self::MAX_REQUEST_CHARACTERS)) {
                $batches[] = $current;
                $current = ['questions' => [], 'parts' => []];
                $stateSize = 0;
                $requestSize = 0;
            }
            $current['questions'][] = $decisionQuestion;
            $current['parts'][$part->id] = $partState;
            $stateSize += $partSize;
            $requestSize += $partSize + $questionSize;
        }
        if ($current['questions'] !== []) {
            $batches[] = $current;
        }

        return array_map(
            static fn(array $batch): array => [
                'questions' => $batch['questions'],
                'state' => ['document' => $document, 'parts' => $batch['parts']],
            ],
            $batches,
        );
    }

    /**
     * One thing per question: which of these types presents this one part best.
     *
     * @param list<Proposal> $contenders
     * @param array<string, ContentTypeCandidate> $candidates
     */
    private function question(Part $part, array $contenders, array $candidates): DecisionQuestion
    {
        $criteria = [];
        foreach ($contenders as $proposal) {
            $candidate = $candidates[$proposal->cType] ?? null;
            if ($candidate === null) {
                continue;
            }
            $criteria[] = new Criterion(0, $proposal->cType, $this->describe($candidate), outcomeValue: $proposal->cType);
        }

        return new DecisionQuestion(
            uid: 0,
            name: $part->id,
            type: QuestionType::Choice,
            instructions: sprintf(
                'The state holds the parts of a Word document that is being imported into a TYPO3 page. '
                . 'Which content element type presents document part "%s" best? '
                . 'Choose the type whose purpose and fields fit that part\'s structure and text.',
                $part->id,
            ),
            criteria: $criteria,
        );
    }

    private function describe(ContentTypeCandidate $candidate): string
    {
        $label = $this->shapes->englishLabel($candidate->shape->label);
        $description = $this->shapes->englishLabel($candidate->shape->description);
        $text = $label . ($description !== '' && $description !== $candidate->shape->description ? ' — ' . $description : '')
            . '. Fields: ' . $candidate->shape->summary() . '.';

        return mb_strlen($text) > self::CRITERION_LENGTH ? mb_substr($text, 0, self::CRITERION_LENGTH - 1) . '…' : $text;
    }

    /**
     * @param list<DecisionQuestion> $questions
     * @param array<string, mixed> $state
     *
     * @return array<string, JevVerdict>
     */
    private function ask(array $questions, array $state, MatchContext $context, DecisionRunner $runner): array
    {
        $decision = new Decision(
            uid: 0,
            identifier: self::DECISION_IDENTIFIER,
            title: 'Content element type per Word document part',
            description: 'Asked by docx_editor when a Word document is imported into a page.',
            stateTemplate: '',
            model: '',
            confidenceThreshold: $this->settings->jevConfidenceThreshold(),
            cacheLifetime: $this->settings->jevCacheLifetime(),
            defaultOutcome: '',
            questions: $questions,
            languageId: $context->languageId,
        );
        $outcome = $runner->run($decision, $state, self::RUN_CONTEXT, 'page:' . $context->pageUid);

        $verdicts = [];
        foreach ($questions as $question) {
            if ($outcome->isFallback()) {
                $verdicts[$question->name] = JevVerdict::unavailable($outcome->result->fallbackReason ?? 'Jev did not answer');
                continue;
            }
            $answer = $outcome->answer($question->name);
            if ($answer === null || $answer->choice === null) {
                $verdicts[$question->name] = JevVerdict::unavailable('Jev gave no answer for this part');
                continue;
            }
            $probabilities = [];
            foreach ($answer->probabilities as $option => $probability) {
                $probabilities[(string)$option] = (float)$probability;
            }
            arsort($probabilities);
            $verdicts[$question->name] = new JevVerdict(
                choice: $answer->choice,
                confidence: $answer->confidence,
                probabilities: $probabilities,
                confident: $outcome->confidentAnswer($question->name) !== null,
            );
        }

        return $verdicts;
    }
}

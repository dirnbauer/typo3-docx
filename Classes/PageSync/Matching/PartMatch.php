<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching;

use Webconsulting\DocxEditor\PageSync\Matching\Jev\JevVerdict;
use Webconsulting\DocxEditor\PageSync\Segmentation\Part;

/**
 * The content element type chosen for one part, how it was chosen and how sure that is.
 */
final readonly class PartMatch
{
    public const string BY_STRUCTURE = 'structure';
    public const string BY_JEV = 'jev';
    public const string BY_EDITOR = 'editor';

    /**
     * @param list<Proposal> $proposals Best first
     */
    public function __construct(
        public Part $part,
        public array $proposals,
        public ?Proposal $chosen,
        public float $confidence,
        public string $decidedBy = self::BY_STRUCTURE,
        public bool $needsReview = false,
        public ?JevVerdict $jev = null,
    ) {}

    public function proposal(string $cType): ?Proposal
    {
        return array_find($this->proposals, static fn(Proposal $proposal): bool => $proposal->cType === $cType);
    }

    /**
     * The candidates close enough to the best one that the structure alone cannot decide.
     *
     * @return list<Proposal>
     */
    public function contenders(float $window, int $max, float $minimum): array
    {
        $best = $this->proposals[0] ?? null;
        if ($best === null) {
            return [];
        }
        $contenders = array_values(array_filter(
            $this->proposals,
            static fn(Proposal $proposal): bool => $proposal->score >= $minimum && $proposal->rank() >= $best->rank() - $window,
        ));

        return array_slice($contenders, 0, $max);
    }

    public function withChoice(Proposal $chosen, float $confidence, string $decidedBy, bool $needsReview, ?JevVerdict $jev): self
    {
        return new self($this->part, $this->proposals, $chosen, $confidence, $decidedBy, $needsReview, $jev);
    }
}

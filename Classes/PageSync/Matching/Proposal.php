<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching;

/**
 * One content element type for one part, with how well it fits and where the content would go.
 */
final readonly class Proposal
{
    public function __construct(
        public string $cType,
        /** Structural fit from 0 to 1. */
        public float $score,
        public Placement $placement,
        /** Identifier of the rule that proposed it. */
        public string $rule,
        /** A rule's nudge on top of the fit — added for ranking, never shown as fit. */
        public float $preference = 0.0,
    ) {}

    /**
     * What proposals are ranked by: the fit plus the rule's preference.
     */
    public function rank(): float
    {
        return $this->score + $this->preference;
    }

    public function mapping(): FieldMapping
    {
        return $this->placement->mapping;
    }

    public function withScore(float $score, string $rule, float $preference = 0.0): self
    {
        return new self($this->cType, max(0.0, min(1.0, $score)), $this->placement, $rule, $preference);
    }
}

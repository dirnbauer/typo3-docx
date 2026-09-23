<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching;

/**
 * The outcome of filling one part into one record type: where everything went, how much of the
 * part found a field made for it (coverage), and what speaks against the type (penalty).
 */
final readonly class Placement
{
    /**
     * @param list<string> $placed Content that found a field: "heading → header"
     * @param list<string> $demoted Content that went into a less specific field: "table → bodytext"
     * @param list<string> $lost Content that has no place in this type: "2 images"
     * @param list<string> $missingRequired Required fields the part leaves empty
     */
    public function __construct(
        public FieldMapping $mapping,
        public float $coverage,
        public float $penalty,
        public array $placed = [],
        public array $demoted = [],
        public array $lost = [],
        public array $missingRequired = [],
    ) {}

    public function score(): float
    {
        return max(0.0, min(1.0, $this->coverage - $this->penalty));
    }
}

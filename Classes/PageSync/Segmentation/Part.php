<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Segmentation;

use Webconsulting\DocxEditor\PageSync\Document\Block;

/**
 * A stretch of the document that becomes one content element.
 */
final readonly class Part
{
    /**
     * @param list<Block> $blocks The blocks the part was made of, in document order
     * @param array{0: string, 1: int}|null $recordHint Table and uid named by a round-trip
     *                                                   bookmark around the part: an element whose
     *                                                   content control was removed
     */
    public function __construct(
        public string $id,
        public array $blocks,
        public PartShape $shape,
        public ?array $recordHint = null,
    ) {}
}

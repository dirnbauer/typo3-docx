<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

/**
 * A heading, level 1 to 6. Word's "Title" style reads as level 1.
 */
final readonly class Heading implements Block
{
    /**
     * @param int<1, 6> $level
     * @param list<Inline> $inlines
     */
    public function __construct(
        public int $level,
        public array $inlines,
    ) {}
}

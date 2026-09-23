<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

/**
 * One list paragraph. Word keeps lists flat with an indentation level per paragraph; HTML
 * nests them. The flat form converts both ways without loss.
 */
final readonly class ListItem
{
    /**
     * @param int<0, 8> $level
     * @param list<Inline> $inlines
     */
    public function __construct(
        public int $level,
        public bool $ordered,
        public array $inlines,
    ) {}
}

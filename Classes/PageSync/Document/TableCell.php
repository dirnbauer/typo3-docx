<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

final readonly class TableCell
{
    /**
     * @param list<Block> $blocks
     * @param positive-int $colspan
     */
    public function __construct(
        public array $blocks,
        public int $colspan = 1,
    ) {}
}

<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

final readonly class TableRow
{
    /**
     * @param list<TableCell> $cells
     */
    public function __construct(
        public array $cells,
        /** A repeated header row (w:tblHeader), or <th> cells in HTML. */
        public bool $header = false,
    ) {}
}

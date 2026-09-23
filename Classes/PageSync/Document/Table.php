<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

final readonly class Table implements Block
{
    /**
     * @param list<TableRow> $rows
     */
    public function __construct(
        public array $rows,
        public string $caption = '',
    ) {}

    public function columnCount(): int
    {
        $max = 0;
        foreach ($this->rows as $row) {
            $width = 0;
            foreach ($row->cells as $cell) {
                $width += $cell->colspan;
            }
            $max = max($max, $width);
        }

        return $max;
    }

    public function hasHeaderRow(): bool
    {
        return $this->rows !== [] && $this->rows[0]->header;
    }
}

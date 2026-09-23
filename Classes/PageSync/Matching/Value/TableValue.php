<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching\Value;

use Webconsulting\DocxEditor\PageSync\Document\Table;

/**
 * A table for the core "table" type, stored as delimited rows in bodytext.
 */
final readonly class TableValue implements FieldValue
{
    public function __construct(
        public Table $table,
    ) {}

    #[\Override]
    public function preview(): string
    {
        return count($this->table->rows) . ' × ' . $this->table->columnCount();
    }
}

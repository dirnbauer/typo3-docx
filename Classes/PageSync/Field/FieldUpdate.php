<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Field;

use Webconsulting\DocxEditor\PageSync\Document\Figure;

/**
 * What a field receives from Word: its new value (text, HTML, or pictures for a file field) and
 * settings of the record that change with it (a table's header row, a list's numbering).
 */
final readonly class FieldUpdate
{
    /**
     * @param string|list<Figure> $value
     * @param array<string, int|string> $settings
     */
    public function __construct(
        public string|array $value,
        public array $settings = [],
    ) {}
}

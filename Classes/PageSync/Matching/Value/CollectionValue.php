<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching\Value;

use Webconsulting\DocxEditor\PageSync\Matching\FieldMapping;

/**
 * The child records of a collection, one mapping per item.
 */
final readonly class CollectionValue implements FieldValue
{
    /**
     * @param list<FieldMapping> $items
     */
    public function __construct(
        public string $childTable,
        public array $items,
    ) {}

    #[\Override]
    public function preview(): string
    {
        return count($this->items) . ' × ' . $this->childTable;
    }
}

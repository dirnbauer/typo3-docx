<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

/**
 * Consecutive list paragraphs that form one list.
 */
final readonly class ListBlock implements Block
{
    /**
     * @param non-empty-list<ListItem> $items
     */
    public function __construct(
        public array $items,
    ) {}

    public function isOrdered(): bool
    {
        return $this->items[0]->ordered;
    }
}

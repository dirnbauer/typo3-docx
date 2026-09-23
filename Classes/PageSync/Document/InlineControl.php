<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

/**
 * A run-level content control (w:sdt inside a paragraph), for a short field such as a
 * collection item's title that shares its paragraph with nothing else.
 */
final readonly class InlineControl implements Inline
{
    /**
     * @param list<Inline> $children
     */
    public function __construct(
        public string $tag,
        public string $alias,
        public array $children,
        public ControlLock $lock = ControlLock::Unlocked,
        public bool $showingPlaceholder = false,
    ) {}
}

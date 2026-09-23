<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

/**
 * A block-level content control (w:sdt around paragraphs, tables or other controls). The
 * round trip tags one per record and one per editable field; Word shows the alias as the
 * control's title.
 */
final readonly class ContentControl implements Block
{
    /**
     * @param list<Block> $blocks
     * @param list<string> $bookmarks
     */
    public function __construct(
        public string $tag,
        public string $alias,
        public array $blocks,
        public ControlLock $lock = ControlLock::Unlocked,
        /** Word shows placeholder text because the author emptied the control. */
        public bool $showingPlaceholder = false,
        /** Hidden bookmarks found around or directly inside the control. */
        public array $bookmarks = [],
    ) {}
}

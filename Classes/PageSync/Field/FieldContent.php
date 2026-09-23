<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Field;

use Webconsulting\DocxEditor\PageSync\Document\Block;

/**
 * A field as it appears in Word: its blocks, whether it may be edited there, and the canonical
 * form its hash is taken from.
 */
final readonly class FieldContent
{
    /**
     * @param list<Block> $blocks
     */
    public function __construct(
        public array $blocks,
        public string $canonical,
        /** Shown read-only: Word cannot carry what the field holds (embedded pictures, links config …). */
        public bool $locked = false,
        /** Why the field is read-only, as a label key of docx_editor.pagesync. */
        public string $lockReason = '',
        /** Formatting the field loses when it is changed in Word (classes, inline styles). */
        public bool $lossy = false,
    ) {}

    public function hash(): string
    {
        return self::hashOf($this->canonical);
    }

    public static function hashOf(string $canonical): string
    {
        return hash('sha256', $canonical);
    }
}

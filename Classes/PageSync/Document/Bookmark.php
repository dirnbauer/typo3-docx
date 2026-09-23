<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

/**
 * Where a hidden round-trip bookmark ("_t3_tt_content_12") starts or ends. Content converters
 * ignore it; segmentation uses it to recognise an element whose content control was removed
 * (Word's "Remove Content Control", or a word processor that drops controls but keeps bookmarks).
 */
final readonly class Bookmark implements Block
{
    public const string PREFIX = '_t3_';

    public function __construct(
        public string $name,
        public bool $start,
    ) {}

    /**
     * @return array{0: string, 1: int}|null table and uid of a round-trip bookmark
     */
    public function record(): ?array
    {
        if (preg_match('/^_t3_([a-z][a-z0-9_]*)_([0-9]+)$/', $this->name, $matches) !== 1) {
            return null;
        }

        return [$matches[1], (int)$matches[2]];
    }

    public static function nameFor(string $table, int $uid): string
    {
        return self::PREFIX . $table . '_' . $uid;
    }
}

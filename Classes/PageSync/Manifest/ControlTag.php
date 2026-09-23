<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Manifest;

/**
 * The w:tag of a round-trip content control.
 *
 *   typo3:tt_content:12             the content element with uid 12
 *   typo3:tt_content:12:bodytext    its bodytext field
 *   typo3:accordion_items:5:title   a field of a collection item (a Content Blocks child record)
 *   typo3:pages:7:title             the page title
 *   typo3:colpos:2                  the column the elements inside belong to
 *   typo3:summary                   a read-only summary (plugins, settings, links)
 *   typo3:#14 / typo3:#14:3         a manifest reference, used where the explicit form would
 *                                   exceed the 64 characters Word allows for a tag
 */
final readonly class ControlTag
{
    public const string PREFIX = 'typo3:';
    public const int MAX_LENGTH = 64;

    private const string COLUMN = 'colpos';
    private const string SUMMARY = 'summary';

    private function __construct(
        public string $table,
        public int $uid,
        public string $field,
        public int $reference = 0,
        public int $fieldReference = 0,
    ) {}

    public static function record(string $table, int $uid): self
    {
        return new self($table, $uid, '');
    }

    public static function field(string $table, int $uid, string $field): self
    {
        return new self($table, $uid, $field);
    }

    public static function column(int $colPos): self
    {
        return new self(self::COLUMN, $colPos, '');
    }

    public static function summary(): self
    {
        return new self(self::SUMMARY, 0, '');
    }

    public static function reference(int $reference, int $fieldReference = 0): self
    {
        return new self('', 0, '', $reference, $fieldReference);
    }

    public static function parse(string $tag): ?self
    {
        $tag = trim($tag);
        if (!str_starts_with($tag, self::PREFIX)) {
            return null;
        }
        $key = substr($tag, strlen(self::PREFIX));
        if ($key === self::SUMMARY) {
            return self::summary();
        }
        if (preg_match('/^#([0-9]{1,9})(?::([0-9]{1,9}))?$/', $key, $matches) === 1) {
            return self::reference((int)$matches[1], (int)($matches[2] ?? 0));
        }
        if (preg_match('/^' . self::COLUMN . ':(-?[0-9]{1,9})$/', $key, $matches) === 1) {
            return self::column((int)$matches[1]);
        }
        if (preg_match('/^([a-z][a-z0-9_]{0,63}):([0-9]{1,10})(?::([A-Za-z][A-Za-z0-9_]{0,63}))?$/', $key, $matches) === 1) {
            $uid = (int)$matches[2];
            if ($uid <= 0) {
                return null;
            }

            return new self($matches[1], $uid, $matches[3] ?? '');
        }

        return null;
    }

    public function isRecord(): bool
    {
        return $this->reference === 0 && $this->uid > 0 && $this->field === '' && !$this->isColumn() && !$this->isSummary();
    }

    public function isField(): bool
    {
        return $this->reference === 0 && $this->field !== '';
    }

    public function isColumn(): bool
    {
        return $this->table === self::COLUMN;
    }

    public function isSummary(): bool
    {
        return $this->table === self::SUMMARY;
    }

    public function isReference(): bool
    {
        return $this->reference > 0;
    }

    public function toString(): string
    {
        return match (true) {
            $this->isReference() => self::PREFIX . '#' . $this->reference . ($this->fieldReference > 0 ? ':' . $this->fieldReference : ''),
            $this->isSummary() => self::PREFIX . self::SUMMARY,
            $this->isColumn() => self::PREFIX . self::COLUMN . ':' . $this->uid,
            default => self::PREFIX . $this->table . ':' . $this->uid . ($this->field !== '' ? ':' . $this->field : ''),
        };
    }

    public function fitsWord(): bool
    {
        return strlen($this->toString()) <= self::MAX_LENGTH;
    }

    /**
     * The same control, addressed as a record (drops the field).
     */
    public function recordTag(): self
    {
        return self::record($this->table, $this->uid);
    }
}

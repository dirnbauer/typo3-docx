<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Schema;

/**
 * One field of a record type, reduced to what the round trip needs to know.
 */
final readonly class FieldInfo
{
    /**
     * @param list<string> $allowedFileExtensions
     */
    public function __construct(
        public string $table,
        public string $name,
        /** The TCA label, usually an LLL reference. */
        public string $label,
        public FieldKind $kind,
        public FieldRole $role,
        public bool $required = false,
        /** 0 = unlimited (file and inline fields). */
        public int $maxItems = 0,
        public int $minItems = 0,
        public array $allowedFileExtensions = [],
        /** The child table of a collection. */
        public string $childTable = '',
        /** The field keeps the default language's value in translations (l10n_mode exclude). */
        public bool $excludedFromTranslation = false,
        /** Only a user with the matching non_exclude_fields permission may edit it. */
        public bool $accessControlled = false,
    ) {}

    public function acceptsImages(): bool
    {
        if ($this->kind !== FieldKind::File) {
            return false;
        }
        if ($this->allowedFileExtensions === []) {
            return true;
        }
        $allowed = array_map('strtolower', $this->allowedFileExtensions);

        return array_intersect($allowed, ['common-image-types', 'common-media-types', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']) !== [];
    }

    public function isSingle(): bool
    {
        return $this->maxItems === 1;
    }

    public function key(): string
    {
        return $this->table . '.' . $this->name;
    }
}

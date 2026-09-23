<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Manifest;

/**
 * One exported record: a content element, a collection item, or the page itself.
 */
final readonly class ManifestRecord
{
    /**
     * @param array<string, ManifestField> $fields Keyed by field name
     */
    public function __construct(
        public string $table,
        public int $uid,
        public array $fields = [],
        public string $type = '',
        public int $colPos = 0,
        /** Position among the exported elements, or among its siblings for a collection item. */
        public int $position = 0,
        /** "tt_content:13" for a collection item. */
        public string $parent = '',
        /** The inline field of the parent the item belongs to. */
        public string $parentField = '',
        public int $language = 0,
        /** Exported as a read-only summary; changes in Word are ignored. */
        public bool $locked = false,
        /**
         * A default-language element exported into a translation document because it has no
         * translation yet. Changing it in Word creates the translation.
         */
        public bool $translationSource = false,
        /** Word-shingle fingerprint of the exported text, to recognise the element without its control. */
        public string $fingerprint = '',
        public int $reference = 0,
    ) {}

    public function key(): string
    {
        return $this->table . ':' . $this->uid;
    }

    public function field(string $name): ?ManifestField
    {
        return $this->fields[$name] ?? null;
    }

    public function fieldByReference(int $reference): ?ManifestField
    {
        return array_find($this->fields, static fn(ManifestField $field): bool => $field->reference === $reference);
    }
}

<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Manifest;

/**
 * What a TYPO3 export wrote into the Word document about itself: which page, language and
 * workspace it came from, when, every exported record with the hashes of its field values, and
 * every exported picture with the file it stands for.
 */
final readonly class RoundTripManifest
{
    public const string NAMESPACE = 'urn:typo3:docx-editor:roundtrip:1';
    public const int VERSION = 1;

    /**
     * @param array<string, ManifestRecord> $records Keyed by "table:uid"
     * @param list<ManifestPicture> $pictures
     */
    public function __construct(
        public int $pageUid,
        public string $siteIdentifier,
        public int $language,
        public int $workspace,
        public \DateTimeImmutable $exportedAt,
        public array $records = [],
        /** False when the signature is missing or does not match: the base hashes cannot be trusted. */
        public bool $trusted = true,
        public int $exportedBy = 0,
        public array $pictures = [],
    ) {}

    public function record(string $table, int $uid): ?ManifestRecord
    {
        return $this->records[$table . ':' . $uid] ?? null;
    }

    public function recordByReference(int $reference): ?ManifestRecord
    {
        return array_find($this->records, static fn(ManifestRecord $record): bool => $record->reference === $reference);
    }

    /**
     * The exported picture of a file reference.
     */
    public function picture(int $reference): ?ManifestPicture
    {
        return array_find($this->pictures, static fn(ManifestPicture $picture): bool => $picture->reference === $reference);
    }

    /**
     * The exported picture whose bytes the document held.
     */
    public function pictureByEmbeddedHash(string $sha1): ?ManifestPicture
    {
        return array_find($this->pictures, static fn(ManifestPicture $picture): bool => $picture->embedded === $sha1);
    }

    /**
     * Resolves a manifest-reference tag ("typo3:#14:3") into its explicit form.
     */
    public function resolve(ControlTag $tag): ?ControlTag
    {
        if (!$tag->isReference()) {
            return $tag;
        }
        $record = $this->recordByReference($tag->reference);
        if ($record === null) {
            return null;
        }
        if ($tag->fieldReference === 0) {
            return ControlTag::record($record->table, $record->uid);
        }
        $field = $record->fieldByReference($tag->fieldReference);

        return $field === null ? null : ControlTag::field($record->table, $record->uid, $field->name);
    }

    /**
     * The content elements of the export, in exported order.
     *
     * @return list<ManifestRecord>
     */
    public function elements(): array
    {
        $elements = array_values(array_filter(
            $this->records,
            static fn(ManifestRecord $record): bool => $record->table === 'tt_content',
        ));
        usort($elements, static fn(ManifestRecord $a, ManifestRecord $b): int => $a->position <=> $b->position);

        return $elements;
    }

    /**
     * The collection items of one element, in exported order.
     *
     * @return list<ManifestRecord>
     */
    public function childrenOf(string $parentKey, string $parentField = ''): array
    {
        $children = array_values(array_filter(
            $this->records,
            static fn(ManifestRecord $record): bool => $record->parent === $parentKey
                && ($parentField === '' || $record->parentField === $parentField),
        ));
        usort($children, static fn(ManifestRecord $a, ManifestRecord $b): int => $a->position <=> $b->position);

        return $children;
    }

    public function withTrust(bool $trusted): self
    {
        return new self(
            $this->pageUid,
            $this->siteIdentifier,
            $this->language,
            $this->workspace,
            $this->exportedAt,
            $this->records,
            $trusted,
            $this->exportedBy,
            $this->pictures,
        );
    }

    /**
     * The manifest as plain data in a fixed order — what the signature is computed over, so
     * that an editor re-serialising the XML (attribute order, whitespace) does not break it.
     *
     * @return array<string, mixed>
     */
    public function toCanonicalArray(): array
    {
        $records = [];
        $keys = array_keys($this->records);
        sort($keys);
        foreach ($keys as $key) {
            $record = $this->records[$key];
            $fields = [];
            $names = array_keys($record->fields);
            sort($names);
            foreach ($names as $name) {
                $field = $record->fields[$name];
                $entry = [$field->name, $field->hash, $field->level, $field->reference];
                if ($field->value !== '') {
                    // Only when there is one, so that the signatures of older exports still match.
                    $entry[] = $field->value;
                }
                $fields[] = $entry;
            }
            $records[] = [
                $record->table,
                $record->uid,
                $record->type,
                $record->colPos,
                $record->position,
                $record->parent,
                $record->parentField,
                $record->language,
                $record->locked,
                $record->translationSource,
                $record->fingerprint,
                $record->reference,
                $fields,
            ];
        }

        $canonical = [
            'version' => self::VERSION,
            'page' => $this->pageUid,
            'site' => $this->siteIdentifier,
            'language' => $this->language,
            'workspace' => $this->workspace,
            'exported' => $this->exportedAt->format(\DateTimeInterface::ATOM),
            'exportedBy' => $this->exportedBy,
            'records' => $records,
        ];
        if ($this->pictures !== []) {
            $pictures = array_map(
                static fn(ManifestPicture $picture): array => [$picture->reference, $picture->file, $picture->sha1, $picture->embedded],
                $this->pictures,
            );
            sort($pictures);
            $canonical['pictures'] = $pictures;
        }

        return $canonical;
    }
}

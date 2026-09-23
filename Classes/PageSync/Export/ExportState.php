<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Export;

use Webconsulting\DocxEditor\PageSync\Document\Image;
use Webconsulting\DocxEditor\PageSync\Manifest\ManifestField;
use Webconsulting\DocxEditor\PageSync\Manifest\ManifestPicture;
use Webconsulting\DocxEditor\PageSync\Manifest\ManifestRecord;

/**
 * What one export collects for its manifest.
 *
 * @internal
 */
final class ExportState
{
    public int $elementCount = 0;

    /** @var array<string, ManifestRecord> */
    private array $records = [];

    /** @var array<int, ManifestPicture> by file reference uid */
    private array $pictures = [];

    /** @var array<string, int> "table:uid" => reference */
    private array $references = [];

    /** @var array<string, int> "table:uid:field" => reference */
    private array $fieldReferences = [];

    private int $nextReference = 1;

    /**
     * @param array<string, ManifestField> $fields
     */
    public function record(
        string $table,
        int $uid,
        array $fields,
        string $type = '',
        int $colPos = 0,
        int $position = 0,
        string $parent = '',
        string $parentField = '',
        bool $locked = false,
        int $language = 0,
        bool $translationSource = false,
        string $fingerprint = '',
    ): void {
        $this->records[$table . ':' . $uid] = new ManifestRecord(
            table: $table,
            uid: $uid,
            fields: $fields,
            type: $type,
            colPos: $colPos,
            position: $position,
            parent: $parent,
            parentField: $parentField,
            language: $language,
            locked: $locked,
            translationSource: $translationSource,
            fingerprint: $fingerprint,
        );
    }

    /**
     * Records an exported picture, so the import can tell it from a picture put in by the editor.
     * Reading the hash of the embedded bytes makes the picture's smaller copy.
     */
    public function picture(Image $image): void
    {
        if ($image->referenceUid > 0 && $image->fileUid > 0 && $image->fileSha1 !== '') {
            $this->pictures[$image->referenceUid] = new ManifestPicture($image->referenceUid, $image->fileUid, $image->fileSha1, $image->data->sha1());
        }
    }

    /**
     * @return list<ManifestPicture>
     */
    public function manifestPictures(): array
    {
        return array_values($this->pictures);
    }

    public function referenceFor(string $table, int $uid): int
    {
        return $this->references[$table . ':' . $uid] ??= $this->nextReference++;
    }

    public function fieldReferenceFor(string $table, int $uid, string $field): int
    {
        $this->referenceFor($table, $uid);

        return $this->fieldReferences[$table . ':' . $uid . ':' . $field] ??= count(array_filter(
            array_keys($this->fieldReferences),
            static fn(string $key): bool => str_starts_with($key, $table . ':' . $uid . ':'),
        )) + 1;
    }

    /**
     * @return array<string, ManifestRecord>
     */
    public function manifestRecords(): array
    {
        $records = [];
        foreach ($this->records as $key => $record) {
            $fields = [];
            foreach ($record->fields as $name => $field) {
                $fields[$name] = new ManifestField(
                    $field->name,
                    $field->hash,
                    $field->level,
                    $this->fieldReferences[$key . ':' . $name] ?? 0,
                    $field->value,
                );
            }
            // Collections and read-only fields have no hash, but a control whose tag may be a
            // reference: without an entry the import could not resolve it.
            foreach ($this->fieldReferences as $fieldKey => $reference) {
                $name = substr($fieldKey, strlen($key) + 1);
                if (str_starts_with($fieldKey, $key . ':') && !str_contains($name, ':') && !isset($fields[$name])) {
                    $fields[$name] = new ManifestField($name, '', 0, $reference);
                }
            }
            $records[$key] = new ManifestRecord(
                table: $record->table,
                uid: $record->uid,
                fields: $fields,
                type: $record->type,
                colPos: $record->colPos,
                position: $record->position,
                parent: $record->parent,
                parentField: $record->parentField,
                language: $record->language,
                locked: $record->locked,
                translationSource: $record->translationSource,
                fingerprint: $record->fingerprint,
                reference: $this->references[$key] ?? 0,
            );
        }

        return $records;
    }
}

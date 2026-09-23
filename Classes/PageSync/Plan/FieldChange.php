<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Plan;

use Webconsulting\DocxEditor\PageSync\Field\FieldUpdate;
use Webconsulting\DocxEditor\PageSync\Schema\FieldInfo;

/**
 * One field of one record in the plan.
 */
final readonly class FieldChange
{
    public function __construct(
        public FieldInfo $field,
        public FieldStatus $status,
        /** What Word holds, as short text for the preview. */
        public string $wordPreview,
        /** What TYPO3 holds now, as short text for the preview. */
        public string $typo3Preview,
        /** The value to write when Word wins. */
        public ?FieldUpdate $update = null,
        /** Formatting the field loses when Word's version is written (classes, styles). */
        public bool $lossy = false,
        /** Only the heading level changed (header_layout). */
        public bool $levelOnly = false,
    ) {}

    public function key(): string
    {
        return $this->field->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $label): array
    {
        return [
            'field' => $this->field->name,
            'label' => $label,
            'status' => $this->status->value,
            'word' => $this->wordPreview,
            'typo3' => $this->typo3Preview,
            'lossy' => $this->lossy,
            'levelOnly' => $this->levelOnly,
            'settings' => $this->update->settings ?? [],
        ];
    }
}

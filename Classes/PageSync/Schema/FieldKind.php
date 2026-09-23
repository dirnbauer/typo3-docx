<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Schema;

/**
 * How a field stores its value, as far as Word can edit it.
 */
enum FieldKind: string
{
    /** One line of plain text (type input). */
    case Input = 'input';
    /** Plain text with line breaks (type text without RTE). */
    case Text = 'text';
    /** HTML from the rich text editor. */
    case RichText = 'richtext';
    /** File references (type file). */
    case File = 'file';
    /** A TYPO3 link (type link) — shown, never edited in Word. */
    case Link = 'link';
    /** Child records (type inline) — a collection. */
    case Collection = 'collection';
    /** Selects, checkboxes, numbers, dates, relations, FlexForms … — not editable in Word. */
    case Other = 'other';

    public function isTextual(): bool
    {
        return $this === self::Input || $this === self::Text || $this === self::RichText;
    }
}

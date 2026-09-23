<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Plan;

/**
 * What applying the plan does to one record.
 */
enum EntryAction: string
{
    /** New content from Word becomes a new record. */
    case Create = 'create';
    /** Fields changed in Word are written. */
    case Update = 'update';
    /** Word and TYPO3 changed the same field differently; the editor decides. */
    case Conflict = 'conflict';
    /** Nothing to do. */
    case Unchanged = 'unchanged';
    /** The record was exported but is gone from the document; deleted only when confirmed. */
    case Delete = 'delete';
    /** An untranslated default-language element was edited in a translation: it is localized, then updated. */
    case Translate = 'translate';
    /** Exported read-only; changes in Word are ignored. */
    case ReadOnly = 'readOnly';
    /** Cannot be applied (no permission, no allowed type, connected translation …). */
    case Skip = 'skip';

    public function writes(): bool
    {
        return in_array($this, [self::Create, self::Update, self::Conflict, self::Delete, self::Translate], true);
    }
}

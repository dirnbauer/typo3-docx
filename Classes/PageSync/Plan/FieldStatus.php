<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Plan;

/**
 * The three-way comparison of one field: what Word holds, what TYPO3 holds now, and what the
 * export held.
 */
enum FieldStatus: string
{
    case Unchanged = 'unchanged';
    /** Changed in Word, not in TYPO3 since the export: Word's value is written. */
    case Changed = 'changed';
    /** Changed in both, differently: the editor picks one. */
    case Conflict = 'conflict';
    /** Changed in TYPO3 since the export, not in Word: TYPO3's value is kept. */
    case ChangedInTypo3 = 'changedInTypo3';
    /** Read-only in the export; whatever Word holds is ignored. */
    case ReadOnly = 'readOnly';
    /** New content (a new record's field). */
    case New = 'new';

    public function writes(): bool
    {
        return $this === self::Changed || $this === self::Conflict || $this === self::New;
    }
}

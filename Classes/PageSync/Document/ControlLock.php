<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

/**
 * The ST_Lock of a content control: whether Word lets the reader delete the control and/or
 * edit what it holds.
 */
enum ControlLock: string
{
    case Unlocked = 'unlocked';
    case SdtLocked = 'sdtLocked';
    case ContentLocked = 'contentLocked';
    case SdtContentLocked = 'sdtContentLocked';

    public static function fromOoxml(string $value): self
    {
        return self::tryFrom($value) ?? self::Unlocked;
    }

    public function forbidsEdit(): bool
    {
        return $this === self::ContentLocked || $this === self::SdtContentLocked;
    }

    public function forbidsRemoval(): bool
    {
        return $this === self::SdtLocked || $this === self::SdtContentLocked;
    }
}

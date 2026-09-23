<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Schema;

/**
 * What a field is for, read from its name and label. The matcher places a document part's
 * heading, text, pictures, items … by role, which is what makes it work for any content element
 * type, not only for the ones it knows by name.
 */
enum FieldRole: string
{
    case Heading = 'heading';
    case Subheading = 'subheading';
    case Body = 'body';
    case Quote = 'quote';
    case Attribution = 'attribution';
    case Position = 'position';
    case Value = 'value';
    case Label = 'label';
    case Code = 'code';
    case LinkLabel = 'linkLabel';
    case Link = 'link';
    case Image = 'image';
    case Media = 'media';
    /** The bodytext of the core "table" type: CSV rows. */
    case TableData = 'tableData';
    /** The bodytext of the core "bullets" type: one item per line. */
    case BulletData = 'bulletData';
    case Collection = 'collection';
    case None = 'none';

    public function isContent(): bool
    {
        return $this !== self::None && $this !== self::Link;
    }
}

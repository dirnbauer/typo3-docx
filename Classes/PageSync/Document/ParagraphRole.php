<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

/**
 * What a paragraph is for, where its Word style says more than "body text".
 */
enum ParagraphRole: string
{
    case Body = 'body';
    /** Word's "Subtitle" style — a subheader or subheadline field. */
    case Subtitle = 'subtitle';
    /** Word's "Caption" style — the caption of the picture or table before it. */
    case Caption = 'caption';
}

<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Ooxml;

/**
 * What a paragraph style means for the content, independent of how it looks.
 */
enum StyleRole
{
    case Body;
    case Heading;
    case Title;
    case Subtitle;
    case Quote;
    case QuoteSource;
    case Code;
    case Caption;
    /** Tables of contents and their headings: navigation Word generates, not content. */
    case TableOfContents;
}

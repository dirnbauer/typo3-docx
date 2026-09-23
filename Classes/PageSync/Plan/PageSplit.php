<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Plan;

/**
 * How a Word document imported as new pages is divided into pages.
 */
enum PageSplit: string
{
    /** The whole document becomes one page. */
    case None = 'none';
    /** Every heading 1 (or Title) starts a page and is its title. */
    case Heading1 = 'h1';
    /** Every page break starts a page. */
    case PageBreak = 'pagebreak';
}

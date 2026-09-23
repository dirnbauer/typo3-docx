<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

/**
 * A hyperlink. The target is kept verbatim, so a TYPO3 link (t3://page?uid=12) survives the
 * trip through Word unchanged; an in-document anchor is carried as "#name".
 */
final readonly class Link implements Inline
{
    /**
     * @param list<Inline> $children Text and line breaks — never another link
     */
    public function __construct(
        public string $href,
        public array $children,
        public string $title = '',
    ) {}
}

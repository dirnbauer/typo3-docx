<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

/**
 * A picture on a paragraph of its own, with an optional caption.
 */
final readonly class Figure implements Block
{
    /**
     * @param list<Inline> $caption
     */
    public function __construct(
        public Image $image,
        public array $caption = [],
    ) {}
}

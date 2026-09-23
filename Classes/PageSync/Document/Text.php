<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

/**
 * A run of text with one set of marks. A tab is carried as "\t".
 */
final readonly class Text implements Inline
{
    public function __construct(
        public string $text,
        public Marks $marks = new Marks(),
    ) {}
}

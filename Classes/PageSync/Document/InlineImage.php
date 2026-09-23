<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

/**
 * A picture that sits in a line of text. A paragraph that holds nothing but pictures is read
 * as figures instead.
 */
final readonly class InlineImage implements Inline
{
    public function __construct(
        public Image $image,
    ) {}
}

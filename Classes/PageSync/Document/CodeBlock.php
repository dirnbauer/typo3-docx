<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

/**
 * Preformatted code: consecutive paragraphs in a code style or set entirely in a monospaced
 * font, joined with newlines.
 */
final readonly class CodeBlock implements Block
{
    public function __construct(
        public string $code,
    ) {}
}

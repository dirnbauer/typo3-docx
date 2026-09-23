<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Ooxml;

/**
 * Something in the Word document the round trip could not carry over, reported in the plan
 * instead of disappearing silently.
 */
final readonly class ReadWarning
{
    /**
     * @param list<string|int> $arguments
     */
    public function __construct(
        /** Label key in docx_editor.pagesync, e.g. "warning.unsupportedImage". */
        public string $labelKey,
        public array $arguments = [],
    ) {}
}

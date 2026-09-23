<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Html;

/**
 * What converting a piece of RTE HTML to the document model would lose.
 */
final readonly class HtmlAnalysis
{
    /**
     * @param list<string> $unsupportedElements Content Word cannot carry (img, iframe, video …)
     * @param list<string> $droppedFormatting   Presentation that is dropped (classes, inline styles, span/div wrappers)
     */
    public function __construct(
        public array $unsupportedElements = [],
        public array $droppedFormatting = [],
    ) {}

    /**
     * Converting loses content: the field must not be edited through Word.
     */
    public function losesContent(): bool
    {
        return $this->unsupportedElements !== [];
    }

    public function losesFormatting(): bool
    {
        return $this->droppedFormatting !== [];
    }
}

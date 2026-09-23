<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

/**
 * A quotation: consecutive paragraphs in Word's "Quote" or "Intense Quote" style, or a
 * <blockquote>, with the attribution that follows it.
 */
final readonly class Quote implements Block
{
    /**
     * @param list<Paragraph> $paragraphs
     * @param list<Inline> $citation
     */
    public function __construct(
        public array $paragraphs,
        public array $citation = [],
    ) {}
}

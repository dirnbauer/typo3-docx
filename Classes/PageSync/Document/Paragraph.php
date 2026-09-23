<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

final readonly class Paragraph implements Block
{
    /**
     * @param list<Inline> $inlines
     */
    public function __construct(
        public array $inlines,
        public ParagraphRole $role = ParagraphRole::Body,
    ) {}
}

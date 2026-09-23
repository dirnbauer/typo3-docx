<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching\Value;

use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Document\PlainText;

/**
 * Structured content for a rich text field (stored as HTML) or, flattened, a textarea.
 */
final readonly class BlocksValue implements FieldValue
{
    /**
     * @param list<Block> $blocks
     */
    public function __construct(
        public array $blocks,
    ) {}

    #[\Override]
    public function preview(): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', PlainText::ofBlocks($this->blocks)));

        return mb_strlen($text) > 120 ? mb_substr($text, 0, 119) . '…' : $text;
    }
}

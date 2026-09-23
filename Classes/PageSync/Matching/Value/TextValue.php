<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching\Value;

/**
 * Plain text for an input or textarea field.
 */
final readonly class TextValue implements FieldValue
{
    public function __construct(
        public string $text,
    ) {}

    #[\Override]
    public function preview(): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', $this->text));

        return mb_strlen($text) > 80 ? mb_substr($text, 0, 79) . '…' : $text;
    }
}

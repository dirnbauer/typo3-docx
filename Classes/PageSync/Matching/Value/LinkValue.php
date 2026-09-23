<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching\Value;

/**
 * A link target for a link field (a button, a card link).
 */
final readonly class LinkValue implements FieldValue
{
    public function __construct(
        public string $href,
    ) {}

    #[\Override]
    public function preview(): string
    {
        return $this->href;
    }
}

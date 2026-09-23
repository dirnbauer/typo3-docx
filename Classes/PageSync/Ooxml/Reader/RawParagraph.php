<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Ooxml\Reader;

use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Document\Inline;
use Webconsulting\DocxEditor\PageSync\Document\InlineImage;
use Webconsulting\DocxEditor\PageSync\Document\PlainText;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Ooxml\StyleRole;

/**
 * A w:p as read, before consecutive list, quote and code paragraphs are grouped into blocks.
 *
 * @internal
 */
final readonly class RawParagraph implements Block
{
    /**
     * @param list<Inline> $inlines
     * @param array{level: int<0, 8>, ordered: bool, list: int}|null $listLevel
     */
    public function __construct(
        public StyleRole $role,
        public int $headingLevel,
        public array $inlines,
        public ?array $listLevel = null,
        public bool $rule = false,
    ) {}

    public function text(): string
    {
        return PlainText::ofInlines($this->inlines);
    }

    public function isEmpty(): bool
    {
        return trim($this->text()) === '' && $this->images() === [];
    }

    /**
     * Every run is code-formatted: the whole paragraph is set in a monospaced font.
     */
    public function isAllCode(): bool
    {
        $hasText = false;
        foreach ($this->inlines as $inline) {
            if ($inline instanceof Text) {
                if (trim($inline->text) === '') {
                    continue;
                }
                if (!$inline->marks->code) {
                    return false;
                }
                $hasText = true;
                continue;
            }

            return false;
        }

        return $hasText;
    }

    /**
     * @return list<InlineImage>
     */
    public function images(): array
    {
        return array_values(array_filter($this->inlines, static fn(Inline $inline): bool => $inline instanceof InlineImage));
    }

    /**
     * The paragraph holds pictures and nothing else but whitespace.
     */
    public function isOnlyImages(): bool
    {
        if ($this->images() === []) {
            return false;
        }
        foreach ($this->inlines as $inline) {
            if ($inline instanceof InlineImage) {
                continue;
            }
            if ($inline instanceof Text && trim($inline->text) === '') {
                continue;
            }

            return false;
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Segmentation;

use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Document\CodeBlock;
use Webconsulting\DocxEditor\PageSync\Document\Figure;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\Link;
use Webconsulting\DocxEditor\PageSync\Document\ListBlock;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\PlainText;
use Webconsulting\DocxEditor\PageSync\Document\Quote;
use Webconsulting\DocxEditor\PageSync\Document\Table;
use Webconsulting\DocxEditor\PageSync\Document\Text;

/**
 * What a part of the document consists of — the input of the content type matcher.
 */
final readonly class PartShape
{
    /**
     * @param list<Block> $body Running content: paragraphs, lists, subheadings that are not items
     * @param list<Figure> $images Pictures with their captions
     * @param list<PartItem> $items
     * @param list<Link> $links A call to action: links that stand on their own at the end of the part
     */
    public function __construct(
        public ?Heading $heading = null,
        public ?Paragraph $subtitle = null,
        public array $body = [],
        public array $images = [],
        public ?Table $table = null,
        public ?Quote $quote = null,
        public ?CodeBlock $code = null,
        public array $items = [],
        public array $links = [],
    ) {}

    public function headingText(): string
    {
        return $this->heading === null ? '' : trim(PlainText::ofBlock($this->heading));
    }

    public function bodyText(): string
    {
        return PlainText::ofBlocks($this->body);
    }

    public function bodyWordCount(): int
    {
        return PlainText::wordCount($this->bodyText());
    }

    /**
     * The body is one list and nothing else — a bullet list element fits.
     */
    public function bodyIsOnlyList(): bool
    {
        return count($this->body) === 1 && $this->body[0] instanceof ListBlock;
    }

    /**
     * The body uses more than plain paragraphs (lists, links, formatting, subheadings), so a
     * plain text field would flatten it.
     */
    public function bodyIsRich(): bool
    {
        foreach ($this->body as $block) {
            if (!$block instanceof Paragraph) {
                return true;
            }
            foreach ($block->inlines as $inline) {
                if (!$inline instanceof Text || !$inline->marks->isPlain()) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->heading === null && $this->subtitle === null && $this->body === [] && $this->images === []
            && $this->table === null && $this->quote === null && $this->code === null && $this->items === [] && $this->links === [];
    }

    /**
     * A short structural description for the plan and for Jev: "heading, 3 paragraphs (120 words), 1 image".
     */
    public function describe(): string
    {
        $parts = [];
        if ($this->heading !== null) {
            $parts[] = 'heading (level ' . $this->heading->level . ')';
        }
        if ($this->subtitle !== null) {
            $parts[] = 'subtitle';
        }
        if ($this->body !== []) {
            $paragraphs = count(array_filter($this->body, static fn(Block $block): bool => $block instanceof Paragraph));
            $lists = count(array_filter($this->body, static fn(Block $block): bool => $block instanceof ListBlock));
            $subheadings = count(array_filter($this->body, static fn(Block $block): bool => $block instanceof Heading));
            $description = [];
            if ($paragraphs > 0) {
                $description[] = $paragraphs . ' paragraph' . ($paragraphs === 1 ? '' : 's');
            }
            if ($lists > 0) {
                $description[] = $lists . ' list' . ($lists === 1 ? '' : 's');
            }
            if ($subheadings > 0) {
                $description[] = $subheadings . ' subheading' . ($subheadings === 1 ? '' : 's');
            }
            $parts[] = implode(', ', $description) . ' (' . $this->bodyWordCount() . ' words)';
        }
        if ($this->images !== []) {
            $parts[] = count($this->images) . ' image' . (count($this->images) === 1 ? '' : 's');
        }
        if ($this->table !== null) {
            $parts[] = 'table ' . count($this->table->rows) . '×' . $this->table->columnCount() . ($this->table->hasHeaderRow() ? ' with header row' : '');
        }
        if ($this->quote !== null) {
            $parts[] = 'quote' . ($this->quote->citation !== [] ? ' with attribution' : '');
        }
        if ($this->code !== null) {
            $parts[] = 'code block';
        }
        if ($this->items !== []) {
            $questions = count(array_filter($this->items, static fn(PartItem $item): bool => $item->isQuestion()));
            $figures = count(array_filter($this->items, static fn(PartItem $item): bool => $item->isFigure()));
            $withImages = count(array_filter($this->items, static fn(PartItem $item): bool => $item->image !== null));
            $parts[] = count($this->items) . ' items (title + text'
                . ($withImages > 0 ? ', ' . $withImages . ' with image' : '')
                . ($questions > 0 ? ', ' . $questions . ' titled as questions' : '')
                . ($figures > 0 ? ', ' . $figures . ' titled with figures' : '') . ')';
        }
        if ($this->links !== []) {
            $parts[] = count($this->links) . ' call-to-action link' . (count($this->links) === 1 ? '' : 's');
        }

        return implode(', ', $parts);
    }

    /**
     * The first words of the part, for the plan preview and as evidence for Jev.
     */
    public function excerpt(int $length = 400): string
    {
        $texts = [$this->headingText()];
        if ($this->subtitle !== null) {
            $texts[] = PlainText::ofBlock($this->subtitle);
        }
        $texts[] = $this->bodyText();
        foreach ($this->items as $item) {
            $texts[] = $item->titleText() . ': ' . $item->bodyText();
        }
        if ($this->quote !== null) {
            $texts[] = PlainText::ofBlock($this->quote);
        }
        if ($this->table !== null) {
            $texts[] = PlainText::ofBlock($this->table);
        }
        if ($this->code !== null) {
            $texts[] = $this->code->code;
        }
        foreach ($this->links as $link) {
            $texts[] = PlainText::ofInlines($link->children);
        }
        $text = PlainText::normalizeWhitespace(str_replace("\n", ' ', implode(' ', array_filter($texts, static fn(string $text): bool => trim($text) !== ''))));

        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)) . '…' : $text;
    }
}

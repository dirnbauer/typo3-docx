<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Html;

use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Document\CodeBlock;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\HorizontalRule;
use Webconsulting\DocxEditor\PageSync\Document\Inline;
use Webconsulting\DocxEditor\PageSync\Document\LineBreak;
use Webconsulting\DocxEditor\PageSync\Document\Link;
use Webconsulting\DocxEditor\PageSync\Document\ListBlock;
use Webconsulting\DocxEditor\PageSync\Document\ListItem;
use Webconsulting\DocxEditor\PageSync\Document\Marks;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\PlainText;
use Webconsulting\DocxEditor\PageSync\Document\Quote;
use Webconsulting\DocxEditor\PageSync\Document\Table;
use Webconsulting\DocxEditor\PageSync\Document\TableCell;
use Webconsulting\DocxEditor\PageSync\Document\TableRow;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Ooxml\Reader\InlineCollector;

/**
 * Reads RTE HTML (a bodytext as TYPO3 stores it) into the document model.
 *
 * Uses PHP's HTML5 parser, so whatever the database holds parses the way a browser would. The
 * structure survives — paragraphs, headings, lists, tables, quotes, code, links and character
 * formatting; classes, inline styles and wrapper elements do not. Elements that carry content
 * Word cannot hold (pictures, embeds, forms) are reported by analyse() and skipped here.
 */
final class HtmlToBlocks
{
    private const array TRANSPARENT = [
        'div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'address', 'center',
        'details', 'summary', 'hgroup', 'body', 'html', 'form', 'fieldset',
    ];

    private const array SKIPPED = [
        'script', 'style', 'template', 'noscript', 'head', 'title', 'meta', 'link', 'base',
    ];

    private const array UNSUPPORTED = [
        'img', 'picture', 'iframe', 'video', 'audio', 'object', 'embed', 'canvas', 'svg', 'math',
        'input', 'select', 'textarea', 'button', 'map', 'source', 'track',
    ];

    private const array BLOCK_ELEMENTS = [
        'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'table', 'blockquote', 'pre', 'hr', 'dl', 'figure',
        ...self::TRANSPARENT,
    ];

    /**
     * @return list<Block>
     */
    public function convert(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }
        $body = $this->parse($html);
        $blocks = [];
        $this->collectBlocks($body, $blocks);

        return $blocks;
    }

    public function analyse(string $html): HtmlAnalysis
    {
        if (trim($html) === '') {
            return new HtmlAnalysis();
        }
        $unsupported = [];
        $formatting = [];
        $document = $this->parse($html);
        foreach ($document->getElementsByTagName('*') as $element) {
            $name = $element->localName;
            if (in_array($name, self::UNSUPPORTED, true) || str_contains($name, '-')) {
                $unsupported[$name] = $name;
                continue;
            }
            if ($element->hasAttribute('class')) {
                $formatting['class'] = 'class';
            }
            if ($element->hasAttribute('style')) {
                $formatting['style'] = 'style';
            }
            if (in_array($name, ['span', 'font', 'mark', 'small', 'big'], true)) {
                $formatting[$name] = $name;
            }
        }

        return new HtmlAnalysis(array_values($unsupported), array_values($formatting));
    }

    private function parse(string $html): \Dom\Element
    {
        $document = \Dom\HTMLDocument::createFromString(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
            LIBXML_NOERROR,
        );
        $body = $document->body;
        if (!$body instanceof \Dom\HTMLElement) {
            return $document->createElement('body');
        }

        return $body;
    }

    /**
     * @param list<Block> $blocks
     */
    private function collectBlocks(\Dom\Node $parent, array &$blocks): void
    {
        $pending = [];
        foreach ($parent->childNodes as $node) {
            if ($node instanceof \Dom\Text) {
                $pending[] = $node;
                continue;
            }
            if (!$node instanceof \Dom\Element) {
                continue;
            }
            $name = $node->localName;
            if (in_array($name, self::SKIPPED, true) || in_array($name, self::UNSUPPORTED, true) || str_contains($name, '-')) {
                continue;
            }
            if (!in_array($name, self::BLOCK_ELEMENTS, true)) {
                $pending[] = $node;
                continue;
            }
            $this->flush($pending, $blocks);
            $pending = [];

            if ($name === 'p') {
                $this->addParagraph($this->inlines($node, Marks::none()), $blocks);
            } elseif (preg_match('/^h([1-6])$/', $name, $matches) === 1) {
                $this->addHeading((int)$matches[1], $this->inlines($node, Marks::none()), $blocks);
            } elseif ($name === 'ul' || $name === 'ol') {
                $this->addList($node, $blocks);
            } elseif ($name === 'table') {
                $blocks[] = $this->table($node);
            } elseif ($name === 'blockquote') {
                $this->addQuote($node, $blocks);
            } elseif ($name === 'pre') {
                $this->addCode($node, $blocks);
            } elseif ($name === 'hr') {
                $blocks[] = new HorizontalRule();
            } elseif ($name === 'dl') {
                $this->addDefinitionList($node, $blocks);
            } else {
                // figure and the transparent containers: their children count
                $this->collectBlocks($node, $blocks);
            }
        }
        $this->flush($pending, $blocks);
    }

    /**
     * Inline content directly inside a block container becomes a paragraph of its own.
     *
     * @param list<\Dom\Node> $nodes
     * @param list<Block> $blocks
     */
    private function flush(array $nodes, array &$blocks): void
    {
        if ($nodes === []) {
            return;
        }
        $inlines = [];
        foreach ($nodes as $node) {
            array_push($inlines, ...$this->inlineNode($node, Marks::none()));
        }
        $this->addParagraph($inlines, $blocks);
    }

    /**
     * @param list<Inline> $inlines
     * @param list<Block> $blocks
     */
    private function addParagraph(array $inlines, array &$blocks): void
    {
        $inlines = self::normalize($inlines);
        if ($inlines === [] || PlainText::trim(PlainText::ofInlines($inlines)) === '') {
            return;
        }
        $blocks[] = new Paragraph($inlines);
    }

    /**
     * @param list<Inline> $inlines
     * @param list<Block> $blocks
     */
    private function addHeading(int $level, array $inlines, array &$blocks): void
    {
        $inlines = self::normalize($inlines);
        if ($inlines === [] || trim(PlainText::ofInlines($inlines)) === '') {
            return;
        }
        $blocks[] = new Heading(max(1, min(6, $level)), $inlines);
    }

    /**
     * @param list<Block> $blocks
     */
    private function addList(\Dom\Element $list, array &$blocks): void
    {
        $items = [];
        $this->collectListItems($list, 0, $items);
        if ($items !== []) {
            $blocks[] = new ListBlock($items);
        }
    }

    /**
     * @param list<ListItem> $items
     */
    private function collectListItems(\Dom\Element $list, int $level, array &$items): void
    {
        $ordered = $list->localName === 'ol';
        foreach ($list->childNodes as $child) {
            if (!$child instanceof \Dom\Element) {
                continue;
            }
            if ($child->localName === 'ul' || $child->localName === 'ol') {
                // A nested list outside any item (invalid, but common in pasted content).
                $this->collectListItems($child, min(8, $level + 1), $items);
                continue;
            }
            if ($child->localName !== 'li') {
                continue;
            }
            $inlines = [];
            $nested = [];
            foreach ($child->childNodes as $node) {
                if ($node instanceof \Dom\Element && ($node->localName === 'ul' || $node->localName === 'ol')) {
                    $nested[] = $node;
                    continue;
                }
                if ($node instanceof \Dom\Element && in_array($node->localName, ['p', 'div'], true)) {
                    if ($inlines !== []) {
                        $inlines[] = new LineBreak();
                    }
                    array_push($inlines, ...$this->inlines($node, Marks::none()));
                    continue;
                }
                array_push($inlines, ...$this->inlineNode($node, Marks::none()));
            }
            $inlines = self::normalize($inlines);
            if ($inlines !== []) {
                $items[] = new ListItem(max(0, min(8, $level)), $ordered, $inlines);
            }
            foreach ($nested as $nestedList) {
                $this->collectListItems($nestedList, min(8, $level + 1), $items);
            }
        }
    }

    private function table(\Dom\Element $table): Table
    {
        $rows = [];
        $caption = '';
        foreach ($table->childNodes as $child) {
            if (!$child instanceof \Dom\Element) {
                continue;
            }
            if ($child->localName === 'caption') {
                $caption = trim(PlainText::normalizeWhitespace((string)$child->textContent));
                continue;
            }
            if ($child->localName === 'tr') {
                $rows[] = $this->row($child, false);
                continue;
            }
            if (in_array($child->localName, ['thead', 'tbody', 'tfoot'], true)) {
                foreach ($child->childNodes as $row) {
                    if ($row instanceof \Dom\Element && $row->localName === 'tr') {
                        $rows[] = $this->row($row, $child->localName === 'thead');
                    }
                }
            }
        }

        return new Table($rows, $caption);
    }

    private function row(\Dom\Element $row, bool $inHead): TableRow
    {
        $cells = [];
        $allHeaderCells = true;
        foreach ($row->childNodes as $cell) {
            if (!$cell instanceof \Dom\Element || ($cell->localName !== 'td' && $cell->localName !== 'th')) {
                continue;
            }
            if ($cell->localName === 'td') {
                $allHeaderCells = false;
            }
            $blocks = [];
            $this->collectBlocks($cell, $blocks);
            $colspan = max(1, min(63, (int)$cell->getAttribute('colspan')));
            $cells[] = new TableCell($blocks, $colspan);
        }

        return new TableRow($cells, $inHead || ($cells !== [] && $allHeaderCells));
    }

    /**
     * @param list<Block> $blocks
     */
    private function addQuote(\Dom\Element $quote, array &$blocks): void
    {
        $inner = [];
        $citation = [];
        foreach ($quote->childNodes as $child) {
            if ($child instanceof \Dom\Element && ($child->localName === 'footer' || $child->localName === 'cite')) {
                $citation = self::normalize($this->inlines($child, Marks::none()));
                continue;
            }
        }
        $this->collectBlocks($quote, $inner);
        $paragraphs = [];
        foreach ($inner as $block) {
            if ($block instanceof Paragraph) {
                $paragraphs[] = $block;
            } elseif ($block instanceof Heading) {
                $paragraphs[] = new Paragraph($block->inlines);
            } elseif ($block instanceof Quote) {
                array_push($paragraphs, ...$block->paragraphs);
            }
        }
        // The attribution is the last paragraph when it starts with a dash.
        if ($citation === [] && count($paragraphs) > 1) {
            $last = $paragraphs[count($paragraphs) - 1];
            $text = trim(PlainText::ofInlines($last->inlines));
            if (preg_match('/^(—|–|―|-{1,2})\s*\S/u', $text) === 1) {
                array_pop($paragraphs);
                $citation = self::stripDash($last->inlines);
            }
        } elseif ($citation !== []) {
            $citationText = PlainText::ofInlines($citation);
            $paragraphs = array_values(array_filter(
                $paragraphs,
                static fn(Paragraph $paragraph): bool => PlainText::ofInlines($paragraph->inlines) !== $citationText,
            ));
            $citation = self::stripDash($citation);
        }
        if ($paragraphs !== []) {
            $blocks[] = new Quote($paragraphs, $citation);
        }
    }

    /**
     * @param list<Block> $blocks
     */
    private function addCode(\Dom\Element $pre, array &$blocks): void
    {
        $code = str_replace(["\r\n", "\r"], "\n", (string)$pre->textContent);
        // HTML drops one newline directly after <pre>; so does the model.
        $code = rtrim(str_starts_with($code, "\n") ? substr($code, 1) : $code, "\n");
        if (trim($code) !== '') {
            $blocks[] = new CodeBlock($code);
        }
    }

    /**
     * @param list<Block> $blocks
     */
    private function addDefinitionList(\Dom\Element $list, array &$blocks): void
    {
        foreach ($list->childNodes as $child) {
            if (!$child instanceof \Dom\Element) {
                continue;
            }
            if ($child->localName === 'dt') {
                $this->addParagraph($this->inlines($child, new Marks(bold: true)), $blocks);
            } elseif ($child->localName === 'dd') {
                $this->addParagraph($this->inlines($child, Marks::none()), $blocks);
            }
        }
    }

    /**
     * @return list<Inline>
     */
    private function inlines(\Dom\Element $element, Marks $marks): array
    {
        $inlines = [];
        foreach ($element->childNodes as $child) {
            array_push($inlines, ...$this->inlineNode($child, $marks));
        }

        return $inlines;
    }

    /**
     * @return list<Inline>
     */
    private function inlineNode(\Dom\Node $node, Marks $marks): array
    {
        if ($node instanceof \Dom\Text) {
            $text = (string)preg_replace('/[\t\n\r ]+/', ' ', (string)$node->data);

            return $text === '' ? [] : [new Text($text, $marks)];
        }
        if (!$node instanceof \Dom\Element) {
            return [];
        }
        $name = $node->localName;
        if (in_array($name, self::SKIPPED, true) || in_array($name, self::UNSUPPORTED, true) || str_contains($name, '-')) {
            return [];
        }

        return match ($name) {
            'br' => [new LineBreak()],
            'strong', 'b' => $this->inlines($node, $marks->merge(new Marks(bold: true))),
            'em', 'i', 'cite', 'dfn', 'var' => $this->inlines($node, $marks->merge(new Marks(italic: true))),
            'u', 'ins' => $this->inlines($node, $marks->merge(new Marks(underline: true))),
            's', 'strike', 'del' => $this->inlines($node, $marks->merge(new Marks(strike: true))),
            'sub' => $this->inlines($node, $marks->merge(new Marks(subscript: true))),
            'sup' => $this->inlines($node, $marks->merge(new Marks(superscript: true))),
            'code', 'kbd', 'samp', 'tt' => $this->inlines($node, $marks->merge(new Marks(code: true))),
            'a' => $this->link($node, $marks),
            default => $this->inlines($node, $marks),
        };
    }

    /**
     * @return list<Inline>
     */
    private function link(\Dom\Element $anchor, Marks $marks): array
    {
        $children = array_values(array_filter(
            $this->inlines($anchor, $marks),
            static fn(Inline $inline): bool => $inline instanceof Text || $inline instanceof LineBreak,
        ));
        $href = trim((string)$anchor->getAttribute('href'));
        if ($href === '' || $children === []) {
            return $children;
        }

        return [new Link($href, $children, trim((string)$anchor->getAttribute('title')))];
    }

    /**
     * Collapses whitespace the way a browser renders it and merges runs with equal marks.
     *
     * @param list<Inline> $inlines
     *
     * @return list<Inline>
     */
    public static function normalize(array $inlines): array
    {
        $result = [];
        $previousEndsWithSpace = true;
        foreach (InlineCollector::mergeTexts($inlines) as $inline) {
            if ($inline instanceof Text) {
                $text = $inline->text;
                if ($previousEndsWithSpace) {
                    $text = ltrim($text, ' ');
                }
                if ($text === '') {
                    continue;
                }
                $previousEndsWithSpace = str_ends_with($text, ' ');
                $result[] = new Text($text, $inline->marks);
                continue;
            }
            if ($inline instanceof Link) {
                $children = [];
                foreach ($inline->children as $child) {
                    if ($child instanceof Text) {
                        $text = $previousEndsWithSpace ? ltrim($child->text, ' ') : $child->text;
                        if ($text === '') {
                            continue;
                        }
                        $previousEndsWithSpace = str_ends_with($text, ' ');
                        $children[] = new Text($text, $child->marks);
                        continue;
                    }
                    $children[] = $child;
                    $previousEndsWithSpace = true;
                }
                if ($children !== []) {
                    $result[] = new Link($inline->href, $children, $inline->title);
                }
                continue;
            }
            if ($inline instanceof LineBreak) {
                // A space before a line break is invisible.
                $last = $result === [] ? null : $result[count($result) - 1];
                if ($last instanceof Text) {
                    $trimmed = rtrim($last->text, ' ');
                    if ($trimmed === '') {
                        array_pop($result);
                    } else {
                        $result[count($result) - 1] = new Text($trimmed, $last->marks);
                    }
                }
                $previousEndsWithSpace = true;
            }
            $result[] = $inline;
        }

        // Trailing whitespace and line breaks at the end of a paragraph are not content.
        while ($result !== []) {
            $last = $result[count($result) - 1];
            if ($last instanceof LineBreak) {
                array_pop($result);
                continue;
            }
            if ($last instanceof Text) {
                $trimmed = rtrim($last->text, ' ');
                if ($trimmed === '') {
                    array_pop($result);
                    continue;
                }
                $result[count($result) - 1] = new Text($trimmed, $last->marks);
            }
            break;
        }

        return InlineCollector::mergeTexts($result);
    }

    /**
     * @param list<Inline> $inlines
     *
     * @return list<Inline>
     */
    private static function stripDash(array $inlines): array
    {
        $first = $inlines[0] ?? null;
        if ($first instanceof Text) {
            $inlines[0] = new Text((string)preg_replace('/^\s*(—|–|―|-{1,2})\s*/u', '', $first->text), $first->marks);
        }

        return self::normalize($inlines);
    }
}

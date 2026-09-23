<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Html;

use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Document\CodeBlock;
use Webconsulting\DocxEditor\PageSync\Document\ContentControl;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\HorizontalRule;
use Webconsulting\DocxEditor\PageSync\Document\Inline;
use Webconsulting\DocxEditor\PageSync\Document\InlineControl;
use Webconsulting\DocxEditor\PageSync\Document\LineBreak;
use Webconsulting\DocxEditor\PageSync\Document\Link;
use Webconsulting\DocxEditor\PageSync\Document\ListBlock;
use Webconsulting\DocxEditor\PageSync\Document\Marks;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\Quote;
use Webconsulting\DocxEditor\PageSync\Document\Table;
use Webconsulting\DocxEditor\PageSync\Document\TableCell;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Ooxml\Reader\InlineCollector;

/**
 * Writes blocks as the HTML an RTE field stores: <p>, <h2>…<h6>, <ul>/<ol>, <table>,
 * <blockquote>, <pre><code>, <hr>, <strong>/<em>/<u>/<s>/<sub>/<sup>/<code>, <a> and <br> —
 * no classes, no styles.
 *
 * The output is canonical: the same content always produces the same string, which is what the
 * change detection hashes. Pictures are not written; they belong in file fields.
 */
final class BlocksToHtml
{
    /**
     * @param list<Block> $blocks
     */
    public function convert(array $blocks): string
    {
        $html = '';
        foreach ($blocks as $block) {
            $html .= $this->block($block);
        }

        return $html;
    }

    /**
     * @param list<Inline> $inlines
     */
    public function inlines(array $inlines): string
    {
        $html = '';
        $open = [];
        foreach (InlineCollector::mergeTexts($inlines) as $inline) {
            if ($inline instanceof Text) {
                $html .= $this->transition($open, self::markList($inline->marks));
                $html .= self::escape($inline->text);
                continue;
            }
            $html .= $this->transition($open, []);
            if ($inline instanceof LineBreak) {
                $html .= '<br>';
            } elseif ($inline instanceof Link) {
                $html .= '<a href="' . self::attribute($inline->href) . '"'
                    . ($inline->title !== '' ? ' title="' . self::attribute($inline->title) . '"' : '')
                    . '>' . $this->inlines($inline->children) . '</a>';
            } elseif ($inline instanceof InlineControl) {
                $html .= $this->inlines($inline->children);
            }
        }

        return $html . $this->transition($open, []);
    }

    private function block(Block $block): string
    {
        return match (true) {
            $block instanceof Heading => '<h' . $block->level . '>' . $this->inlines($block->inlines) . '</h' . $block->level . '>',
            $block instanceof Paragraph => $this->paragraph($block),
            $block instanceof ListBlock => $this->listBlock($block),
            $block instanceof Table => $this->table($block),
            $block instanceof Quote => $this->quote($block),
            $block instanceof CodeBlock => '<pre><code>' . self::escape($block->code) . '</code></pre>',
            $block instanceof HorizontalRule => '<hr>',
            $block instanceof ContentControl => $this->convert($block->blocks),
            default => '',
        };
    }

    private function paragraph(Paragraph $paragraph): string
    {
        $inner = $this->inlines($paragraph->inlines);

        return $inner === '' ? '' : '<p>' . $inner . '</p>';
    }

    private function listBlock(ListBlock $list): string
    {
        $html = '';
        /** @var list<array{tag: string, level: int}> $stack */
        $stack = [];
        foreach ($list->items as $item) {
            $tag = $item->ordered ? 'ol' : 'ul';
            while ($stack !== [] && $stack[count($stack) - 1]['level'] > $item->level) {
                $closed = array_pop($stack);
                $html .= '</li></' . $closed['tag'] . '>';
            }
            $top = $stack === [] ? null : $stack[count($stack) - 1];
            if ($top === null || $top['level'] < $item->level) {
                // A deeper level opens inside the current item; a level skipped in the source
                // still nests only one step, as HTML can express nothing else.
                $html .= '<' . $tag . '>';
                $stack[] = ['tag' => $tag, 'level' => $item->level];
            } elseif ($top['tag'] !== $tag) {
                array_pop($stack);
                $html .= '</li></' . $top['tag'] . '><' . $tag . '>';
                $stack[] = ['tag' => $tag, 'level' => $item->level];
            } else {
                $html .= '</li>';
            }
            $html .= '<li>' . $this->inlines($item->inlines);
        }
        while ($stack !== []) {
            $closed = array_pop($stack);
            $html .= '</li></' . $closed['tag'] . '>';
        }

        return $html;
    }

    private function table(Table $table): string
    {
        $head = '';
        $body = '';
        foreach ($table->rows as $index => $row) {
            $cellTag = $row->header ? 'th' : 'td';
            $cells = '';
            foreach ($row->cells as $cell) {
                $cells .= '<' . $cellTag . ($cell->colspan > 1 ? ' colspan="' . $cell->colspan . '"' : '') . '>'
                    . $this->cell($cell) . '</' . $cellTag . '>';
            }
            if ($row->header && $index === 0) {
                $head .= '<tr>' . $cells . '</tr>';
            } else {
                $body .= '<tr>' . $cells . '</tr>';
            }
        }

        return '<table>'
            . ($table->caption !== '' ? '<caption>' . self::escape($table->caption) . '</caption>' : '')
            . ($head !== '' ? '<thead>' . $head . '</thead>' : '')
            . ($body !== '' ? '<tbody>' . $body . '</tbody>' : '')
            . '</table>';
    }

    /**
     * A cell with one paragraph holds its text directly, the way the RTE writes it.
     */
    private function cell(TableCell $cell): string
    {
        if (count($cell->blocks) === 1 && $cell->blocks[0] instanceof Paragraph) {
            return $this->inlines($cell->blocks[0]->inlines);
        }

        return $this->convert($cell->blocks);
    }

    private function quote(Quote $quote): string
    {
        $html = '';
        foreach ($quote->paragraphs as $paragraph) {
            $html .= $this->paragraph($paragraph);
        }
        if ($quote->citation !== []) {
            $html .= '<p>— ' . $this->inlines($quote->citation) . '</p>';
        }

        return '<blockquote>' . $html . '</blockquote>';
    }

    /**
     * Closes and opens formatting elements to get from the marks that are open to the wanted
     * ones, keeping the canonical nesting order.
     *
     * @param list<string> $open
     * @param list<string> $wanted
     */
    private function transition(array &$open, array $wanted): string
    {
        $common = 0;
        while ($common < count($open) && $common < count($wanted) && $open[$common] === $wanted[$common]) {
            $common++;
        }
        $html = '';
        for ($i = count($open) - 1; $i >= $common; $i--) {
            $html .= '</' . $open[$i] . '>';
        }
        for ($i = $common; $i < count($wanted); $i++) {
            $html .= '<' . $wanted[$i] . '>';
        }
        $open = $wanted;

        return $html;
    }

    /**
     * @return list<string>
     */
    private static function markList(Marks $marks): array
    {
        // Canonical nesting order of the formatting elements.
        $tags = [
            'strong' => $marks->bold,
            'em' => $marks->italic,
            'u' => $marks->underline,
            's' => $marks->strike,
            'sup' => $marks->superscript,
            'sub' => $marks->subscript,
            'code' => $marks->code,
        ];

        return array_keys(array_filter($tags));
    }

    private static function escape(string $text): string
    {
        return str_replace(["\u{00A0}", "\t"], ['&nbsp;', ' '], htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    private static function attribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

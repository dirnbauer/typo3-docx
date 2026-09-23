<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Ooxml\Reader;

use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Document\CodeBlock;
use Webconsulting\DocxEditor\PageSync\Document\Figure;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\HorizontalRule;
use Webconsulting\DocxEditor\PageSync\Document\Inline;
use Webconsulting\DocxEditor\PageSync\Document\InlineImage;
use Webconsulting\DocxEditor\PageSync\Document\ListBlock;
use Webconsulting\DocxEditor\PageSync\Document\ListItem;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\ParagraphRole;
use Webconsulting\DocxEditor\PageSync\Document\PlainText;
use Webconsulting\DocxEditor\PageSync\Document\Quote;
use Webconsulting\DocxEditor\PageSync\Document\Table;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Ooxml\StyleRole;

/**
 * Turns the paragraphs of one container (the body, a table cell, a content control) into
 * blocks: consecutive list paragraphs into one list, quote paragraphs and their attribution
 * into one quote, code paragraphs into one code block, and captions onto their picture or table.
 *
 * @internal
 */
final class BlockAssembler
{
    private const int MAX_CITATION_LENGTH = 200;

    /**
     * @param list<Block> $raw Raw paragraphs mixed with finished blocks (tables, controls, breaks)
     *
     * @return list<Block>
     */
    public static function assemble(array $raw): array
    {
        $blocks = [];
        $pendingTableCaption = '';
        $count = count($raw);
        for ($i = 0; $i < $count; $i++) {
            $item = $raw[$i];
            if (!$item instanceof RawParagraph) {
                if ($item instanceof Table && $pendingTableCaption !== '' && $item->caption === '') {
                    $item = new Table($item->rows, $pendingTableCaption);
                }
                $pendingTableCaption = '';
                $blocks[] = $item;
                continue;
            }

            if ($item->rule) {
                $blocks[] = new HorizontalRule();
                continue;
            }
            $text = trim($item->text());
            if ($item->role === StyleRole::Body && $item->images() === [] && preg_match('/^(\*{3,}|-{3,}|_{3,})$/', $text) === 1) {
                $blocks[] = new HorizontalRule();
                continue;
            }

            if ($item->role === StyleRole::Heading || $item->role === StyleRole::Title) {
                if ($text !== '') {
                    $level = max(1, min(6, $item->headingLevel));
                    $blocks[] = new Heading($level, self::withoutImages($item->inlines));
                }
                foreach ($item->images() as $image) {
                    $blocks[] = new Figure($image->image);
                }
                continue;
            }

            if ($item->listLevel !== null && !$item->isEmpty()) {
                // One list is one numbering instance: a paragraph with another numId starts the next list.
                $listId = $item->listLevel['list'];
                $items = [];
                while ($i < $count && $raw[$i] instanceof RawParagraph && $raw[$i]->listLevel !== null
                    && $raw[$i]->listLevel['list'] === $listId
                    && $raw[$i]->role !== StyleRole::Heading && $raw[$i]->role !== StyleRole::Title
                ) {
                    $current = $raw[$i];
                    if (!$current->isEmpty() && $current->listLevel !== null) {
                        $items[] = new ListItem($current->listLevel['level'], $current->listLevel['ordered'], self::withoutImages($current->inlines));
                    }
                    $i++;
                }
                $i--;
                if ($items !== []) {
                    $blocks[] = new ListBlock($items);
                }
                continue;
            }

            if ($item->role === StyleRole::Quote) {
                $paragraphs = [];
                while ($i < $count && $raw[$i] instanceof RawParagraph && $raw[$i]->role === StyleRole::Quote) {
                    if (!$raw[$i]->isEmpty()) {
                        $paragraphs[] = new Paragraph(self::withoutImages($raw[$i]->inlines));
                    }
                    $i++;
                }
                $citation = [];
                $next = $raw[$i] ?? null;
                if ($next instanceof RawParagraph && ($next->role === StyleRole::QuoteSource || self::looksLikeAttribution($next))) {
                    $citation = self::stripAttributionDash($next->inlines);
                    $i++;
                }
                $i--;
                if ($paragraphs !== []) {
                    // A dash-led last paragraph inside the quote style is the attribution too.
                    if ($citation === [] && count($paragraphs) > 1) {
                        $lastParagraph = $paragraphs[count($paragraphs) - 1];
                        $candidate = new RawParagraph(StyleRole::Body, 0, $lastParagraph->inlines);
                        if (self::looksLikeAttribution($candidate)) {
                            array_pop($paragraphs);
                            $citation = self::stripAttributionDash($lastParagraph->inlines);
                        }
                    }
                    $blocks[] = new Quote($paragraphs, $citation);
                }
                continue;
            }

            if ($item->role === StyleRole::Code || ($item->role === StyleRole::Body && $item->isAllCode())) {
                $lines = [];
                while ($i < $count && $raw[$i] instanceof RawParagraph
                    && ($raw[$i]->role === StyleRole::Code || ($raw[$i]->role === StyleRole::Body && $raw[$i]->isAllCode()))
                ) {
                    $lines[] = rtrim($raw[$i]->text(), "\r");
                    $i++;
                }
                $i--;
                $code = rtrim(implode("\n", $lines), "\n");
                if (trim($code) !== '') {
                    $blocks[] = new CodeBlock($code);
                }
                continue;
            }

            if ($item->role === StyleRole::Caption) {
                if ($text === '') {
                    continue;
                }
                $last = $blocks === [] ? null : $blocks[count($blocks) - 1];
                if ($last instanceof Figure && $last->caption === []) {
                    $blocks[count($blocks) - 1] = new Figure($last->image, self::withoutImages($item->inlines));
                    continue;
                }
                if ($last instanceof Table && $last->caption === '') {
                    $blocks[count($blocks) - 1] = new Table($last->rows, $text);
                    continue;
                }
                if (($raw[$i + 1] ?? null) instanceof Table) {
                    $pendingTableCaption = $text;
                    continue;
                }
                $blocks[] = new Paragraph(self::withoutImages($item->inlines), ParagraphRole::Caption);
                continue;
            }

            if ($item->isOnlyImages()) {
                foreach ($item->images() as $image) {
                    $blocks[] = new Figure($image->image);
                }
                continue;
            }
            if ($item->isEmpty()) {
                continue;
            }

            $role = $item->role === StyleRole::Subtitle ? ParagraphRole::Subtitle : ParagraphRole::Body;
            $blocks[] = new Paragraph(self::trimInlines($item->inlines), $role);
        }

        return $blocks;
    }

    private static function looksLikeAttribution(RawParagraph $paragraph): bool
    {
        $text = trim($paragraph->text());

        return $text !== ''
            && mb_strlen($text) <= self::MAX_CITATION_LENGTH
            && preg_match('/^(—|–|―|-{1,2})\s*\S/u', $text) === 1;
    }

    /**
     * @param list<Inline> $inlines
     *
     * @return list<Inline>
     */
    private static function stripAttributionDash(array $inlines): array
    {
        $inlines = self::withoutImages($inlines);
        foreach ($inlines as $index => $inline) {
            if (!$inline instanceof Text) {
                break;
            }
            $stripped = (string)preg_replace('/^\s*(—|–|―|-{1,2})\s*/u', '', $inline->text, 1, $replaced);
            if ($replaced > 0 || trim($inline->text) === '') {
                $inlines[$index] = new Text($stripped, $inline->marks);
                if ($replaced > 0) {
                    break;
                }
            }
        }

        return self::trimInlines(array_values(array_filter(
            $inlines,
            static fn(Inline $inline): bool => !$inline instanceof Text || $inline->text !== '',
        )));
    }

    /**
     * Inline pictures leave the text flow; segmentation collects them as the part's pictures.
     *
     * @param list<Inline> $inlines
     *
     * @return list<Inline>
     */
    private static function withoutImages(array $inlines): array
    {
        return self::trimInlines(array_values(array_filter(
            $inlines,
            static fn(Inline $inline): bool => !$inline instanceof InlineImage,
        )));
    }

    /**
     * Leading and trailing whitespace of a paragraph is layout, not content.
     *
     * @param list<Inline> $inlines
     *
     * @return list<Inline>
     */
    public static function trimInlines(array $inlines): array
    {
        $count = count($inlines);
        if ($count === 0) {
            return [];
        }
        $first = $inlines[0];
        if ($first instanceof Text) {
            $inlines[0] = new Text(ltrim($first->text, " \t\u{00A0}"), $first->marks);
        }
        $last = $inlines[$count - 1];
        if ($last instanceof Text) {
            $inlines[$count - 1] = new Text(rtrim($last->text, " \t\u{00A0}"), $last->marks);
        }

        return array_values(array_filter(
            $inlines,
            static fn(Inline $inline): bool => !$inline instanceof Text || $inline->text !== '',
        ));
    }

    /**
     * @param list<Inline> $inlines
     */
    public static function textOf(array $inlines): string
    {
        return PlainText::ofInlines($inlines);
    }
}

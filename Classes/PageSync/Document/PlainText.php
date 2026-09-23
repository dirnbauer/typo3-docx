<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

/**
 * Reads the plain text out of inlines and blocks — for headings that go into input fields,
 * for previews in the plan, and for similarity fingerprints.
 */
final class PlainText
{
    /**
     * @param list<Inline> $inlines
     */
    public static function ofInlines(array $inlines): string
    {
        $text = '';
        foreach ($inlines as $inline) {
            $text .= match (true) {
                $inline instanceof Text => $inline->text,
                $inline instanceof Link => self::ofInlines($inline->children),
                $inline instanceof LineBreak => "\n",
                $inline instanceof InlineControl => self::ofInlines($inline->children),
                default => '',
            };
        }

        return $text;
    }

    /**
     * Blocks as text, one line per paragraph, list item or table row (cells separated by tabs).
     *
     * @param list<Block> $blocks
     */
    public static function ofBlocks(array $blocks): string
    {
        $lines = [];
        foreach ($blocks as $block) {
            $lines[] = self::ofBlock($block);
        }

        return implode("\n", array_filter($lines, static fn(string $line): bool => $line !== ''));
    }

    public static function ofBlock(Block $block): string
    {
        return match (true) {
            $block instanceof Heading => self::ofInlines($block->inlines),
            $block instanceof Paragraph => self::ofInlines($block->inlines),
            $block instanceof ListBlock => implode("\n", array_map(
                static fn(ListItem $item): string => self::ofInlines($item->inlines),
                $block->items,
            )),
            $block instanceof Table => implode("\n", array_map(
                static fn(TableRow $row): string => implode("\t", array_map(
                    static fn(TableCell $cell): string => str_replace("\n", ' ', self::ofBlocks($cell->blocks)),
                    $row->cells,
                )),
                $block->rows,
            )),
            $block instanceof Figure => trim($block->image->alternative . ' ' . self::ofInlines($block->caption)),
            $block instanceof Quote => trim(self::ofBlocks($block->paragraphs) . "\n" . self::ofInlines($block->citation)),
            $block instanceof CodeBlock => $block->code,
            $block instanceof ContentControl => self::ofBlocks($block->blocks),
            default => '',
        };
    }

    /**
     * Collapses whitespace and trims, the form in which two texts count as "the same words".
     */
    public static function normalizeWhitespace(string $text): string
    {
        return trim((string)preg_replace('/[ \t\x{00A0}]+/u', ' ', $text));
    }

    public static function wordCount(string $text): int
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($words) ? count($words) : 0;
    }
}

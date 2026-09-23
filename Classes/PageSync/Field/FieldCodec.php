<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Field;

use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Document\CodeBlock;
use Webconsulting\DocxEditor\PageSync\Document\ContentControl;
use Webconsulting\DocxEditor\PageSync\Document\Figure;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\Inline;
use Webconsulting\DocxEditor\PageSync\Document\InlineImage;
use Webconsulting\DocxEditor\PageSync\Document\LineBreak;
use Webconsulting\DocxEditor\PageSync\Document\Link;
use Webconsulting\DocxEditor\PageSync\Document\ListBlock;
use Webconsulting\DocxEditor\PageSync\Document\ListItem;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\ParagraphRole;
use Webconsulting\DocxEditor\PageSync\Document\PlainText;
use Webconsulting\DocxEditor\PageSync\Document\Quote;
use Webconsulting\DocxEditor\PageSync\Document\Table;
use Webconsulting\DocxEditor\PageSync\Document\TableCell;
use Webconsulting\DocxEditor\PageSync\Document\TableRow;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Html\BlocksToHtml;
use Webconsulting\DocxEditor\PageSync\Html\HtmlToBlocks;
use Webconsulting\DocxEditor\PageSync\Schema\FieldInfo;
use Webconsulting\DocxEditor\PageSync\Schema\FieldKind;
use Webconsulting\DocxEditor\PageSync\Schema\FieldRole;

/**
 * Translates field values between the database and Word, and defines when two values are the
 * same: every field has a canonical form, and the round trip compares hashes of it.
 *
 * - Lines (input) and headings: plain text, whitespace collapsed.
 * - Textareas: one paragraph per line; blank lines collapse.
 * - Rich text: class-free HTML (see BlocksToHtml), compared after one more HTML round trip.
 * - The core table: CSV in bodytext with the record's delimiter and enclosure.
 * - The core bullet list: one item per line; the numbering follows bullets_type.
 * - Pictures: the file bytes (sha1), alt text and caption of each reference, in order.
 * - Links and everything else: shown read-only.
 */
final readonly class FieldCodec
{
    public function __construct(
        private HtmlToBlocks $htmlToBlocks,
        private BlocksToHtml $blocksToHtml,
    ) {}

    /**
     * The field as Word shows it.
     *
     * @param array<string, mixed> $record
     * @param list<Figure> $figures The pictures of a file field, read from FAL by the caller
     */
    public function export(FieldInfo $field, array $record, int $headingLevel = 2, array $figures = []): FieldContent
    {
        $value = $record[$field->name] ?? '';
        $text = is_scalar($value) ? (string)$value : '';

        return match (true) {
            $field->role === FieldRole::TableData => $this->exportTable($text, $record),
            $field->role === FieldRole::BulletData => $this->exportBullets($text, $record),
            $field->kind === FieldKind::RichText => $this->exportRichText($text),
            $field->kind === FieldKind::File => $this->exportFiles($field, $figures),
            $field->kind === FieldKind::Link => $this->exportLink($text),
            $field->kind === FieldKind::Input, $field->kind === FieldKind::Text => new FieldContent(
                $this->textBlocks($field, $text, $headingLevel),
                $this->canonicalText($field, $this->textBlocks($field, $text, $headingLevel)),
            ),
            default => new FieldContent([], '', true, 'lock.unsupportedField'),
        };
    }

    /**
     * The canonical form of what Word holds for the field.
     *
     * @param list<Block> $blocks
     * @param array<string, mixed> $record
     */
    public function canonical(FieldInfo $field, array $blocks, array $record = []): string
    {
        $blocks = self::unwrap($blocks);

        return match (true) {
            $field->role === FieldRole::TableData => self::canonicalTable(self::firstTable($blocks, $this->csvSettings($record))),
            $field->role === FieldRole::BulletData => implode("\n", self::lines($blocks)),
            $field->kind === FieldKind::RichText => $this->canonicalRichText($blocks),
            $field->kind === FieldKind::File => self::canonicalFigures(self::figures($blocks)),
            default => $this->canonicalText($field, $blocks),
        };
    }

    /**
     * The value the database receives for what Word holds.
     *
     * @param list<Block> $blocks
     * @param array<string, mixed> $record
     */
    public function update(FieldInfo $field, array $blocks, array $record = []): FieldUpdate
    {
        $blocks = self::unwrap($blocks);

        if ($field->role === FieldRole::TableData) {
            return $this->tableUpdate($blocks, $record);
        }
        if ($field->role === FieldRole::BulletData) {
            $settings = [];
            $list = array_find($blocks, static fn(Block $block): bool => $block instanceof ListBlock);
            $currentType = (int)($record['bullets_type'] ?? 0);
            if ($list instanceof ListBlock && $currentType !== 2) {
                $settings['bullets_type'] = $list->isOrdered() ? 1 : 0;
            }

            return new FieldUpdate(implode("\n", self::lines($blocks)), $settings);
        }

        return match ($field->kind) {
            FieldKind::RichText => new FieldUpdate($this->blocksToHtml->convert(self::withoutFigures($blocks))),
            FieldKind::File => new FieldUpdate(self::figures($blocks)),
            FieldKind::Input => new FieldUpdate($this->singleLine($blocks)),
            default => new FieldUpdate($field->role === FieldRole::Code ? self::codeText($blocks) : implode("\n", self::lines($blocks))),
        };
    }

    /**
     * The canonical form of a value stored in the database — how "has TYPO3 changed it since the
     * export" is decided.
     *
     * @param array<string, mixed> $record
     * @param list<Figure> $figures
     */
    public function canonicalOfRecord(FieldInfo $field, array $record, int $headingLevel = 2, array $figures = []): string
    {
        return $this->export($field, $record, $headingLevel, $figures)->canonical;
    }

    /**
     * @return list<Block>
     */
    private function textBlocks(FieldInfo $field, string $text, int $headingLevel): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (trim($text) === '') {
            return [];
        }

        return match ($field->role) {
            FieldRole::Heading => [new Heading(max(1, min(6, $headingLevel)), [new Text(self::collapse($text))])],
            FieldRole::Subheading => [new Paragraph([new Text(self::collapse($text))], ParagraphRole::Subtitle)],
            FieldRole::Code => [new CodeBlock(rtrim($text, "\n"))],
            FieldRole::Quote => [new Quote(self::paragraphs($text))],
            default => $field->kind === FieldKind::Input ? [new Paragraph([new Text(self::collapse($text))])] : self::paragraphs($text),
        };
    }

    /**
     * @param list<Block> $blocks
     */
    private function canonicalText(FieldInfo $field, array $blocks): string
    {
        $blocks = self::unwrap($blocks);
        if ($field->role === FieldRole::Code) {
            return self::codeText($blocks);
        }
        if ($field->kind === FieldKind::Input || $field->role === FieldRole::Heading || $field->role === FieldRole::Subheading) {
            return $this->singleLine($blocks);
        }

        return implode("\n", self::lines($blocks));
    }

    private function exportRichText(string $html): FieldContent
    {
        $blocks = $this->htmlToBlocks->convert($html);
        $analysis = $this->htmlToBlocks->analyse($html);

        return new FieldContent(
            $blocks,
            $this->canonicalRichText($blocks),
            $analysis->losesContent(),
            $analysis->losesContent() ? 'lock.embeddedContent' : '',
            $analysis->losesFormatting(),
        );
    }

    /**
     * @param list<Block> $blocks
     */
    private function canonicalRichText(array $blocks): string
    {
        $html = $this->blocksToHtml->convert(self::withoutFigures($blocks));

        return $this->blocksToHtml->convert($this->htmlToBlocks->convert($html));
    }

    /**
     * @param list<Figure> $figures
     */
    private function exportFiles(FieldInfo $field, array $figures): FieldContent
    {
        if (!$field->acceptsImages() || $field->role === FieldRole::Media) {
            return new FieldContent([], '', true, 'lock.files');
        }

        return new FieldContent($figures, self::canonicalFigures($figures));
    }

    private function exportLink(string $href): FieldContent
    {
        $blocks = trim($href) === '' ? [] : [new Paragraph([new Link(self::linkTarget($href), [new Text(self::linkTarget($href))])])];

        return new FieldContent($blocks, trim($href), true, 'lock.link');
    }

    /**
     * @param array<string, mixed> $record
     */
    private function exportTable(string $csv, array $record): FieldContent
    {
        $settings = $this->csvSettings($record);
        $rows = [];
        foreach (preg_split('/\r\n|\n|\r/', $csv) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = $settings['enclosure'] === ''
                ? explode($settings['delimiter'], $line)
                : str_getcsv($line, $settings['delimiter'], $settings['enclosure'], '');
            $rows[] = new TableRow(
                array_map(static fn(?string $cell): TableCell => new TableCell([new Paragraph([new Text(trim((string)$cell))])]), $cells),
                $settings['header'] === 1 && $rows === [],
            );
        }
        // The caption lives in its own field (table_caption) and travels as that field.
        $table = $rows === [] ? null : new Table($rows);

        return new FieldContent($table === null ? [] : [$table], self::canonicalTable($table));
    }

    /**
     * @param array<string, mixed> $record
     */
    private function exportBullets(string $text, array $record): FieldContent
    {
        $type = (int)($record['bullets_type'] ?? 0);
        $items = [];
        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $line) {
            if (trim($line) !== '') {
                $items[] = new ListItem(0, $type === 1, [new Text(trim($line))]);
            }
        }
        $blocks = $items === [] ? [] : [new ListBlock($items)];

        return new FieldContent(
            $blocks,
            implode("\n", self::lines($blocks)),
            $type === 2,
            $type === 2 ? 'lock.definitionList' : '',
        );
    }

    /**
     * @param list<Block> $blocks
     * @param array<string, mixed> $record
     */
    private function tableUpdate(array $blocks, array $record): FieldUpdate
    {
        $settings = $this->csvSettings($record);
        $table = self::firstTable($blocks, $settings);
        if ($table === null) {
            return new FieldUpdate('');
        }
        $rows = self::tableCells($table);
        $delimiter = $settings['delimiter'];
        $enclosure = $settings['enclosure'];
        $changes = [];
        $needsEnclosure = array_any($rows, static fn(array $cells): bool => array_any(
            $cells,
            static fn(string $cell): bool => str_contains($cell, $delimiter) || str_contains($cell, '"'),
        ));
        if ($enclosure === '' && $needsEnclosure) {
            $enclosure = '"';
            $changes['table_enclosure'] = 34;
        }
        $lines = [];
        foreach ($rows as $cells) {
            if ($enclosure === '') {
                $lines[] = implode($delimiter, $cells);
                continue;
            }
            $lines[] = implode($delimiter, array_map(
                static fn(string $cell): string => $enclosure . str_replace($enclosure, $enclosure . $enclosure, $cell) . $enclosure,
                $cells,
            ));
        }
        if ($settings['header'] !== 2) {
            $changes['table_header_position'] = $table->hasHeaderRow() ? 1 : 0;
        }

        return new FieldUpdate(implode("\n", $lines), $changes);
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array{delimiter: non-empty-string, enclosure: string, header: int}
     */
    private function csvSettings(array $record): array
    {
        $delimiter = (int)($record['table_delimiter'] ?? 124);
        $enclosure = (int)($record['table_enclosure'] ?? 0);
        return [
            'delimiter' => chr($delimiter > 0 && $delimiter < 128 ? $delimiter : 124),
            'enclosure' => $enclosure > 0 && $enclosure < 128 ? chr($enclosure) : '',
            'header' => (int)($record['table_header_position'] ?? 0),
        ];
    }

    /**
     * @param list<Block> $blocks
     * @param array{delimiter: non-empty-string, enclosure: string, header: int} $settings
     */
    private static function firstTable(array $blocks, array $settings): ?Table
    {
        foreach ($blocks as $block) {
            if ($block instanceof Table) {
                return $block;
            }
        }
        // Rows typed as paragraphs ("a | b") still make a table.
        $rows = [];
        foreach ($blocks as $block) {
            if ($block instanceof Paragraph) {
                $cells = array_map('trim', explode($settings['delimiter'], PlainText::ofBlock($block)));
                $rows[] = new TableRow(array_map(static fn(string $cell): TableCell => new TableCell([new Paragraph([new Text($cell)])]), $cells), $settings['header'] === 1 && $rows === []);
            }
        }

        return $rows === [] ? null : new Table($rows);
    }

    /**
     * @return list<list<string>>
     */
    private static function tableCells(Table $table): array
    {
        $rows = [];
        foreach ($table->rows as $row) {
            $cells = [];
            foreach ($row->cells as $cell) {
                $cells[] = self::collapse(PlainText::ofBlocks($cell->blocks));
                // A spanning cell becomes that many cells, as CSV cannot span.
                for ($i = 1; $i < $cell->colspan; $i++) {
                    $cells[] = '';
                }
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    private static function canonicalTable(?Table $table): string
    {
        if ($table === null) {
            return '';
        }

        return (string)json_encode(['header' => $table->hasHeaderRow(), 'rows' => self::tableCells($table)], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param list<Figure> $figures
     */
    private static function canonicalFigures(array $figures): string
    {
        return (string)json_encode(array_map(
            static fn(Figure $figure): array => [$figure->image->data->sha1(), trim($figure->image->alternative), trim(PlainText::ofInlines($figure->caption))],
            $figures,
        ), JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param list<Block> $blocks
     *
     * @return list<Figure>
     */
    private static function figures(array $blocks): array
    {
        $figures = [];
        foreach ($blocks as $block) {
            if ($block instanceof Figure) {
                $figures[] = $block;
            }
            if ($block instanceof Paragraph) {
                foreach ($block->inlines as $inline) {
                    if ($inline instanceof InlineImage) {
                        $figures[] = new Figure($inline->image);
                    }
                }
            }
        }

        return $figures;
    }

    /**
     * @param list<Block> $blocks
     *
     * @return list<Block>
     */
    private static function withoutFigures(array $blocks): array
    {
        return array_values(array_filter($blocks, static fn(Block $block): bool => !$block instanceof Figure));
    }

    /**
     * Content of nested controls counts as the field's content.
     *
     * @param list<Block> $blocks
     *
     * @return list<Block>
     */
    private static function unwrap(array $blocks): array
    {
        $result = [];
        foreach ($blocks as $block) {
            if ($block instanceof ContentControl) {
                array_push($result, ...self::unwrap($block->blocks));
                continue;
            }
            $result[] = $block;
        }

        return $result;
    }

    /**
     * Text lines of blocks: one per paragraph, heading, list item and table row; line breaks
     * inside a paragraph start a new line too.
     *
     * @param list<Block> $blocks
     *
     * @return list<string>
     */
    private static function lines(array $blocks): array
    {
        $lines = [];
        foreach ($blocks as $block) {
            $text = match (true) {
                $block instanceof Quote => PlainText::ofBlocks($block->paragraphs),
                $block instanceof CodeBlock => $block->code,
                default => PlainText::ofBlock($block),
            };
            foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $line) {
                $line = PlainText::normalizeWhitespace($line);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        return $lines;
    }

    /**
     * @param list<Block> $blocks
     */
    private static function codeText(array $blocks): string
    {
        $texts = [];
        foreach ($blocks as $block) {
            $texts[] = $block instanceof CodeBlock ? $block->code : PlainText::ofBlock($block);
        }
        $code = str_replace(["\r\n", "\r"], "\n", implode("\n", $texts));

        return rtrim(implode("\n", array_map('rtrim', explode("\n", $code))), "\n");
    }

    /**
     * @param list<Block> $blocks
     */
    private function singleLine(array $blocks): string
    {
        return self::collapse(implode(' ', self::lines($blocks)));
    }

    /**
     * @return list<Paragraph>
     */
    private static function paragraphs(string $text): array
    {
        $paragraphs = [];
        foreach (preg_split('/\n{2,}/', trim($text)) ?: [] as $chunk) {
            $inlines = [];
            foreach (explode("\n", $chunk) as $index => $line) {
                if ($index > 0) {
                    $inlines[] = new LineBreak();
                }
                if ($line !== '') {
                    $inlines[] = new Text($line);
                }
            }
            if ($inlines !== []) {
                $paragraphs[] = new Paragraph($inlines);
            }
        }

        return $paragraphs;
    }

    private static function collapse(string $text): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * A TYPO3 link field may hold "t3://page?uid=1 _blank - "Title"" — the target is the first part.
     */
    private static function linkTarget(string $value): string
    {
        $value = trim($value);
        $space = strpos($value, ' ');

        return $space === false ? $value : substr($value, 0, $space);
    }

    /**
     * @param list<Inline> $inlines
     */
    public static function inlineText(array $inlines): string
    {
        return self::collapse(PlainText::ofInlines($inlines));
    }
}

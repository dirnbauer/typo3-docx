<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Ooxml;

use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Document\Bookmark;
use Webconsulting\DocxEditor\PageSync\Document\ContentControl;
use Webconsulting\DocxEditor\PageSync\Document\ControlLock;
use Webconsulting\DocxEditor\PageSync\Document\DocxDocument;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\Inline;
use Webconsulting\DocxEditor\PageSync\Document\InlineControl;
use Webconsulting\DocxEditor\PageSync\Document\InlineImage;
use Webconsulting\DocxEditor\PageSync\Document\Link;
use Webconsulting\DocxEditor\PageSync\Document\Marks;
use Webconsulting\DocxEditor\PageSync\Document\PageBreak;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\Table;
use Webconsulting\DocxEditor\PageSync\Document\TableCell;
use Webconsulting\DocxEditor\PageSync\Document\TableRow;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Exception\PageSyncException;
use Webconsulting\DocxEditor\PageSync\Manifest\ManifestXml;
use Webconsulting\DocxEditor\PageSync\Ooxml\Reader\BlockAssembler;
use Webconsulting\DocxEditor\PageSync\Ooxml\Reader\BreakMarker;
use Webconsulting\DocxEditor\PageSync\Ooxml\Reader\InlineCollector;
use Webconsulting\DocxEditor\PageSync\Ooxml\Reader\RawParagraph;
use Webconsulting\DocxEditor\PageSync\Ooxml\Reader\ReadContext;

/**
 * Reads the body of a .docx into the document model.
 *
 * Kept: headings, paragraphs, subtitles, captions, bold/italic/underline/strike/sub/sup/code,
 * links (relationship, anchor and HYPERLINK fields), line and page breaks, horizontal rules,
 * bulleted and numbered lists with their levels, tables with header rows and column spans,
 * pictures with their alt text, quotes with attribution, code, text boxes, content controls
 * and the hidden round-trip bookmarks. Tracked insertions count as accepted, tracked deletions
 * as gone. Everything visual — fonts, colours, sizes, spacing, alignment — belongs to the site's
 * CSS and is dropped.
 */
final readonly class DocumentReader
{
    public function __construct(
        private ManifestXml $manifestXml,
    ) {}

    public function read(string $binary, ArchiveLimits $limits = new ArchiveLimits()): DocxDocument
    {
        return $this->readArchive(DocxArchive::fromBinary($binary, $limits));
    }

    public function readArchive(DocxArchive $archive): DocxDocument
    {
        $mainPart = $archive->mainDocumentPart();
        $relationships = $archive->relationships($mainPart);
        $styles = StyleCatalog::empty();
        $numbering = NumberingCatalog::empty();
        foreach ($relationships as $relationship) {
            if ($relationship->external || !$archive->has($relationship->target)) {
                continue;
            }
            if ($relationship->type === Ns::REL_STYLES) {
                $styles = StyleCatalog::fromXml($archive->xml($relationship->target));
            } elseif ($relationship->type === Ns::REL_NUMBERING) {
                $numbering = NumberingCatalog::fromXml($archive->xml($relationship->target));
            }
        }

        $context = new ReadContext($archive, $relationships, $styles, $numbering);
        $document = $archive->xml($mainPart);
        $body = null;
        foreach ($document->getElementsByTagNameNS(Ns::W, 'body') as $candidate) {
            $body = $candidate;
            break;
        }
        if ($body === null) {
            throw new PageSyncException('error.notADocx', 415);
        }

        $blocks = $this->readContainer($body, $context);

        return new DocxDocument(
            blocks: $blocks,
            manifest: $this->manifestXml->find($archive, $mainPart),
            customProperties: $this->readCustomProperties($archive),
            title: $this->readTitle($archive),
            warnings: $context->warnings,
        );
    }

    /**
     * @return list<Block>
     */
    private function readContainer(\DOMElement $container, ReadContext $context): array
    {
        $raw = [];
        foreach (SafeXml::children($container) as $child) {
            if ($child->namespaceURI === Ns::MC && $child->localName === 'AlternateContent') {
                $branch = self::alternateContentBranch($child);
                if ($branch !== null) {
                    array_push($raw, ...$this->readContainer($branch, $context));
                }
                continue;
            }
            if ($child->namespaceURI !== Ns::W) {
                continue;
            }
            switch ($child->localName) {
                case 'p':
                    array_push($raw, ...$this->readParagraph($child, $context));
                    break;
                case 'tbl':
                    $raw[] = $this->readTable($child, $context);
                    break;
                case 'sdt':
                    array_push($raw, ...$this->readBlockControl($child, $context));
                    break;
                case 'customXml':
                case 'ins':
                case 'moveTo':
                case 'smartTag':
                    array_push($raw, ...$this->readContainer($child, $context));
                    break;
                case 'bookmarkStart':
                    $name = $child->getAttributeNS(Ns::W, 'name');
                    $context->bookmarkNames[$child->getAttributeNS(Ns::W, 'id')] = $name;
                    if (str_starts_with($name, Bookmark::PREFIX)) {
                        $raw[] = new Bookmark($name, true);
                    }
                    break;
                case 'bookmarkEnd':
                    $name = $context->bookmarkNames[$child->getAttributeNS(Ns::W, 'id')] ?? '';
                    if (str_starts_with($name, Bookmark::PREFIX)) {
                        $raw[] = new Bookmark($name, false);
                    }
                    break;
                case 'altChunk':
                    $context->warn('warning.altChunk');
                    break;
                default:
                    // sectPr, del, moveFrom, proofErr, permStart … carry no content
                    break;
            }
        }

        return BlockAssembler::assemble($raw);
    }

    /**
     * @return list<Block>
     */
    private function readParagraph(\DOMElement $paragraph, ReadContext $context): array
    {
        $properties = SafeXml::firstChild($paragraph, Ns::W, 'pPr');
        $styleId = SafeXml::wordValue($properties === null ? null : SafeXml::firstChild($properties, Ns::W, 'pStyle'))
            ?? $context->styles->defaultParagraphStyle();
        [$role, $level] = $context->styles->paragraphRole($styleId);
        if ($role === StyleRole::TableOfContents) {
            return [];
        }

        if ($role === StyleRole::Body && $properties !== null) {
            $outline = SafeXml::wordValue(SafeXml::firstChild($properties, Ns::W, 'outlineLvl'));
            if ($outline !== null && is_numeric($outline) && (int)$outline >= 0 && (int)$outline <= 5) {
                $role = StyleRole::Heading;
                $level = (int)$outline + 1;
            }
        }

        $listLevel = $this->listLevel($properties, $styleId, $context);

        $collector = new InlineCollector();
        $this->collectInlines($paragraph, $context, $collector);
        $inlines = $collector->inlines();

        // Word moves bookmarks into the paragraph they touch; a round-trip bookmark starting or
        // ending inside a paragraph marks the boundary before or after it.
        $items = array_map(static fn(string $name): Bookmark => new Bookmark($name, true), $context->paragraphBookmarkStarts);
        $bookmarkEnds = array_map(static fn(string $name): Bookmark => new Bookmark($name, false), $context->paragraphBookmarkEnds);
        $context->paragraphBookmarkStarts = [];
        $context->paragraphBookmarkEnds = [];

        if ($properties !== null && SafeXml::isOn(SafeXml::firstChild($properties, Ns::W, 'pageBreakBefore'))) {
            $items[] = new PageBreak();
        }

        $segment = [];
        $segments = [];
        $separators = [];
        foreach ($inlines as $inline) {
            if ($inline instanceof BreakMarker) {
                $segments[] = $segment;
                $separators[] = $inline;
                $segment = [];
                continue;
            }
            $segment[] = $inline;
        }
        $segments[] = $segment;

        $hasBorder = $properties !== null && self::hasBottomBorder($properties);
        foreach ($segments as $index => $segmentInlines) {
            if ($index > 0) {
                $separator = $separators[$index - 1];
                $items[] = $separator->rule ? new RawParagraph(StyleRole::Body, 0, [], null, true) : new PageBreak();
            }
            // Untrimmed: indentation is content in a code paragraph; the assembler trims the rest.
            $raw = new RawParagraph($role, $level, $segmentInlines, $listLevel);
            if ($hasBorder && $raw->isEmpty()) {
                $raw = new RawParagraph(StyleRole::Body, 0, [], null, true);
            }
            $items[] = $raw;
        }

        array_push($items, ...$context->takeFloating());
        array_push($items, ...$bookmarkEnds);

        if ($properties !== null && SafeXml::firstChild($properties, Ns::W, 'sectPr') !== null) {
            $items[] = new PageBreak();
        }

        return $items;
    }

    /**
     * @return array{level: int<0, 8>, ordered: bool, list: int}|null
     */
    private function listLevel(?\DOMElement $properties, string $styleId, ReadContext $context): ?array
    {
        $numId = null;
        $level = 0;
        $numPr = $properties === null ? null : SafeXml::firstChild($properties, Ns::W, 'numPr');
        if ($numPr !== null) {
            $numIdValue = SafeXml::wordValue(SafeXml::firstChild($numPr, Ns::W, 'numId'));
            $levelValue = SafeXml::wordValue(SafeXml::firstChild($numPr, Ns::W, 'ilvl'));
            if ($numIdValue !== null && is_numeric($numIdValue)) {
                $numId = (int)$numIdValue;
            }
            if ($levelValue !== null && is_numeric($levelValue)) {
                $level = (int)$levelValue;
            }
        }
        if ($numId === null) {
            $fromStyle = $context->styles->styleNumbering($styleId);
            if ($fromStyle !== null) {
                [$numId, $styleLevel] = $fromStyle;
                $level = $numPr === null ? $styleLevel : $level;
            }
        }
        if ($numId === null || $numId === 0) {
            return null;
        }
        $level = max(0, min(8, $level));

        return [
            'level' => $level,
            'ordered' => $context->numbering->isKnown($numId) && $context->numbering->isOrdered($numId, $level),
            'list' => $numId,
        ];
    }

    private function collectInlines(\DOMElement $parent, ReadContext $context, InlineCollector $out): void
    {
        foreach (SafeXml::children($parent) as $child) {
            if ($child->namespaceURI === Ns::MC && $child->localName === 'AlternateContent') {
                $branch = self::alternateContentBranch($child);
                if ($branch !== null) {
                    $this->collectInlines($branch, $context, $out);
                }
                continue;
            }
            if ($child->namespaceURI === Ns::M && ($child->localName === 'oMath' || $child->localName === 'oMathPara')) {
                $math = '';
                foreach ($child->getElementsByTagNameNS(Ns::M, 't') as $mathText) {
                    $math .= $mathText->textContent;
                }
                $out->text($math, Marks::none());
                continue;
            }
            if ($child->namespaceURI !== Ns::W) {
                continue;
            }
            switch ($child->localName) {
                case 'r':
                    $this->collectRun($child, $context, $out);
                    break;
                case 'hyperlink':
                    $this->collectHyperlink($child, $context, $out);
                    break;
                case 'sdt':
                    $out->add($this->readInlineControl($child, $context));
                    break;
                case 'fldSimple':
                    $inner = new InlineCollector();
                    $this->collectInlines($child, $context, $inner);
                    $out->simpleField($child->getAttributeNS(Ns::W, 'instr'), $inner->inlines());
                    break;
                case 'ins':
                case 'moveTo':
                case 'smartTag':
                case 'customXml':
                case 'dir':
                case 'bdo':
                    $this->collectInlines($child, $context, $out);
                    break;
                case 'bookmarkStart':
                    $name = $child->getAttributeNS(Ns::W, 'name');
                    $context->bookmarkNames[$child->getAttributeNS(Ns::W, 'id')] = $name;
                    if (str_starts_with($name, Bookmark::PREFIX)) {
                        $context->paragraphBookmarkStarts[] = $name;
                    }
                    break;
                case 'bookmarkEnd':
                    $name = $context->bookmarkNames[$child->getAttributeNS(Ns::W, 'id')] ?? '';
                    if (str_starts_with($name, Bookmark::PREFIX)) {
                        $context->paragraphBookmarkEnds[] = $name;
                    }
                    break;
                default:
                    // pPr, del, moveFrom, proofErr, comment ranges, permissions
                    break;
            }
        }
    }

    private function collectRun(\DOMElement $run, ReadContext $context, InlineCollector $out): void
    {
        $marks = $this->marksOf(SafeXml::firstChild($run, Ns::W, 'rPr'), $context);
        foreach (SafeXml::children($run) as $child) {
            if ($child->namespaceURI === Ns::MC && $child->localName === 'AlternateContent') {
                $branch = self::alternateContentBranch($child);
                if ($branch !== null) {
                    $this->collectRun($branch, $context, $out);
                }
                continue;
            }
            if ($child->namespaceURI !== Ns::W) {
                continue;
            }
            switch ($child->localName) {
                case 't':
                    $text = $child->textContent;
                    // Without xml:space="preserve", Word ignores the whitespace around a text node.
                    if ($child->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'space') !== 'preserve') {
                        $text = trim($text, " \t\n\r");
                    }
                    $out->text($text, $marks);
                    break;
                case 'instrText':
                    $out->instruction($child->textContent);
                    break;
                case 'tab':
                case 'ptab':
                    $out->text("\t", $marks);
                    break;
                case 'br':
                    $type = $child->getAttributeNS(Ns::W, 'type');
                    if ($type === 'page') {
                        $out->pageBreak();
                    } elseif ($type !== 'column') {
                        $out->lineBreak();
                    }
                    break;
                case 'cr':
                    $out->lineBreak();
                    break;
                case 'noBreakHyphen':
                    $out->text('-', $marks);
                    break;
                case 'sym':
                    $code = hexdec($child->getAttributeNS(Ns::W, 'char'));
                    // Symbol-font glyphs live in the private use area and mean nothing without the font.
                    if (is_int($code) && $code > 31 && ($code < 0xE000 || $code > 0xF8FF)) {
                        $out->text(mb_chr($code, 'UTF-8'), $marks);
                    }
                    break;
                case 'fldChar':
                    match ($child->getAttributeNS(Ns::W, 'fldCharType')) {
                        'begin' => $out->beginField(),
                        'separate' => $out->separateField(),
                        'end' => $out->endField(),
                        default => null,
                    };
                    break;
                case 'drawing':
                    $this->collectDrawing($child, $context, $out);
                    break;
                case 'pict':
                    $this->collectPicture($child, $context, $out);
                    break;
                default:
                    // delText, lastRenderedPageBreak, softHyphen, footnote references, objects
                    break;
            }
        }
    }

    private function collectHyperlink(\DOMElement $hyperlink, ReadContext $context, InlineCollector $out): void
    {
        $inner = new InlineCollector();
        $this->collectInlines($hyperlink, $context, $inner);
        $children = $inner->inlines();

        $href = '';
        $relationshipId = $hyperlink->getAttributeNS(Ns::R, 'id');
        if ($relationshipId !== '') {
            $href = $context->hyperlinkTarget($relationshipId);
        }
        $anchor = $hyperlink->getAttributeNS(Ns::W, 'anchor');
        if ($anchor !== '') {
            $href .= '#' . $anchor;
        }
        if ($href === '') {
            $out->addAll($children);

            return;
        }

        $linkChildren = [];
        foreach ($children as $child) {
            if ($child instanceof InlineImage || $child instanceof BreakMarker) {
                // A linked picture or a page break inside a link leaves the link.
                $out->add($child);
                continue;
            }
            $linkChildren[] = $child;
        }
        if ($linkChildren !== []) {
            $out->add(new Link($href, $linkChildren, $hyperlink->getAttributeNS(Ns::W, 'tooltip')));
        }
    }

    private function collectDrawing(\DOMElement $drawing, ReadContext $context, InlineCollector $out): void
    {
        $docPr = null;
        foreach ($drawing->getElementsByTagNameNS(Ns::WP, 'docPr') as $candidate) {
            $docPr = $candidate;
            break;
        }
        $extent = null;
        foreach ($drawing->getElementsByTagNameNS(Ns::WP, 'extent') as $candidate) {
            $extent = $candidate;
            break;
        }
        $alternative = $docPr?->getAttribute('descr') ?? '';
        $title = $docPr?->getAttribute('title') ?? '';
        $width = (int)($extent?->getAttribute('cx') ?? 0);
        $height = (int)($extent?->getAttribute('cy') ?? 0);

        $sawPicture = false;
        foreach ($drawing->getElementsByTagNameNS(Ns::A, 'blip') as $blip) {
            $sawPicture = true;
            $relationshipId = $blip->getAttributeNS(Ns::R, 'embed');
            if ($relationshipId === '') {
                if ($blip->getAttributeNS(Ns::R, 'link') !== '') {
                    $context->warn('warning.linkedImage', $blip->getAttributeNS(Ns::R, 'link'));
                }
                continue;
            }
            $image = $context->image($relationshipId, $alternative, $title, $width, $height);
            if ($image !== null) {
                $out->add(new InlineImage($image));
            }
        }

        $textBoxes = $drawing->getElementsByTagNameNS(Ns::W, 'txbxContent');
        foreach ($textBoxes as $textBox) {
            $context->addFloating($this->readContainer($textBox, $context));
        }

        // Charts, SmartArt and shapes without a picture or text cannot be carried over.
        if (!$sawPicture && $textBoxes->length === 0) {
            $context->warn('warning.unsupportedDrawing');
        }
    }

    private function collectPicture(\DOMElement $picture, ReadContext $context, InlineCollector $out): void
    {
        foreach ($picture->getElementsByTagNameNS(Ns::V, 'rect') as $rectangle) {
            if ($rectangle->getAttributeNS(Ns::O, 'hr') === 't') {
                $out->pageBreak(rule: true);

                return;
            }
        }
        foreach ($picture->getElementsByTagNameNS(Ns::V, 'imagedata') as $imageData) {
            $relationshipId = $imageData->getAttributeNS(Ns::R, 'id');
            if ($relationshipId === '') {
                continue;
            }
            $shape = $imageData->parentNode instanceof \DOMElement ? $imageData->parentNode : null;
            $image = $context->image($relationshipId, $shape?->getAttribute('alt') ?? '', $imageData->getAttributeNS(Ns::O, 'title'), 0, 0);
            if ($image !== null) {
                $out->add(new InlineImage($image));
            }
        }
        foreach ($picture->getElementsByTagNameNS(Ns::W, 'txbxContent') as $textBox) {
            $context->addFloating($this->readContainer($textBox, $context));
        }
    }

    private function marksOf(?\DOMElement $properties, ReadContext $context): Marks
    {
        if ($properties === null) {
            return Marks::none();
        }
        $underline = SafeXml::wordValue(SafeXml::firstChild($properties, Ns::W, 'u'));
        $verticalAlign = SafeXml::wordValue(SafeXml::firstChild($properties, Ns::W, 'vertAlign')) ?? '';
        $styleId = SafeXml::wordValue(SafeXml::firstChild($properties, Ns::W, 'rStyle')) ?? '';
        $fromStyle = $styleId === '' ? ['bold' => false, 'italic' => false, 'code' => false] : $context->styles->characterMarks($styleId);
        $boldElement = SafeXml::firstChild($properties, Ns::W, 'b');
        $italicElement = SafeXml::firstChild($properties, Ns::W, 'i');

        return new Marks(
            bold: $boldElement !== null ? SafeXml::isOn($boldElement) : $fromStyle['bold'],
            italic: $italicElement !== null ? SafeXml::isOn($italicElement) : $fromStyle['italic'],
            underline: $underline !== null && $underline !== 'none' && strtolower($styleId) !== 'hyperlink',
            strike: SafeXml::isOn(SafeXml::firstChild($properties, Ns::W, 'strike')) || SafeXml::isOn(SafeXml::firstChild($properties, Ns::W, 'dstrike')),
            subscript: $verticalAlign === 'subscript',
            superscript: $verticalAlign === 'superscript',
            code: $fromStyle['code'] || StyleCatalog::isMonospaceRunProperties($properties),
        );
    }

    private function readTable(\DOMElement $table, ReadContext $context): Table
    {
        $rows = [];
        foreach ($this->tableRows($table) as $rowElement) {
            $properties = SafeXml::firstChild($rowElement, Ns::W, 'trPr');
            $header = $properties !== null && SafeXml::isOn(SafeXml::firstChild($properties, Ns::W, 'tblHeader'));
            $cells = [];
            foreach ($this->rowCells($rowElement) as $cellElement) {
                $cellProperties = SafeXml::firstChild($cellElement, Ns::W, 'tcPr');
                $span = 1;
                $continuation = false;
                if ($cellProperties !== null) {
                    $spanValue = SafeXml::wordValue(SafeXml::firstChild($cellProperties, Ns::W, 'gridSpan'));
                    $span = $spanValue !== null && is_numeric($spanValue) ? max(1, (int)$spanValue) : 1;
                    $merge = SafeXml::firstChild($cellProperties, Ns::W, 'vMerge');
                    $continuation = $merge !== null && (SafeXml::wordValue($merge) ?? 'continue') === 'continue';
                }
                $cells[] = new TableCell($continuation ? [] : $this->readContainer($cellElement, $context), $span);
            }
            $rows[] = new TableRow($cells, $header);
        }

        if ($rows !== [] && !$rows[0]->header && $this->firstRowIsHeader($table, $rows[0])) {
            $rows[0] = new TableRow($rows[0]->cells, true);
        }

        return new Table($rows);
    }

    /**
     * @return list<\DOMElement>
     */
    private function tableRows(\DOMElement $container): array
    {
        $rows = [];
        foreach (SafeXml::children($container) as $child) {
            if ($child->namespaceURI !== Ns::W) {
                continue;
            }
            if ($child->localName === 'tr') {
                $rows[] = $child;
            } elseif (in_array($child->localName, ['sdt', 'sdtContent', 'customXml', 'ins', 'moveTo'], true)) {
                array_push($rows, ...$this->tableRows($child));
            }
        }

        return $rows;
    }

    /**
     * @return list<\DOMElement>
     */
    private function rowCells(\DOMElement $container): array
    {
        $cells = [];
        foreach (SafeXml::children($container) as $child) {
            if ($child->namespaceURI !== Ns::W) {
                continue;
            }
            if ($child->localName === 'tc') {
                $cells[] = $child;
            } elseif (in_array($child->localName, ['sdt', 'sdtContent', 'customXml', 'ins', 'moveTo'], true)) {
                array_push($cells, ...$this->rowCells($child));
            }
        }

        return $cells;
    }

    /**
     * A first row counts as header when the table style emphasises it (Word's "Header Row"
     * option on a styled table) or when every cell of it is bold.
     */
    private function firstRowIsHeader(\DOMElement $table, TableRow $row): bool
    {
        $properties = SafeXml::firstChild($table, Ns::W, 'tblPr');
        if ($properties !== null) {
            $style = strtolower(SafeXml::wordValue(SafeXml::firstChild($properties, Ns::W, 'tblStyle')) ?? '');
            $look = SafeXml::firstChild($properties, Ns::W, 'tblLook');
            $firstRow = $look !== null && (
                in_array(strtolower($look->getAttributeNS(Ns::W, 'firstRow')), ['1', 'true', 'on'], true)
                || (hexdec($look->getAttributeNS(Ns::W, 'val') ?: '0') & 0x0020) === 0x0020
            );
            if ($firstRow && $style !== '' && !in_array($style, ['tablegrid', 'tablenormal', 'table grid', 'normaltable'], true)) {
                return true;
            }
        }

        $sawText = false;
        foreach ($row->cells as $cell) {
            foreach ($cell->blocks as $block) {
                foreach (self::inlinesOfBlock($block) as $inline) {
                    if ($inline instanceof Text && trim($inline->text) !== '') {
                        if (!$inline->marks->bold) {
                            return false;
                        }
                        $sawText = true;
                    }
                }
            }
        }

        return $sawText;
    }

    /**
     * @return list<Inline>
     */
    private static function inlinesOfBlock(Block $block): array
    {
        if ($block instanceof Paragraph || $block instanceof Heading) {
            $inlines = [];
            foreach ($block->inlines as $inline) {
                if ($inline instanceof Link) {
                    array_push($inlines, ...$inline->children);
                    continue;
                }
                $inlines[] = $inline;
            }

            return $inlines;
        }

        return [];
    }

    /**
     * @return list<Block>
     */
    private function readBlockControl(\DOMElement $control, ReadContext $context): array
    {
        $properties = SafeXml::firstChild($control, Ns::W, 'sdtPr');
        $content = SafeXml::firstChild($control, Ns::W, 'sdtContent');
        if ($properties !== null) {
            $docPart = SafeXml::firstChild($properties, Ns::W, 'docPartObj');
            $gallery = $docPart === null ? null : SafeXml::wordValue(SafeXml::firstChild($docPart, Ns::W, 'docPartGallery'));
            if ($gallery !== null && stripos($gallery, 'table of contents') !== false) {
                return [];
            }
        }

        $blocks = $content === null ? [] : $this->readContainer($content, $context);
        $tag = SafeXml::wordValue($properties === null ? null : SafeXml::firstChild($properties, Ns::W, 'tag')) ?? '';
        if (!str_starts_with($tag, 'typo3:')) {
            // Controls of a company template or a form: their content is ordinary content.
            return $blocks;
        }

        $placeholder = $properties !== null && SafeXml::firstChild($properties, Ns::W, 'showingPlcHdr') !== null
            && SafeXml::isOn(SafeXml::firstChild($properties, Ns::W, 'showingPlcHdr'));
        $bookmarks = [];
        foreach ($blocks as $block) {
            if ($block instanceof Bookmark && $block->start) {
                $bookmarks[] = $block->name;
            }
        }

        return [new ContentControl(
            tag: $tag,
            alias: SafeXml::wordValue($properties === null ? null : SafeXml::firstChild($properties, Ns::W, 'alias')) ?? '',
            blocks: $placeholder ? [] : $blocks,
            lock: ControlLock::fromOoxml(SafeXml::wordValue($properties === null ? null : SafeXml::firstChild($properties, Ns::W, 'lock')) ?? ''),
            showingPlaceholder: $placeholder,
            bookmarks: $bookmarks,
        )];
    }

    private function readInlineControl(\DOMElement $control, ReadContext $context): Inline
    {
        $properties = SafeXml::firstChild($control, Ns::W, 'sdtPr');
        $content = SafeXml::firstChild($control, Ns::W, 'sdtContent');
        $inner = new InlineCollector();
        if ($content !== null) {
            $this->collectInlines($content, $context, $inner);
        }
        $children = $inner->inlines();
        $placeholder = $properties !== null && SafeXml::isOn(SafeXml::firstChild($properties, Ns::W, 'showingPlcHdr'))
            && SafeXml::firstChild($properties, Ns::W, 'showingPlcHdr') !== null;

        return new InlineControl(
            tag: SafeXml::wordValue($properties === null ? null : SafeXml::firstChild($properties, Ns::W, 'tag')) ?? '',
            alias: SafeXml::wordValue($properties === null ? null : SafeXml::firstChild($properties, Ns::W, 'alias')) ?? '',
            children: $placeholder ? [] : $children,
            lock: ControlLock::fromOoxml(SafeXml::wordValue($properties === null ? null : SafeXml::firstChild($properties, Ns::W, 'lock')) ?? ''),
            showingPlaceholder: $placeholder,
        );
    }

    private static function hasBottomBorder(\DOMElement $properties): bool
    {
        $borders = SafeXml::firstChild($properties, Ns::W, 'pBdr');
        if ($borders === null) {
            return false;
        }
        $bottom = SafeXml::firstChild($borders, Ns::W, 'bottom');
        $value = SafeXml::wordValue($bottom);

        return $bottom !== null && $value !== null && !in_array($value, ['nil', 'none'], true);
    }

    /**
     * Word writes newer constructs twice: a Choice for itself and a Fallback for older readers.
     * The fallback (VML pictures, plain text boxes) is what this reader understands everywhere.
     */
    private static function alternateContentBranch(\DOMElement $alternateContent): ?\DOMElement
    {
        $fallback = SafeXml::firstChild($alternateContent, Ns::MC, 'Fallback');
        if ($fallback !== null) {
            return $fallback;
        }

        return SafeXml::firstChild($alternateContent, Ns::MC, 'Choice');
    }

    /**
     * @return array<string, string>
     */
    private function readCustomProperties(DocxArchive $archive): array
    {
        $relationship = $archive->firstRelationshipOfType('', Ns::REL_CUSTOM_PROPERTIES);
        $part = $relationship->target ?? 'docProps/custom.xml';
        if (!$archive->has($part)) {
            return [];
        }
        $properties = [];
        foreach ($archive->xml($part)->getElementsByTagNameNS(Ns::CUSTOM_PROPERTIES, 'property') as $property) {
            $name = $property->getAttribute('name');
            if ($name !== '') {
                $properties[$name] = trim($property->textContent);
            }
        }

        return $properties;
    }

    private function readTitle(DocxArchive $archive): string
    {
        $relationship = $archive->firstRelationshipOfType('', Ns::REL_CORE_PROPERTIES);
        $part = $relationship->target ?? 'docProps/core.xml';
        if (!$archive->has($part)) {
            return '';
        }
        foreach ($archive->xml($part)->getElementsByTagNameNS(Ns::DC, 'title') as $title) {
            return trim($title->textContent);
        }

        return '';
    }
}

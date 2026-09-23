<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Ooxml;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Document\Bookmark;
use Webconsulting\DocxEditor\PageSync\Document\CodeBlock;
use Webconsulting\DocxEditor\PageSync\Document\ContentControl;
use Webconsulting\DocxEditor\PageSync\Document\ControlLock;
use Webconsulting\DocxEditor\PageSync\Document\Figure;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\HorizontalRule;
use Webconsulting\DocxEditor\PageSync\Document\Image;
use Webconsulting\DocxEditor\PageSync\Document\Inline;
use Webconsulting\DocxEditor\PageSync\Document\InlineControl;
use Webconsulting\DocxEditor\PageSync\Document\InlineImage;
use Webconsulting\DocxEditor\PageSync\Document\LineBreak;
use Webconsulting\DocxEditor\PageSync\Document\Link;
use Webconsulting\DocxEditor\PageSync\Document\ListBlock;
use Webconsulting\DocxEditor\PageSync\Document\Marks;
use Webconsulting\DocxEditor\PageSync\Document\PageBreak;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\ParagraphRole;
use Webconsulting\DocxEditor\PageSync\Document\Quote;
use Webconsulting\DocxEditor\PageSync\Document\Table;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Exception\PageSyncException;
use Webconsulting\DocxEditor\PageSync\Manifest\ManifestXml;
use Webconsulting\DocxEditor\PageSync\Ooxml\Writer\WriterState;

/**
 * Writes the document model as a clean .docx: named styles instead of direct formatting, real
 * lists, tables with header rows, embedded pictures with alt text, and the content controls,
 * bookmarks and manifest the round trip needs.
 */
final readonly class DocumentWriter
{
    /** The text width of an A4 page with 2.5 cm margins, in EMU. */
    private const int MAX_IMAGE_WIDTH_EMU = 5_760_720;
    private const int EMU_PER_PIXEL = 9525;
    private const int TEXT_WIDTH_TWIPS = 9072;

    public function __construct(
        private ManifestXml $manifestXml,
    ) {}

    public function write(WriteRequest $request): string
    {
        $template = WordTemplate::load($request->templatePath, $request->languageTag);
        $state = new WriterState();

        $document = new \DOMDocument('1.0', 'UTF-8');
        $root = $document->createElementNS(Ns::W, 'w:document');
        $document->appendChild($root);
        foreach (['r' => Ns::R, 'wp' => Ns::WP, 'a' => Ns::A, 'pic' => Ns::PIC] as $prefix => $namespace) {
            $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:' . $prefix, $namespace);
        }
        $body = $this->element($document, 'body');
        $root->appendChild($body);
        $this->writeBlocks($body, $request->blocks, $state, $template);
        $body->appendChild($this->sectionProperties($document));

        $state->relationship(Ns::REL_STYLES, 'styles.xml');
        $state->relationship(Ns::REL_SETTINGS, 'settings.xml');
        $parts = [
            'word/styles.xml' => $template->stylesXml,
            'word/settings.xml' => $template->settingsXml,
        ];
        if ($state->numberings !== []) {
            $state->relationship(Ns::REL_NUMBERING, 'numbering.xml');
            $parts['word/numbering.xml'] = $this->numberingXml($state);
        }
        if ($request->manifest !== null) {
            $state->relationship(Ns::REL_CUSTOM_XML, '../customXml/item1.xml');
            $parts['customXml/item1.xml'] = $this->manifestXml->toXml($request->manifest);
            $parts['customXml/itemProps1.xml'] = $this->manifestXml->itemPropertiesXml();
            $parts['customXml/_rels/item1.xml.rels'] = $this->relationshipsXml([
                ['id' => 'rId1', 'type' => Ns::REL_CUSTOM_XML_PROPS, 'target' => 'itemProps1.xml', 'external' => false],
            ]);
        }

        $parts['word/document.xml'] = (string)$document->saveXML();
        $parts['word/_rels/document.xml.rels'] = $this->relationshipsXml($state->relationships);
        foreach ($state->media as $media) {
            $parts['word/' . $media['part']] = $media['data']->bytes;
        }
        $parts['docProps/core.xml'] = $this->coreProperties($request);
        $parts['docProps/app.xml'] = $this->extendedProperties();
        $packageRelationships = [
            ['id' => 'rId1', 'type' => Ns::REL_OFFICE_DOCUMENT, 'target' => 'word/document.xml', 'external' => false],
            ['id' => 'rId2', 'type' => Ns::REL_CORE_PROPERTIES, 'target' => 'docProps/core.xml', 'external' => false],
            ['id' => 'rId3', 'type' => Ns::REL_EXTENDED_PROPERTIES, 'target' => 'docProps/app.xml', 'external' => false],
        ];
        if ($request->customProperties !== []) {
            $parts['docProps/custom.xml'] = $this->customProperties($request->customProperties);
            $packageRelationships[] = ['id' => 'rId4', 'type' => Ns::REL_CUSTOM_PROPERTIES, 'target' => 'docProps/custom.xml', 'external' => false];
        }
        $parts['_rels/.rels'] = $this->relationshipsXml($packageRelationships);

        return $this->zip(['[Content_Types].xml' => $this->contentTypes($parts, $state)] + $parts);
    }

    /**
     * @param list<Block> $blocks
     */
    private function writeBlocks(\DOMElement $parent, array $blocks, WriterState $state, WordTemplate $template): void
    {
        $document = $this->ownerDocument($parent);
        foreach ($blocks as $block) {
            match (true) {
                $block instanceof Heading => $parent->appendChild(
                    $this->paragraph($document, $template->styleId('heading' . $block->level), $block->inlines, $state, $template),
                ),
                $block instanceof Paragraph => $parent->appendChild($this->paragraph(
                    $document,
                    match ($block->role) {
                        ParagraphRole::Subtitle => $template->styleId('subtitle'),
                        ParagraphRole::Caption => $template->styleId('caption'),
                        ParagraphRole::Body => null,
                    },
                    $block->inlines,
                    $state,
                    $template,
                )),
                $block instanceof ListBlock => $this->writeList($parent, $block, $state, $template),
                $block instanceof Table => $this->writeTable($parent, $block, $state, $template),
                $block instanceof Figure => $this->writeFigure($parent, $block, $state, $template),
                $block instanceof Quote => $this->writeQuote($parent, $block, $state, $template),
                $block instanceof CodeBlock => $this->writeCode($parent, $block, $template),
                $block instanceof PageBreak => $parent->appendChild($this->pageBreak($document)),
                $block instanceof HorizontalRule => $parent->appendChild($this->horizontalRule($document)),
                $block instanceof ContentControl => $parent->appendChild($this->blockControl($block, $document, $state, $template)),
                $block instanceof Bookmark => $parent->appendChild($this->bookmark($document, $block, $state)),
                default => null,
            };
        }
    }

    /**
     * @param list<Inline> $inlines
     * @param array{numId: int, level: int}|null $numbering
     */
    private function paragraph(\DOMDocument $document, ?string $styleId, array $inlines, WriterState $state, WordTemplate $template, ?array $numbering = null): \DOMElement
    {
        $paragraph = $this->element($document, 'p');
        if ($styleId !== null || $numbering !== null) {
            $properties = $this->element($document, 'pPr');
            if ($styleId !== null) {
                $properties->appendChild($this->valued($document, 'pStyle', $styleId));
            }
            if ($numbering !== null) {
                $numPr = $this->element($document, 'numPr');
                $numPr->appendChild($this->valued($document, 'ilvl', (string)$numbering['level']));
                $numPr->appendChild($this->valued($document, 'numId', (string)$numbering['numId']));
                $properties->appendChild($numPr);
            }
            $paragraph->appendChild($properties);
        }
        $this->writeInlines($paragraph, $inlines, $state, $template);

        return $paragraph;
    }

    /**
     * @param list<Inline> $inlines
     */
    private function writeInlines(\DOMElement $paragraph, array $inlines, WriterState $state, WordTemplate $template, bool $inLink = false): void
    {
        $document = $this->ownerDocument($paragraph);
        foreach ($inlines as $inline) {
            if ($inline instanceof Text) {
                $this->writeText($paragraph, $inline->text, $inline->marks, $template, $inLink);
                continue;
            }
            if ($inline instanceof LineBreak) {
                $run = $this->run($document, Marks::none(), $template, $inLink);
                $run->appendChild($this->element($document, 'br'));
                $paragraph->appendChild($run);
                continue;
            }
            if ($inline instanceof Link) {
                $hyperlink = $this->hyperlink($document, $inline, $state);
                if ($hyperlink === null) {
                    $this->writeInlines($paragraph, $inline->children, $state, $template, $inLink);
                    continue;
                }
                $this->writeInlines($hyperlink, $inline->children, $state, $template, true);
                $paragraph->appendChild($hyperlink);
                continue;
            }
            if ($inline instanceof InlineControl) {
                $control = $this->element($document, 'sdt');
                $control->appendChild($this->controlProperties($document, $inline->tag, $inline->alias, $inline->lock, $state));
                $content = $this->element($document, 'sdtContent');
                $this->writeInlines($content, $inline->children, $state, $template, $inLink);
                if (!$content->hasChildNodes()) {
                    $content->appendChild($this->run($document, Marks::none(), $template, false));
                }
                $control->appendChild($content);
                $paragraph->appendChild($control);
                continue;
            }
            if ($inline instanceof InlineImage) {
                $paragraph->appendChild($this->drawingRun($document, $inline->image, $state));
            }
        }
    }

    private function writeText(\DOMElement $parent, string $text, Marks $marks, WordTemplate $template, bool $inLink): void
    {
        $text = self::xmlSafe($text);
        if ($text === '') {
            return;
        }
        $document = $this->ownerDocument($parent);
        $run = $this->run($document, $marks, $template, $inLink);
        $pieces = preg_split('/(\t|\r\n|\n|\r)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        foreach ($pieces === false ? [$text] : $pieces as $piece) {
            if ($piece === "\t") {
                $run->appendChild($this->element($document, 'tab'));
            } elseif ($piece === "\n" || $piece === "\r\n" || $piece === "\r") {
                $run->appendChild($this->element($document, 'br'));
            } else {
                $textElement = $this->element($document, 't');
                $textElement->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                $textElement->appendChild($document->createTextNode($piece));
                $run->appendChild($textElement);
            }
        }
        $parent->appendChild($run);
    }

    /**
     * A run with its properties in schema order (rStyle, rFonts, b, i, strike, u, vertAlign).
     */
    private function run(\DOMDocument $document, Marks $marks, WordTemplate $template, bool $inLink): \DOMElement
    {
        $run = $this->element($document, 'r');
        $properties = $this->element($document, 'rPr');
        if ($inLink) {
            $properties->appendChild($this->valued($document, 'rStyle', $template->styleId('hyperlink')));
            if ($marks->code) {
                $fonts = $this->element($document, 'rFonts');
                $fonts->setAttributeNS(Ns::W, 'w:ascii', 'Consolas');
                $fonts->setAttributeNS(Ns::W, 'w:hAnsi', 'Consolas');
                $properties->appendChild($fonts);
            }
        } elseif ($marks->code) {
            $properties->appendChild($this->valued($document, 'rStyle', $template->styleId('codeChar')));
        }
        if ($marks->bold) {
            $properties->appendChild($this->element($document, 'b'));
        }
        if ($marks->italic) {
            $properties->appendChild($this->element($document, 'i'));
        }
        if ($marks->strike) {
            $properties->appendChild($this->element($document, 'strike'));
        }
        if ($marks->underline) {
            $properties->appendChild($this->valued($document, 'u', 'single'));
        }
        if ($marks->superscript || $marks->subscript) {
            $properties->appendChild($this->valued($document, 'vertAlign', $marks->superscript ? 'superscript' : 'subscript'));
        }
        if ($properties->hasChildNodes()) {
            $run->appendChild($properties);
        }

        return $run;
    }

    private function hyperlink(\DOMDocument $document, Link $link, WriterState $state): ?\DOMElement
    {
        $href = trim($link->href);
        if ($href === '' || preg_match('/^\s*(javascript|vbscript|data):/i', $href) === 1) {
            return null;
        }
        $hyperlink = $this->element($document, 'hyperlink');
        if (str_starts_with($href, '#')) {
            $hyperlink->setAttributeNS(Ns::W, 'w:anchor', substr($href, 1));
        } else {
            // Relationship targets are URIs: whitespace and control characters are not allowed.
            $target = (string)preg_replace_callback('/[\x00-\x20\x7F]/', static fn(array $match): string => rawurlencode($match[0]), $href);
            $hyperlink->setAttributeNS(Ns::R, 'r:id', $state->hyperlink($target));
        }
        if ($link->title !== '') {
            $hyperlink->setAttributeNS(Ns::W, 'w:tooltip', self::xmlSafe($link->title));
        }
        $hyperlink->setAttributeNS(Ns::W, 'w:history', '1');

        return $hyperlink;
    }

    private function writeList(\DOMElement $parent, ListBlock $list, WriterState $state, WordTemplate $template): void
    {
        $numId = $state->numbering($list);
        $document = $this->ownerDocument($parent);
        foreach ($list->items as $item) {
            $parent->appendChild($this->paragraph(
                $document,
                $template->styleId('listParagraph'),
                $item->inlines,
                $state,
                $template,
                ['numId' => $numId, 'level' => $item->level],
            ));
        }
    }

    private function writeTable(\DOMElement $parent, Table $table, WriterState $state, WordTemplate $template): void
    {
        $document = $this->ownerDocument($parent);
        if ($table->caption !== '') {
            $parent->appendChild($this->paragraph($document, $template->styleId('caption'), [new Text($table->caption)], $state, $template));
        }
        $columns = max(1, $table->columnCount());
        $columnWidth = intdiv(self::TEXT_WIDTH_TWIPS, $columns);
        $hasHeader = $table->hasHeaderRow();

        $tableElement = $this->element($document, 'tbl');
        $properties = $this->element($document, 'tblPr');
        $properties->appendChild($this->valued($document, 'tblStyle', $template->styleId('tableGrid')));
        $width = $this->element($document, 'tblW');
        $width->setAttributeNS(Ns::W, 'w:w', '5000');
        $width->setAttributeNS(Ns::W, 'w:type', 'pct');
        $properties->appendChild($width);
        $look = $this->element($document, 'tblLook');
        $look->setAttributeNS(Ns::W, 'w:val', $hasHeader ? '04A0' : '0480');
        $look->setAttributeNS(Ns::W, 'w:firstRow', $hasHeader ? '1' : '0');
        $look->setAttributeNS(Ns::W, 'w:lastRow', '0');
        $look->setAttributeNS(Ns::W, 'w:firstColumn', '0');
        $look->setAttributeNS(Ns::W, 'w:lastColumn', '0');
        $look->setAttributeNS(Ns::W, 'w:noHBand', '0');
        $look->setAttributeNS(Ns::W, 'w:noVBand', '1');
        $properties->appendChild($look);
        $tableElement->appendChild($properties);

        $grid = $this->element($document, 'tblGrid');
        for ($i = 0; $i < $columns; $i++) {
            $column = $this->element($document, 'gridCol');
            $column->setAttributeNS(Ns::W, 'w:w', (string)$columnWidth);
            $grid->appendChild($column);
        }
        $tableElement->appendChild($grid);

        foreach ($table->rows as $row) {
            $rowElement = $this->element($document, 'tr');
            if ($row->header) {
                $rowProperties = $this->element($document, 'trPr');
                $rowProperties->appendChild($this->element($document, 'tblHeader'));
                $rowElement->appendChild($rowProperties);
            }
            $used = 0;
            foreach ($row->cells as $cell) {
                $cellElement = $this->element($document, 'tc');
                $cellProperties = $this->element($document, 'tcPr');
                $cellWidth = $this->element($document, 'tcW');
                $cellWidth->setAttributeNS(Ns::W, 'w:w', (string)($columnWidth * $cell->colspan));
                $cellWidth->setAttributeNS(Ns::W, 'w:type', 'dxa');
                $cellProperties->appendChild($cellWidth);
                if ($cell->colspan > 1) {
                    $cellProperties->appendChild($this->valued($document, 'gridSpan', (string)$cell->colspan));
                }
                $cellElement->appendChild($cellProperties);
                $this->writeBlocks($cellElement, $cell->blocks, $state, $template);
                $this->ensureParagraph($cellElement);
                $rowElement->appendChild($cellElement);
                $used += $cell->colspan;
            }
            // Word refuses rows with fewer cells than the grid; pad ragged rows.
            for (; $used < $columns; $used++) {
                $padding = $this->element($document, 'tc');
                $padding->appendChild($this->element($document, 'p'));
                $rowElement->appendChild($padding);
            }
            $tableElement->appendChild($rowElement);
        }
        if ($table->rows === []) {
            $rowElement = $this->element($document, 'tr');
            $padding = $this->element($document, 'tc');
            $padding->appendChild($this->element($document, 'p'));
            $rowElement->appendChild($padding);
            $tableElement->appendChild($rowElement);
        }
        $parent->appendChild($tableElement);
    }

    private function writeFigure(\DOMElement $parent, Figure $figure, WriterState $state, WordTemplate $template): void
    {
        $document = $this->ownerDocument($parent);
        $paragraph = $this->element($document, 'p');
        $paragraph->appendChild($this->drawingRun($document, $figure->image, $state));
        $parent->appendChild($paragraph);
        if ($figure->caption !== []) {
            $parent->appendChild($this->paragraph($document, $template->styleId('caption'), $figure->caption, $state, $template));
        }
    }

    private function writeQuote(\DOMElement $parent, Quote $quote, WriterState $state, WordTemplate $template): void
    {
        $document = $this->ownerDocument($parent);
        foreach ($quote->paragraphs as $paragraph) {
            $parent->appendChild($this->paragraph($document, $template->styleId('quote'), $paragraph->inlines, $state, $template));
        }
        if ($quote->citation !== []) {
            $parent->appendChild($this->paragraph(
                $document,
                $template->styleId('quoteSource'),
                [new Text('— '), ...$quote->citation],
                $state,
                $template,
            ));
        }
    }

    private function writeCode(\DOMElement $parent, CodeBlock $code, WordTemplate $template): void
    {
        $document = $this->ownerDocument($parent);
        foreach (preg_split('/\r\n|\n|\r/', $code->code) ?: [$code->code] as $line) {
            $paragraph = $this->element($document, 'p');
            $properties = $this->element($document, 'pPr');
            $properties->appendChild($this->valued($document, 'pStyle', $template->styleId('code')));
            $paragraph->appendChild($properties);
            $this->writeText($paragraph, $line, Marks::none(), $template, false);
            $parent->appendChild($paragraph);
        }
    }

    private function blockControl(ContentControl $control, \DOMDocument $document, WriterState $state, WordTemplate $template): \DOMElement
    {
        $element = $this->element($document, 'sdt');
        $element->appendChild($this->controlProperties($document, $control->tag, $control->alias, $control->lock, $state));
        $content = $this->element($document, 'sdtContent');
        $this->writeBlocks($content, $control->blocks, $state, $template);
        $this->ensureParagraph($content);
        $element->appendChild($content);

        return $element;
    }

    private function controlProperties(\DOMDocument $document, string $tag, string $alias, ControlLock $lock, WriterState $state): \DOMElement
    {
        $properties = $this->element($document, 'sdtPr');
        if ($alias !== '') {
            $properties->appendChild($this->valued($document, 'alias', mb_substr(self::xmlSafe($alias), 0, 64)));
        }
        $properties->appendChild($this->valued($document, 'tag', self::xmlSafe($tag)));
        $properties->appendChild($this->valued($document, 'id', (string)$state->controlId()));
        if ($lock !== ControlLock::Unlocked) {
            $properties->appendChild($this->valued($document, 'lock', $lock->value));
        }

        return $properties;
    }

    private function bookmark(\DOMDocument $document, Bookmark $bookmark, WriterState $state): \DOMElement
    {
        $element = $this->element($document, $bookmark->start ? 'bookmarkStart' : 'bookmarkEnd');
        $element->setAttributeNS(Ns::W, 'w:id', (string)$state->bookmarkId($bookmark->name));
        if ($bookmark->start) {
            $element->setAttributeNS(Ns::W, 'w:name', mb_substr($bookmark->name, 0, 40));
        }

        return $element;
    }

    private function drawingRun(\DOMDocument $document, Image $image, WriterState $state): \DOMElement
    {
        $relationshipId = $state->image($image->data);
        [$width, $height] = $this->extent($image);
        $id = $state->drawingId();

        $run = $this->element($document, 'r');
        $drawing = $this->element($document, 'drawing');
        $inline = $document->createElementNS(Ns::WP, 'wp:inline');
        foreach (['distT', 'distB', 'distL', 'distR'] as $distance) {
            $inline->setAttribute($distance, '0');
        }
        $extent = $document->createElementNS(Ns::WP, 'wp:extent');
        $extent->setAttribute('cx', (string)$width);
        $extent->setAttribute('cy', (string)$height);
        $inline->appendChild($extent);
        $effect = $document->createElementNS(Ns::WP, 'wp:effectExtent');
        foreach (['l', 't', 'r', 'b'] as $side) {
            $effect->setAttribute($side, '0');
        }
        $inline->appendChild($effect);
        $docPr = $document->createElementNS(Ns::WP, 'wp:docPr');
        $docPr->setAttribute('id', (string)$id);
        $docPr->setAttribute('name', 'Picture ' . $id);
        if ($image->alternative !== '') {
            $docPr->setAttribute('descr', self::xmlSafe($image->alternative));
        }
        if ($image->title !== '') {
            $docPr->setAttribute('title', self::xmlSafe($image->title));
        }
        $inline->appendChild($docPr);
        $frame = $document->createElementNS(Ns::WP, 'wp:cNvGraphicFramePr');
        $locks = $document->createElementNS(Ns::A, 'a:graphicFrameLocks');
        $locks->setAttribute('noChangeAspect', '1');
        $frame->appendChild($locks);
        $inline->appendChild($frame);

        $graphic = $document->createElementNS(Ns::A, 'a:graphic');
        $graphicData = $document->createElementNS(Ns::A, 'a:graphicData');
        $graphicData->setAttribute('uri', Ns::PIC);
        $picture = $document->createElementNS(Ns::PIC, 'pic:pic');
        $nonVisual = $document->createElementNS(Ns::PIC, 'pic:nvPicPr');
        $nonVisualProperties = $document->createElementNS(Ns::PIC, 'pic:cNvPr');
        $nonVisualProperties->setAttribute('id', '0');
        $nonVisualProperties->setAttribute('name', self::xmlSafe($image->data->fileName));
        if ($image->alternative !== '') {
            $nonVisualProperties->setAttribute('descr', self::xmlSafe($image->alternative));
        }
        $nonVisual->appendChild($nonVisualProperties);
        $nonVisual->appendChild($document->createElementNS(Ns::PIC, 'pic:cNvPicPr'));
        $picture->appendChild($nonVisual);
        $fill = $document->createElementNS(Ns::PIC, 'pic:blipFill');
        $blip = $document->createElementNS(Ns::A, 'a:blip');
        $blip->setAttributeNS(Ns::R, 'r:embed', $relationshipId);
        $fill->appendChild($blip);
        $stretch = $document->createElementNS(Ns::A, 'a:stretch');
        $stretch->appendChild($document->createElementNS(Ns::A, 'a:fillRect'));
        $fill->appendChild($stretch);
        $picture->appendChild($fill);
        $shape = $document->createElementNS(Ns::PIC, 'pic:spPr');
        $transform = $document->createElementNS(Ns::A, 'a:xfrm');
        $offset = $document->createElementNS(Ns::A, 'a:off');
        $offset->setAttribute('x', '0');
        $offset->setAttribute('y', '0');
        $transform->appendChild($offset);
        $size = $document->createElementNS(Ns::A, 'a:ext');
        $size->setAttribute('cx', (string)$width);
        $size->setAttribute('cy', (string)$height);
        $transform->appendChild($size);
        $shape->appendChild($transform);
        $geometry = $document->createElementNS(Ns::A, 'a:prstGeom');
        $geometry->setAttribute('prst', 'rect');
        $geometry->appendChild($document->createElementNS(Ns::A, 'a:avLst'));
        $shape->appendChild($geometry);
        $picture->appendChild($shape);
        $graphicData->appendChild($picture);
        $graphic->appendChild($graphicData);
        $inline->appendChild($graphic);
        $drawing->appendChild($inline);
        $run->appendChild($drawing);

        return $run;
    }

    /**
     * @return array{0: int, 1: int} width and height in EMU, at most the text width
     */
    private function extent(Image $image): array
    {
        $width = $image->widthEmu;
        $height = $image->heightEmu;
        if ($width <= 0 || $height <= 0) {
            $info = @getimagesizefromstring($image->data->bytes);
            $width = is_array($info) ? max(1, $info[0]) * self::EMU_PER_PIXEL : self::MAX_IMAGE_WIDTH_EMU;
            $height = is_array($info) ? max(1, $info[1]) * self::EMU_PER_PIXEL : intdiv(self::MAX_IMAGE_WIDTH_EMU * 2, 3);
        }
        if ($width > self::MAX_IMAGE_WIDTH_EMU) {
            $height = (int)round($height * self::MAX_IMAGE_WIDTH_EMU / $width);
            $width = self::MAX_IMAGE_WIDTH_EMU;
        }

        return [max(1, $width), max(1, $height)];
    }

    private function pageBreak(\DOMDocument $document): \DOMElement
    {
        $paragraph = $this->element($document, 'p');
        $run = $this->element($document, 'r');
        $break = $this->element($document, 'br');
        $break->setAttributeNS(Ns::W, 'w:type', 'page');
        $run->appendChild($break);
        $paragraph->appendChild($run);

        return $paragraph;
    }

    private function horizontalRule(\DOMDocument $document): \DOMElement
    {
        $paragraph = $this->element($document, 'p');
        $properties = $this->element($document, 'pPr');
        $borders = $this->element($document, 'pBdr');
        $bottom = $this->element($document, 'bottom');
        $bottom->setAttributeNS(Ns::W, 'w:val', 'single');
        $bottom->setAttributeNS(Ns::W, 'w:sz', '6');
        $bottom->setAttributeNS(Ns::W, 'w:space', '1');
        $bottom->setAttributeNS(Ns::W, 'w:color', 'auto');
        $borders->appendChild($bottom);
        $properties->appendChild($borders);
        $paragraph->appendChild($properties);

        return $paragraph;
    }

    private function sectionProperties(\DOMDocument $document): \DOMElement
    {
        $section = $this->element($document, 'sectPr');
        $size = $this->element($document, 'pgSz');
        $size->setAttributeNS(Ns::W, 'w:w', '11906');
        $size->setAttributeNS(Ns::W, 'w:h', '16838');
        $section->appendChild($size);
        $margins = $this->element($document, 'pgMar');
        foreach (['top' => '1417', 'right' => '1417', 'bottom' => '1134', 'left' => '1417', 'header' => '708', 'footer' => '708', 'gutter' => '0'] as $side => $value) {
            $margins->setAttributeNS(Ns::W, 'w:' . $side, $value);
        }
        $section->appendChild($margins);

        return $section;
    }

    /**
     * A table cell or content control must hold at least one block, and a cell must end with a
     * paragraph; bookmarks do not count.
     */
    private function ensureParagraph(\DOMElement $container): void
    {
        $lastBlock = null;
        foreach ($container->childNodes as $child) {
            if ($child instanceof \DOMElement && in_array($child->localName, ['p', 'tbl', 'sdt'], true)) {
                $lastBlock = $child->localName;
            }
        }
        if ($lastBlock === null || ($container->localName === 'tc' && $lastBlock !== 'p')) {
            $container->appendChild($this->element($this->ownerDocument($container), 'p'));
        }
    }

    private function numberingXml(WriterState $state): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $root = $document->createElementNS(Ns::W, 'w:numbering');
        $document->appendChild($root);
        $bullets = ['•', '◦', '▪'];
        $orderedFormats = ['decimal', 'lowerLetter', 'lowerRoman'];
        foreach ($state->abstractNumberings as $abstract) {
            $abstractElement = $this->element($document, 'abstractNum');
            $abstractElement->setAttributeNS(Ns::W, 'w:abstractNumId', (string)$abstract['id']);
            $abstractElement->appendChild($this->valued($document, 'multiLevelType', 'hybridMultilevel'));
            foreach ($abstract['levels'] as $level => $ordered) {
                $levelElement = $this->element($document, 'lvl');
                $levelElement->setAttributeNS(Ns::W, 'w:ilvl', (string)$level);
                $levelElement->appendChild($this->valued($document, 'start', '1'));
                $levelElement->appendChild($this->valued($document, 'numFmt', $ordered ? $orderedFormats[$level % 3] : 'bullet'));
                $levelElement->appendChild($this->valued($document, 'lvlText', $ordered ? '%' . ($level + 1) . '.' : $bullets[$level % 3]));
                $levelElement->appendChild($this->valued($document, 'lvlJc', 'left'));
                $paragraphProperties = $this->element($document, 'pPr');
                $indent = $this->element($document, 'ind');
                $indent->setAttributeNS(Ns::W, 'w:left', (string)(720 * ($level + 1)));
                $indent->setAttributeNS(Ns::W, 'w:hanging', '360');
                $paragraphProperties->appendChild($indent);
                $levelElement->appendChild($paragraphProperties);
                $abstractElement->appendChild($levelElement);
            }
            $root->appendChild($abstractElement);
        }
        foreach ($state->numberings as $numbering) {
            $num = $this->element($document, 'num');
            $num->setAttributeNS(Ns::W, 'w:numId', (string)$numbering['numId']);
            $num->appendChild($this->valued($document, 'abstractNumId', (string)$numbering['abstractId']));
            // Every list restarts at one, even where two share a definition.
            $override = $this->element($document, 'lvlOverride');
            $override->setAttributeNS(Ns::W, 'w:ilvl', '0');
            $override->appendChild($this->valued($document, 'startOverride', '1'));
            $num->appendChild($override);
            $root->appendChild($num);
        }

        return (string)$document->saveXML();
    }

    /**
     * @param list<array{id: string, type: string, target: string, external: bool}> $relationships
     */
    private function relationshipsXml(array $relationships): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $root = $document->createElementNS(Ns::PACKAGE_RELATIONSHIPS, 'Relationships');
        $document->appendChild($root);
        foreach ($relationships as $relationship) {
            $element = $document->createElementNS(Ns::PACKAGE_RELATIONSHIPS, 'Relationship');
            $element->setAttribute('Id', $relationship['id']);
            $element->setAttribute('Type', $relationship['type']);
            $element->setAttribute('Target', self::xmlSafe($relationship['target']));
            if ($relationship['external']) {
                $element->setAttribute('TargetMode', 'External');
            }
            $root->appendChild($element);
        }

        return (string)$document->saveXML();
    }

    /**
     * @param array<string, string> $parts
     */
    private function contentTypes(array $parts, WriterState $state): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $root = $document->createElementNS(Ns::CONTENT_TYPES, 'Types');
        $document->appendChild($root);
        $defaults = ['rels' => Ns::CT_RELATIONSHIPS, 'xml' => 'application/xml'];
        foreach ($state->media as $media) {
            $defaults[$media['data']->extension()] = $media['data']->mimeType;
        }
        foreach ($defaults as $extension => $contentType) {
            $default = $document->createElementNS(Ns::CONTENT_TYPES, 'Default');
            $default->setAttribute('Extension', $extension);
            $default->setAttribute('ContentType', $contentType);
            $root->appendChild($default);
        }
        $overrides = [
            'word/document.xml' => Ns::CT_DOCUMENT,
            'word/styles.xml' => Ns::CT_STYLES,
            'word/settings.xml' => Ns::CT_SETTINGS,
            'word/numbering.xml' => Ns::CT_NUMBERING,
            'docProps/core.xml' => Ns::CT_CORE_PROPERTIES,
            'docProps/app.xml' => Ns::CT_EXTENDED_PROPERTIES,
            'docProps/custom.xml' => Ns::CT_CUSTOM_PROPERTIES,
            'customXml/itemProps1.xml' => Ns::CT_CUSTOM_XML_PROPS,
        ];
        foreach ($overrides as $part => $contentType) {
            if (!isset($parts[$part])) {
                continue;
            }
            $override = $document->createElementNS(Ns::CONTENT_TYPES, 'Override');
            $override->setAttribute('PartName', '/' . $part);
            $override->setAttribute('ContentType', $contentType);
            $root->appendChild($override);
        }

        return (string)$document->saveXML();
    }

    private function coreProperties(WriteRequest $request): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $document = new \DOMDocument('1.0', 'UTF-8');
        $root = $document->createElementNS(Ns::CORE_PROPERTIES, 'cp:coreProperties');
        $document->appendChild($root);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dc', Ns::DC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dcterms', Ns::DCTERMS);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', Ns::XSI);
        if ($request->title !== '') {
            $root->appendChild($this->textElement($document, Ns::DC, 'dc:title', $request->title));
        }
        if ($request->creator !== '') {
            $root->appendChild($this->textElement($document, Ns::DC, 'dc:creator', $request->creator));
            $root->appendChild($this->textElement($document, Ns::CORE_PROPERTIES, 'cp:lastModifiedBy', $request->creator));
        }
        if ($request->languageTag !== '') {
            $root->appendChild($this->textElement($document, Ns::DC, 'dc:language', $request->languageTag));
        }
        foreach (['created', 'modified'] as $name) {
            $date = $this->textElement($document, Ns::DCTERMS, 'dcterms:' . $name, $now);
            $date->setAttributeNS(Ns::XSI, 'xsi:type', 'dcterms:W3CDTF');
            $root->appendChild($date);
        }

        return (string)$document->saveXML();
    }

    private function extendedProperties(): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $root = $document->createElementNS(Ns::EXTENDED_PROPERTIES, 'Properties');
        $document->appendChild($root);
        $root->appendChild($this->textElement($document, Ns::EXTENDED_PROPERTIES, 'Application', 'TYPO3 docx_editor'));

        return (string)$document->saveXML();
    }

    /**
     * @param array<string, string> $properties
     */
    private function customProperties(array $properties): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $root = $document->createElementNS(Ns::CUSTOM_PROPERTIES, 'Properties');
        $document->appendChild($root);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:vt', Ns::VT);
        $pid = 2;
        foreach ($properties as $name => $value) {
            $property = $document->createElementNS(Ns::CUSTOM_PROPERTIES, 'property');
            $property->setAttribute('fmtid', Ns::CUSTOM_PROPERTY_FMTID);
            $property->setAttribute('pid', (string)$pid++);
            $property->setAttribute('name', self::xmlSafe($name));
            $property->appendChild($this->textElement($document, Ns::VT, 'vt:lpwstr', $value));
            $root->appendChild($property);
        }

        return (string)$document->saveXML();
    }

    /**
     * @param array<string, string> $parts
     */
    private function zip(array $parts): string
    {
        $temporaryFile = GeneralUtility::tempnam('docx_pagesync_out_');
        $zip = new \ZipArchive();
        if ($zip->open($temporaryFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new PageSyncException('error.writeFailed', 500);
        }
        try {
            foreach ($parts as $name => $contents) {
                $zip->addFromString($name, $contents);
                if (str_starts_with($name, 'word/media/')) {
                    // Pictures are compressed already.
                    $zip->setCompressionName($name, \ZipArchive::CM_STORE);
                }
            }
            if (!$zip->close()) {
                throw new PageSyncException('error.writeFailed', 500);
            }
            $binary = file_get_contents($temporaryFile);
        } finally {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
        if ($binary === false || $binary === '') {
            throw new PageSyncException('error.writeFailed', 500);
        }

        return $binary;
    }

    private function textElement(\DOMDocument $document, string $namespace, string $qualifiedName, string $text): \DOMElement
    {
        $element = $document->createElementNS($namespace, $qualifiedName);
        $element->appendChild($document->createTextNode(self::xmlSafe($text)));

        return $element;
    }

    private function element(\DOMDocument $document, string $localName): \DOMElement
    {
        return $document->createElementNS(Ns::W, 'w:' . $localName);
    }

    private function valued(\DOMDocument $document, string $localName, string $value): \DOMElement
    {
        $element = $this->element($document, $localName);
        $element->setAttributeNS(Ns::W, 'w:val', $value);

        return $element;
    }

    private function ownerDocument(\DOMNode $node): \DOMDocument
    {
        $document = $node instanceof \DOMDocument ? $node : $node->ownerDocument;
        if ($document === null) {
            throw new PageSyncException('error.writeFailed', 500);
        }

        return $document;
    }

    /**
     * Characters XML 1.0 cannot carry at all.
     */
    private static function xmlSafe(string $text): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u', '', $text);

        return $clean ?? (string)mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
}

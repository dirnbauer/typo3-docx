<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Unit\PageSync\Ooxml;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Document\Bookmark;
use Webconsulting\DocxEditor\PageSync\Document\CodeBlock;
use Webconsulting\DocxEditor\PageSync\Document\ContentControl;
use Webconsulting\DocxEditor\PageSync\Document\ControlLock;
use Webconsulting\DocxEditor\PageSync\Document\Figure;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\HorizontalRule;
use Webconsulting\DocxEditor\PageSync\Document\Image;
use Webconsulting\DocxEditor\PageSync\Document\ImageData;
use Webconsulting\DocxEditor\PageSync\Document\InlineControl;
use Webconsulting\DocxEditor\PageSync\Document\LineBreak;
use Webconsulting\DocxEditor\PageSync\Document\Link;
use Webconsulting\DocxEditor\PageSync\Document\ListBlock;
use Webconsulting\DocxEditor\PageSync\Document\ListItem;
use Webconsulting\DocxEditor\PageSync\Document\Marks;
use Webconsulting\DocxEditor\PageSync\Document\PageBreak;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\ParagraphRole;
use Webconsulting\DocxEditor\PageSync\Document\PlainText;
use Webconsulting\DocxEditor\PageSync\Document\Quote;
use Webconsulting\DocxEditor\PageSync\Document\Table;
use Webconsulting\DocxEditor\PageSync\Document\TableCell;
use Webconsulting\DocxEditor\PageSync\Document\TableRow;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Html\BlocksToHtml;
use Webconsulting\DocxEditor\PageSync\Manifest\ManifestField;
use Webconsulting\DocxEditor\PageSync\Manifest\ManifestRecord;
use Webconsulting\DocxEditor\PageSync\Manifest\ManifestXml;
use Webconsulting\DocxEditor\PageSync\Manifest\RoundTripManifest;
use Webconsulting\DocxEditor\PageSync\Ooxml\DocumentReader;
use Webconsulting\DocxEditor\PageSync\Ooxml\DocumentWriter;
use Webconsulting\DocxEditor\PageSync\Ooxml\DocxArchive;
use Webconsulting\DocxEditor\PageSync\Ooxml\WriteRequest;
use Webconsulting\DocxEditor\Tests\Fixtures\PageSync\DocxFixtureBuilder;

/**
 * Whatever the writer produces, the reader reads back as the same content.
 */
final class DocumentRoundTripTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-encryption-key-for-the-page-sync-manifest';
    }

    #[Test]
    public function everyConstructSurvivesWritingAndReading(): void
    {
        $picture = new ImageData(DocxFixtureBuilder::png(10, 200, 30, 40, 20), 'image/png', 'green.png');
        $blocks = [
            new Heading(1, [new Text('Page title')]),
            new Paragraph([new Text('A subtitle')], ParagraphRole::Subtitle),
            new Heading(2, [new Text('Section')]),
            new Paragraph([
                new Text('Plain, '),
                new Text('bold', new Marks(bold: true)),
                new Text(', '),
                new Text('both', new Marks(bold: true, italic: true)),
                new Text(', '),
                new Text('under', new Marks(underline: true)),
                new Text(' '),
                new Text('gone', new Marks(strike: true)),
                new Text(' H'),
                new Text('2', new Marks(subscript: true)),
                new Text('O x'),
                new Text('2', new Marks(superscript: true)),
                new Text(' '),
                new Text('call()', new Marks(code: true)),
                new LineBreak(),
                new Link('t3://page?uid=5&_language=1#c12', [new Text('internal')], 'A tooltip'),
                new Text(' '),
                new Link('https://example.org/path?x=ä&y=1', [new Text('external')]),
                new Text(' '),
                new Link('#section-2', [new Text('anchor')]),
                new Text("\tafter a tab & <angle> brackets"),
            ]),
            new ListBlock([
                new ListItem(0, false, [new Text('one')]),
                new ListItem(1, true, [new Text('one.first')]),
                new ListItem(1, true, [new Text('one.second')]),
                new ListItem(0, false, [new Text('two')]),
            ]),
            new ListBlock([new ListItem(0, true, [new Text('numbered again')])]),
            new Table([
                new TableRow([new TableCell([new Paragraph([new Text('Plan')])]), new TableCell([new Paragraph([new Text('Price')])])], true),
                new TableRow([new TableCell([new Paragraph([new Text('Basic')])]), new TableCell([new Paragraph([new Text('100 €')])])]),
                new TableRow([new TableCell([new Paragraph([new Text('All inclusive')])], 2)]),
            ], 'Prices'),
            new Figure(new Image($picture, 'A green bar', 'Bar'), [new Text('Figure caption')]),
            new Quote([new Paragraph([new Text('Less is more.')])], [new Text('Ludwig Mies van der Rohe')]),
            new CodeBlock("if (\$a) {\n    return 'x';\n}"),
            new HorizontalRule(),
            new PageBreak(),
            new ContentControl('typo3:tt_content:12', 'Plugin: News (read-only)', [
                new Paragraph([new Text('Summary of the plugin')], ParagraphRole::Body),
            ], ControlLock::SdtContentLocked),
            new ContentControl('typo3:tt_content:13', 'Accordion', [
                new Bookmark('_t3_tt_content_13', true),
                new ContentControl('typo3:tt_content:13:header', 'Header', [new Heading(2, [new Text('FAQ')])]),
                new ContentControl('typo3:accordion_items:5', 'Item', [
                    new Heading(3, [new InlineControl('typo3:accordion_items:5:title', 'Title', [new Text('What does it cost?')])]),
                    new ContentControl('typo3:accordion_items:5:content', 'Content', [new Paragraph([new Text('It depends.')])]),
                ]),
                new Bookmark('_t3_tt_content_13', false),
            ]),
            new ContentControl('typo3:tt_content:14:bodytext', 'Empty field', []),
        ];

        $binary = $this->writer()->write(new WriteRequest($blocks, title: 'Round trip', creator: 'Tester', languageTag: 'de-AT', customProperties: ['TYPO3 page' => '7']));
        $document = $this->reader()->read($binary);

        self::assertSame([], $document->warnings);
        self::assertSame('Round trip', $document->title);
        self::assertSame(['TYPO3 page' => '7'], $document->customProperties);
        $html = new BlocksToHtml();
        self::assertSame($html->convert($blocks), $html->convert($document->blocks));
        self::assertSame(self::describe($blocks), self::describe($document->blocks));

        $figure = $document->blocks[7];
        self::assertInstanceOf(Figure::class, $figure);
        self::assertSame('A green bar', $figure->image->alternative);
        self::assertSame('Bar', $figure->image->title);
        self::assertSame($picture->sha1(), $figure->image->data->sha1());
        self::assertSame('image/png', $figure->image->data->mimeType);
        self::assertSame('Figure caption', PlainText::ofInlines($figure->caption));

        $table = $document->blocks[6];
        self::assertInstanceOf(Table::class, $table);
        self::assertSame('Prices', $table->caption);
        self::assertSame(2, $table->rows[2]->cells[0]->colspan);

        $plugin = $document->blocks[12];
        self::assertInstanceOf(ContentControl::class, $plugin);
        self::assertSame(ControlLock::SdtContentLocked, $plugin->lock);
        self::assertSame('Plugin: News (read-only)', $plugin->alias);

        $element = $document->blocks[13];
        self::assertInstanceOf(ContentControl::class, $element);
        self::assertSame(['_t3_tt_content_13'], $element->bookmarks);
        $item = $element->blocks[2];
        self::assertInstanceOf(ContentControl::class, $item);
        $itemHeading = $item->blocks[0];
        self::assertInstanceOf(Heading::class, $itemHeading);
        $title = $itemHeading->inlines[0];
        self::assertInstanceOf(InlineControl::class, $title);
        self::assertSame('typo3:accordion_items:5:title', $title->tag);

        $empty = $document->blocks[14];
        self::assertInstanceOf(ContentControl::class, $empty);
        self::assertSame([], $empty->blocks);
    }

    /**
     * Found on real pages: "©" (C2 A9) lost its first byte to a byte-wise trim of "\u{00A0}"
     * (C2 A0) and the field would have been emptied; "à" (C3 A0) at the end lost its last byte.
     */
    #[Test]
    public function charactersSharingBytesWithANonBreakingSpaceSurvive(): void
    {
        $texts = ['© 2026 webconsulting studio', 'Voilà', '« Guillemets »', '° 45 ½', "\u{00A0}keeps the rest\u{00A0}"];
        $binary = $this->writer()->write(new WriteRequest(array_map(static fn(string $text): Paragraph => new Paragraph([new Text($text)]), $texts)));

        $read = array_map(static fn(Block $block): string => PlainText::ofBlock($block), $this->reader()->read($binary)->blocks);

        self::assertSame(['© 2026 webconsulting studio', 'Voilà', '« Guillemets »', '° 45 ½', 'keeps the rest'], $read);
        foreach ($read as $text) {
            self::assertTrue(mb_check_encoding($text, 'UTF-8'));
        }
    }

    #[Test]
    public function whitespaceInALinkTargetIsPercentEncoded(): void
    {
        $binary = $this->writer()->write(new WriteRequest([
            new Paragraph([new Link('https://example.org/a b', [new Text('x')])]),
        ]));
        $paragraph = $this->reader()->read($binary)->blocks[0];

        self::assertInstanceOf(Paragraph::class, $paragraph);
        $link = $paragraph->inlines[0];
        self::assertInstanceOf(Link::class, $link);
        self::assertSame('https://example.org/a%20b', $link->href);
    }

    #[Test]
    public function thePackageIsWellFormedWord(): void
    {
        $binary = $this->writer()->write(new WriteRequest([
            new Paragraph([new Text('x')]),
            new ListBlock([new ListItem(0, true, [new Text('a')])]),
            new Figure(new Image(new ImageData(DocxFixtureBuilder::png(), 'image/png', 'a.png'))),
        ], manifest: $this->manifest()));
        $archive = DocxArchive::fromBinary($binary);

        self::assertSame('word/document.xml', $archive->mainDocumentPart());
        foreach (['[Content_Types].xml', 'word/styles.xml', 'word/settings.xml', 'word/numbering.xml', 'customXml/item1.xml', 'customXml/itemProps1.xml', 'docProps/core.xml', 'word/media/image1.png'] as $part) {
            self::assertTrue($archive->has($part), $part . ' is missing');
        }
        $contentTypes = $archive->read('[Content_Types].xml');
        self::assertStringContainsString('Extension="png" ContentType="image/png"', $contentTypes);
        self::assertStringContainsString('PartName="/customXml/itemProps1.xml"', $contentTypes);
        $relationships = $archive->relationships('word/document.xml');
        $types = array_map(static fn($relationship): string => basename($relationship->type), array_values($relationships));
        self::assertContains('customXml', $types);
        self::assertContains('numbering', $types);
        self::assertContains('image', $types);
    }

    #[Test]
    public function theManifestTravelsSignedAndIsFoundByNamespace(): void
    {
        $binary = $this->writer()->write(new WriteRequest([new Paragraph([new Text('x')])], manifest: $this->manifest()));
        $manifest = $this->reader()->read($binary)->manifest;

        self::assertNotNull($manifest);
        self::assertTrue($manifest->trusted);
        self::assertSame(7, $manifest->pageUid);
        self::assertSame('main', $manifest->siteIdentifier);
        self::assertSame(1, $manifest->language);
        $record = $manifest->record('tt_content', 11);
        self::assertNotNull($record);
        self::assertSame('text', $record->type);
        self::assertSame('abc', $record->field('bodytext')?->hash);
        self::assertSame(2, $record->field('header')?->level);

        // Word renames custom XML parts when it saves; the manifest is still found.
        $archive = DocxArchive::fromBinary($binary);
        $parts = [];
        foreach ($archive->partNames() as $name) {
            $parts[$name] = $archive->read($name);
        }
        $parts['customXml/item3.xml'] = $parts['customXml/item1.xml'];
        unset($parts['customXml/item1.xml'], $parts['customXml/_rels/item1.xml.rels']);
        $parts['word/_rels/document.xml.rels'] = str_replace('item1.xml', 'item3.xml', $parts['word/_rels/document.xml.rels']);
        $renamed = $this->reader()->read(DocxFixtureBuilder::zip($parts))->manifest;
        self::assertNotNull($renamed);
        self::assertTrue($renamed->trusted);

        // A manifest edited by hand is found, but no longer trusted.
        $parts['customXml/item3.xml'] = str_replace('hash="abc"', 'hash="forged"', $parts['customXml/item3.xml']);
        $forged = $this->reader()->read(DocxFixtureBuilder::zip($parts))->manifest;
        self::assertNotNull($forged);
        self::assertFalse($forged->trusted);
    }

    private function manifest(): RoundTripManifest
    {
        return new RoundTripManifest(
            pageUid: 7,
            siteIdentifier: 'main',
            language: 1,
            workspace: 0,
            exportedAt: new \DateTimeImmutable('2026-09-23T12:00:00+00:00'),
            records: [
                'tt_content:11' => new ManifestRecord('tt_content', 11, [
                    'header' => new ManifestField('header', 'def', 2, 1),
                    'bodytext' => new ManifestField('bodytext', 'abc', 0, 2),
                ], 'text', 0, 1, reference: 1),
            ],
        );
    }

    private function writer(): DocumentWriter
    {
        return new DocumentWriter(new ManifestXml(new HashService()));
    }

    private function reader(): DocumentReader
    {
        return new DocumentReader(new ManifestXml(new HashService()));
    }

    /**
     * The block structure as a readable outline, for comparing documents.
     *
     * @param list<Block> $blocks
     *
     * @return list<string>
     */
    private static function describe(array $blocks, string $indent = ''): array
    {
        $lines = [];
        foreach ($blocks as $block) {
            $name = (new \ReflectionClass($block))->getShortName();
            $detail = match (true) {
                $block instanceof Heading => 'h' . $block->level . ' ' . PlainText::ofBlock($block),
                $block instanceof Paragraph => $block->role->value . ' ' . PlainText::ofBlock($block),
                $block instanceof ListBlock => implode(',', array_map(static fn(ListItem $item): string => $item->level . ($item->ordered ? 'o' : 'b'), $block->items)),
                $block instanceof ContentControl => $block->tag . ' ' . $block->lock->value,
                $block instanceof Bookmark => $block->name . ($block->start ? ' start' : ' end'),
                default => PlainText::ofBlock($block),
            };
            $lines[] = $indent . $name . ': ' . $detail;
            if ($block instanceof ContentControl) {
                array_push($lines, ...self::describe($block->blocks, $indent . '  '));
            }
        }

        return $lines;
    }
}

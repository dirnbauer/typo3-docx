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
use Webconsulting\DocxEditor\PageSync\Document\Figure;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\Link;
use Webconsulting\DocxEditor\PageSync\Document\ListBlock;
use Webconsulting\DocxEditor\PageSync\Document\PageBreak;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\PlainText;
use Webconsulting\DocxEditor\PageSync\Document\Quote;
use Webconsulting\DocxEditor\PageSync\Document\Table;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Html\BlocksToHtml;
use Webconsulting\DocxEditor\PageSync\Manifest\ManifestXml;
use Webconsulting\DocxEditor\PageSync\Ooxml\DocumentReader;
use Webconsulting\DocxEditor\Tests\Fixtures\PageSync\DocxFixtureBuilder;

/**
 * What the reader makes of documents other producers wrote.
 */
final class DocumentReaderTest extends UnitTestCase
{
    private const string LOCALISED_STYLES = '<w:style w:type="paragraph" w:default="1" w:styleId="Standard"><w:name w:val="Normal"/></w:style>'
        . '<w:style w:type="paragraph" w:styleId="berschrift2"><w:name w:val="heading 2"/><w:basedOn w:val="Standard"/></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Kapitel"><w:name w:val="Kapitel"/><w:basedOn w:val="berschrift2"/></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Zitat"><w:name w:val="Quote"/></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Verzeichnis1"><w:name w:val="toc 1"/></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Aufzhlungszeichen"><w:name w:val="List Bullet"/><w:pPr><w:numPr><w:numId w:val="7"/></w:numPr></w:pPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Gliederung"><w:name w:val="Gliederung"/><w:pPr><w:outlineLvl w:val="2"/></w:pPr></w:style>'
        . '<w:style w:type="character" w:styleId="Fett"><w:name w:val="Strong"/></w:style>'
        . '<w:style w:type="character" w:styleId="VerbatimChar"><w:name w:val="Verbatim Char"/></w:style>';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-encryption-key-for-the-page-sync-manifest';
    }

    #[Test]
    public function stylesAreRecognisedByTheirBuiltInNameNotTheirLocalisedId(): void
    {
        $blocks = $this->read(
            DocxFixtureBuilder::body(
                '<w:p><w:pPr><w:pStyle w:val="berschrift2"/></w:pPr><w:r><w:t>Überschrift</w:t></w:r></w:p>'
                . '<w:p><w:pPr><w:pStyle w:val="Kapitel"/></w:pPr><w:r><w:t>Based on a heading</w:t></w:r></w:p>'
                . '<w:p><w:pPr><w:pStyle w:val="Gliederung"/></w:pPr><w:r><w:t>By outline level</w:t></w:r></w:p>'
                . '<w:p><w:pPr><w:outlineLvl w:val="3"/></w:pPr><w:r><w:t>Direct outline level</w:t></w:r></w:p>'
                . '<w:p><w:pPr><w:pStyle w:val="Zitat"/></w:pPr><w:r><w:t>Quoted</w:t></w:r></w:p>'
                . '<w:p><w:r><w:t xml:space="preserve">– Somebody</w:t></w:r></w:p>'
                . '<w:p><w:pPr><w:pStyle w:val="Verzeichnis1"/></w:pPr><w:r><w:t>Table of contents entry</w:t></w:r></w:p>'
                . '<w:p><w:pPr><w:pStyle w:val="Aufzhlungszeichen"/></w:pPr><w:r><w:t>Bulleted by its style</w:t></w:r></w:p>'
                . '<w:p><w:r><w:rPr><w:rStyle w:val="Fett"/></w:rPr><w:t>strong</w:t></w:r><w:r><w:t xml:space="preserve"> and </w:t></w:r><w:r><w:rPr><w:rStyle w:val="VerbatimChar"/></w:rPr><w:t>code</w:t></w:r></w:p>',
            )->withStyles(self::LOCALISED_STYLES)
                ->withNumbering('<w:abstractNum w:abstractNumId="3"><w:lvl w:ilvl="0"><w:numFmt w:val="bullet"/></w:lvl></w:abstractNum><w:num w:numId="7"><w:abstractNumId w:val="3"/></w:num>'),
        );

        self::assertSame(
            '<h2>Überschrift</h2><h2>Based on a heading</h2><h3>By outline level</h3><h4>Direct outline level</h4>'
            . '<blockquote><p>Quoted</p><p>— Somebody</p></blockquote>'
            . '<ul><li>Bulleted by its style</li></ul>'
            . '<p><strong>strong</strong> and <code>code</code></p>',
            new BlocksToHtml()->convert($blocks),
        );
        self::assertInstanceOf(Quote::class, $blocks[4]);
    }

    #[Test]
    public function fieldsTrackedChangesAndSmartTagsResolveToTheirVisibleText(): void
    {
        $blocks = $this->read(DocxFixtureBuilder::body(
            '<w:p>'
            . '<w:r><w:t xml:space="preserve">See </w:t></w:r>'
            . '<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> HYPERLINK "https://typo3.org" \o "TYPO3" </w:instrText></w:r>'
            . '<w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>typo3.org</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r>'
            . '<w:r><w:t xml:space="preserve">, page </w:t></w:r>'
            . '<w:fldSimple w:instr=" PAGE "><w:r><w:t>3</w:t></w:r></w:fldSimple>'
            . '<w:ins w:id="1" w:author="A"><w:r><w:t xml:space="preserve"> inserted</w:t></w:r></w:ins>'
            . '<w:del w:id="2" w:author="A"><w:r><w:delText> deleted</w:delText></w:r></w:del>'
            . '<w:proofErr w:type="spellStart"/><w:smartTag w:uri="x" w:element="place"><w:r><w:t xml:space="preserve"> Vienna</w:t></w:r></w:smartTag>'
            . '<w:fldSimple w:instr=" HYPERLINK \l &quot;top&quot; "><w:r><w:t xml:space="preserve"> up</w:t></w:r></w:fldSimple>'
            . '</w:p>',
        ));

        self::assertCount(1, $blocks);
        self::assertSame(
            '<p>See <a href="https://typo3.org" title="TYPO3">typo3.org</a>, page 3 inserted Vienna<a href="#top"> up</a></p>',
            new BlocksToHtml()->convert($blocks),
        );
    }

    #[Test]
    public function breaksRulesTextBoxesAndMathAreCarried(): void
    {
        $blocks = $this->read(DocxFixtureBuilder::body(
            '<w:p><w:r><w:t>before</w:t><w:br w:type="page"/><w:t>after</w:t></w:r></w:p>'
            . '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="auto"/></w:pBdr></w:pPr></w:p>'
            . '<w:p><w:r><w:t>***</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>anchor</w:t></w:r><w:r><mc:AlternateContent><mc:Choice Requires="wps"><w:drawing/></mc:Choice>'
            . '<mc:Fallback><w:pict><v:rect><v:textbox><w:txbxContent><w:p><w:r><w:t>In a text box</w:t></w:r></w:p></w:txbxContent></v:textbox></v:rect></w:pict></mc:Fallback>'
            . '</mc:AlternateContent></w:r></w:p>'
            . '<w:p><w:pPr><w:sectPr/></w:pPr><w:r><w:t>end of section</w:t></w:r></w:p>'
            . '<w:p><m:oMath><m:r><m:t>E=mc2</m:t></m:r></m:oMath></w:p>'
            . '<w:p><w:r><w:t>x</w:t><w:sym w:font="Wingdings" w:char="F0E0"/><w:sym w:font="Symbol" w:char="03B1"/></w:r></w:p>',
        ));

        $summary = array_map(static fn(Block $block): string => (new \ReflectionClass($block))->getShortName() . ':' . PlainText::ofBlock($block), $blocks);
        self::assertSame([
            'Paragraph:before',
            'PageBreak:',
            'Paragraph:after',
            'HorizontalRule:',
            'HorizontalRule:',
            'Paragraph:anchor',
            'Paragraph:In a text box',
            'Paragraph:end of section',
            'PageBreak:',
            'Paragraph:E=mc2',
            'Paragraph:xα',
        ], $summary);
    }

    #[Test]
    public function foreignContentControlsAreUnwrappedAndRoundTripControlsKept(): void
    {
        $blocks = $this->read(DocxFixtureBuilder::body(
            '<w:sdt><w:sdtPr><w:tag w:val="company-form-field"/></w:sdtPr><w:sdtContent><w:p><w:r><w:t>Unwrapped</w:t></w:r></w:p></w:sdtContent></w:sdt>'
            . '<w:sdt><w:sdtPr><w:docPartObj><w:docPartGallery w:val="Table of Contents"/></w:docPartObj></w:sdtPr><w:sdtContent><w:p><w:r><w:t>TOC</w:t></w:r></w:p></w:sdtContent></w:sdt>'
            . '<w:sdt><w:sdtPr><w:alias w:val="Text"/><w:tag w:val="typo3:tt_content:4:bodytext"/><w:showingPlcHdr/></w:sdtPr><w:sdtContent><w:p><w:r><w:t>Click or tap here to enter text.</w:t></w:r></w:p></w:sdtContent></w:sdt>'
            . '<w:p><w:bookmarkStart w:id="9" w:name="_t3_tt_content_5"/><w:r><w:t>Element without its control</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t>second paragraph</w:t></w:r><w:bookmarkEnd w:id="9"/></w:p>'
            . '<w:p><w:bookmarkStart w:id="10" w:name="_GoBack"/><w:bookmarkEnd w:id="10"/><w:r><w:t>plain</w:t></w:r></w:p>',
        ));

        self::assertInstanceOf(Paragraph::class, $blocks[0]);
        self::assertSame('Unwrapped', PlainText::ofBlock($blocks[0]));
        $control = $blocks[1];
        self::assertInstanceOf(ContentControl::class, $control);
        self::assertTrue($control->showingPlaceholder);
        self::assertSame([], $control->blocks, 'Placeholder text is not content');
        self::assertInstanceOf(Bookmark::class, $blocks[2]);
        self::assertTrue($blocks[2]->start);
        self::assertSame(['tt_content', 5], $blocks[2]->record());
        self::assertInstanceOf(Paragraph::class, $blocks[3]);
        self::assertInstanceOf(Paragraph::class, $blocks[4]);
        self::assertInstanceOf(Bookmark::class, $blocks[5]);
        self::assertFalse($blocks[5]->start);
        self::assertInstanceOf(Paragraph::class, $blocks[6]);
        self::assertCount(7, $blocks);
    }

    #[Test]
    public function tablesListsCodeAndCaptionsAreAssembled(): void
    {
        $blocks = $this->read(
            DocxFixtureBuilder::body(
                '<w:p><w:pPr><w:pStyle w:val="Caption"/></w:pPr><w:r><w:t>Table 1: Prices</w:t></w:r></w:p>'
                . '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/></w:tblPr><w:tblGrid><w:gridCol/><w:gridCol/></w:tblGrid>'
                . '<w:tr><w:tc><w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Plan</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Price</w:t></w:r></w:p></w:tc></w:tr>'
                . '<w:tr><w:tc><w:tcPr><w:vMerge w:val="restart"/></w:tcPr><w:p><w:r><w:t>Basic</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>10</w:t></w:r></w:p></w:tc></w:tr>'
                . '<w:tr><w:tc><w:tcPr><w:vMerge/></w:tcPr><w:p/></w:tc><w:tc><w:p><w:r><w:t>20</w:t></w:r></w:p></w:tc></w:tr>'
                . '</w:tbl>'
                . '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>Step one</w:t></w:r></w:p>'
                . '<w:p><w:pPr><w:numPr><w:ilvl w:val="1"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>Detail</w:t></w:r></w:p>'
                . '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="2"/></w:numPr></w:pPr><w:r><w:t>Other list</w:t></w:r></w:p>'
                . '<w:p><w:r><w:rPr><w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/></w:rPr><w:t xml:space="preserve">  indented()</w:t></w:r></w:p>'
                . '<w:p><w:r><w:rPr><w:rFonts w:ascii="Courier New" w:hAnsi="Courier New"/></w:rPr><w:t>next();</w:t></w:r></w:p>',
            )->withStyles('<w:style w:type="paragraph" w:styleId="Caption"><w:name w:val="caption"/></w:style>')
                ->withNumbering(
                    '<w:abstractNum w:abstractNumId="0"><w:lvl w:ilvl="0"><w:numFmt w:val="decimal"/></w:lvl><w:lvl w:ilvl="1"><w:numFmt w:val="bullet"/></w:lvl></w:abstractNum>'
                    . '<w:abstractNum w:abstractNumId="1"><w:lvl w:ilvl="0"><w:numFmt w:val="bullet"/></w:lvl></w:abstractNum>'
                    . '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num><w:num w:numId="2"><w:abstractNumId w:val="1"/></w:num>',
                ),
        );

        self::assertInstanceOf(Table::class, $blocks[0]);
        self::assertSame('Table 1: Prices', $blocks[0]->caption);
        self::assertTrue($blocks[0]->hasHeaderRow(), 'A bold first row is a header row');
        self::assertSame('', PlainText::ofBlocks($blocks[0]->rows[2]->cells[0]->blocks), 'A merged-away cell is empty');
        self::assertInstanceOf(ListBlock::class, $blocks[1]);
        self::assertSame([[0, true], [1, false]], array_map(static fn($item): array => [$item->level, $item->ordered], $blocks[1]->items));
        self::assertInstanceOf(ListBlock::class, $blocks[2]);
        self::assertFalse($blocks[2]->isOrdered());
        self::assertInstanceOf(CodeBlock::class, $blocks[3]);
        self::assertSame("  indented()\nnext();", $blocks[3]->code);
    }

    #[Test]
    public function picturesAreCheckedAndOnlyWebFormatsAccepted(): void
    {
        $drawing = static fn(string $relationshipId, string $alt): string => '<w:p><w:r><w:drawing><wp:inline><wp:extent cx="914400" cy="457200"/>'
            . '<wp:docPr id="1" name="Picture" descr="' . $alt . '"/><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic><pic:blipFill><a:blip r:embed="' . $relationshipId . '"/></pic:blipFill></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';

        $document = new DocumentReader(new ManifestXml(new HashService()))->read(
            DocxFixtureBuilder::body(
                $drawing('rIdPng', 'A red dot')
                . $drawing('rIdEmf', 'Clip art')
                . $drawing('rIdFake', 'Not an image')
                . '<w:p><w:r><w:t>Text with a </w:t></w:r><w:r><w:drawing><wp:inline><wp:docPr id="2" name="x"/><a:graphic><a:graphicData><pic:pic><pic:blipFill><a:blip r:embed="rIdPng"/></pic:blipFill></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r><w:r><w:t xml:space="preserve"> picture inside.</w:t></w:r></w:p>',
            )
                ->withImage('rIdPng', 'media/image1.png', DocxFixtureBuilder::png())
                ->withImage('rIdEmf', 'media/image2.emf', "\x01\x00\x00\x00EMF-bytes")
                ->withImage('rIdFake', 'media/image3.png', '<?php echo "no";')
                ->build(),
        );

        $blocks = $document->blocks;
        self::assertInstanceOf(Figure::class, $blocks[0]);
        self::assertSame('A red dot', $blocks[0]->image->alternative);
        self::assertSame(914400, $blocks[0]->image->widthEmu);
        self::assertInstanceOf(Paragraph::class, $blocks[1]);
        self::assertSame('Text with a picture inside.', PlainText::ofBlock($blocks[1]));
        self::assertCount(2, $blocks);
        self::assertSame(
            ['warning.unsupportedImage:image2.emf', 'warning.unsupportedImage:image3.png'],
            array_map(static fn($warning): string => $warning->labelKey . ':' . implode(',', $warning->arguments), $document->warnings),
        );
    }

    #[Test]
    public function hyperlinksResolveThroughTheirRelationship(): void
    {
        $blocks = $this->read(
            DocxFixtureBuilder::body(
                '<w:p><w:hyperlink r:id="rIdLink"><w:r><w:t>TYPO3 link</w:t></w:r></w:hyperlink>'
                . '<w:hyperlink w:anchor="_Toc1"><w:r><w:t xml:space="preserve"> jump</w:t></w:r></w:hyperlink>'
                . '<w:hyperlink r:id="rIdMissing"><w:r><w:t xml:space="preserve"> dangling</w:t></w:r></w:hyperlink></w:p>',
            )->withRelationship('rIdLink', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink', 't3://page?uid=12', true),
        );

        $paragraph = $blocks[0];
        self::assertInstanceOf(Paragraph::class, $paragraph);
        self::assertInstanceOf(Link::class, $paragraph->inlines[0]);
        self::assertSame('t3://page?uid=12', $paragraph->inlines[0]->href);
        self::assertInstanceOf(Link::class, $paragraph->inlines[1]);
        self::assertSame('#_Toc1', $paragraph->inlines[1]->href);
        self::assertInstanceOf(Text::class, $paragraph->inlines[2], 'A link without a target is plain text');
    }

    #[Test]
    public function headingsDoNotSwallowTheirPictures(): void
    {
        $blocks = $this->read(
            DocxFixtureBuilder::body(
                '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Title</w:t></w:r>'
                . '<w:r><w:drawing><wp:inline><wp:docPr id="1" name="x" descr="Logo"/><a:graphic><a:graphicData><pic:pic><pic:blipFill><a:blip r:embed="rIdPng"/></pic:blipFill></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>'
                . '<w:p><w:r><w:br w:type="page"/></w:r></w:p>',
            )
                ->withStyles('<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/></w:style>')
                ->withImage('rIdPng', 'media/image1.png', DocxFixtureBuilder::png()),
        );

        self::assertInstanceOf(Heading::class, $blocks[0]);
        self::assertSame('Title', PlainText::ofBlock($blocks[0]));
        self::assertInstanceOf(Figure::class, $blocks[1]);
        self::assertInstanceOf(PageBreak::class, $blocks[2]);
    }

    /**
     * @return list<Block>
     */
    private function read(DocxFixtureBuilder $builder): array
    {
        return new DocumentReader(new ManifestXml(new HashService()))->read($builder->build())->blocks;
    }
}

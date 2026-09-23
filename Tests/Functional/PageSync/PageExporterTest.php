<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Functional\PageSync;

use PHPUnit\Framework\Attributes\Test;
use Webconsulting\DocxEditor\PageSync\Document\ControlLock;
use Webconsulting\DocxEditor\PageSync\Document\Figure;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\PlainText;
use Webconsulting\DocxEditor\PageSync\Document\Table;
use Webconsulting\DocxEditor\PageSync\Exception\PageSyncException;
use Webconsulting\DocxEditor\PageSync\Export\PageExporter;
use Webconsulting\DocxEditor\PageSync\Html\BlocksToHtml;

final class PageExporterTest extends AbstractPageSyncTestCase
{
    #[Test]
    public function aPageBecomesAWordDocumentWithOneControlPerElementAndField(): void
    {
        $result = $this->get(PageExporter::class)->export(1, 0, $this->backendUser(1));
        $document = $this->read($result->binary);

        self::assertSame('our-services-en-gb.docx', $result->fileName);
        self::assertSame(7, $result->elementCount);
        self::assertSame([], $document->warnings);
        self::assertSame('Our services', $document->title);
        self::assertSame('1', $document->customProperties['TYPO3 page'] ?? null);

        $title = self::control($document->blocks, 'typo3:pages:1:title');
        self::assertNotNull($title);
        self::assertInstanceOf(Heading::class, $title->blocks[0]);
        self::assertSame(1, $title->blocks[0]->level);

        $text = self::control($document->blocks, 'typo3:tt_content:2');
        self::assertNotNull($text);
        self::assertSame(['_t3_tt_content_2'], $text->bookmarks);
        $bodytext = self::control($document->blocks, 'typo3:tt_content:2:bodytext');
        self::assertNotNull($bodytext);
        self::assertSame(
            '<p>We build <strong>websites</strong> with <a href="t3://page?uid=3">TYPO3</a>.</p><ul><li>Plan</li><li>Build</li></ul>',
            new BlocksToHtml()->convert($bodytext->blocks),
        );

        $header = self::control($document->blocks, 'typo3:tt_content:3:header');
        self::assertNotNull($header);
        self::assertInstanceOf(Heading::class, $header->blocks[0]);
        self::assertSame(3, $header->blocks[0]->level, 'header_layout 3 is a level-3 heading');
        $assets = self::control($document->blocks, 'typo3:tt_content:3:assets');
        self::assertNotNull($assets);
        self::assertInstanceOf(Figure::class, $assets->blocks[0]);
        self::assertSame('Our office in Vienna', $assets->blocks[0]->image->alternative);
        self::assertSame('The entrance', PlainText::ofInlines($assets->blocks[0]->caption));

        $table = self::control($document->blocks, 'typo3:tt_content:4:bodytext');
        self::assertNotNull($table);
        self::assertInstanceOf(Table::class, $table->blocks[0]);
        self::assertTrue($table->blocks[0]->hasHeaderRow());
        self::assertSame("Plan\tPrice\nBasic\t10 €\nPro\t20 €", PlainText::ofBlock($table->blocks[0]));

        $plugin = self::control($document->blocks, 'typo3:tt_content:5');
        self::assertNotNull($plugin);
        self::assertSame(ControlLock::SdtContentLocked, $plugin->lock);
        self::assertStringContainsString('Event list', PlainText::ofBlocks($plugin->blocks));

        self::assertNotNull(self::control($document->blocks, 'typo3:tt_content:6:pagesynctest_items'));
        $item = self::control($document->blocks, 'typo3:tx_pagesynctest_item:1');
        self::assertNotNull($item);
        self::assertSame('What does it cost?', PlainText::ofBlocks(self::control($item->blocks, 'typo3:tx_pagesynctest_item:1:title')->blocks ?? []));

        $quote = self::control($document->blocks, 'typo3:tt_content:7:quote_text');
        self::assertNotNull($quote);
        self::assertSame('They understood us.', PlainText::ofBlocks($quote->blocks));

        $manifest = $document->manifest;
        self::assertNotNull($manifest);
        self::assertTrue($manifest->trusted);
        self::assertSame(1, $manifest->pageUid);
        self::assertSame('main', $manifest->siteIdentifier);
        self::assertSame(['tt_content:1', 'tt_content:2', 'tt_content:3', 'tt_content:4', 'tt_content:5', 'tt_content:6', 'tt_content:7'], array_map(
            static fn($record): string => $record->key(),
            $manifest->elements(),
        ));
        self::assertTrue($manifest->record('tt_content', 5)?->locked);
        self::assertSame(3, $manifest->record('tt_content', 3)?->field('header')?->level);
        self::assertCount(2, $manifest->childrenOf('tt_content:6'));
        self::assertNotSame('', $manifest->record('tt_content', 2)?->fingerprint);
    }

    #[Test]
    public function aTranslationInConnectedModeExportsTheUntranslatedElementsAsSources(): void
    {
        $result = $this->get(PageExporter::class)->export(1, 1, $this->backendUser(1));
        $document = $this->read($result->binary);

        self::assertSame('unser-angebot-de-at.docx', $result->fileName);
        $title = self::control($document->blocks, 'typo3:pages:2:title');
        self::assertNotNull($title, 'The title comes from the page translation');
        self::assertSame('Unser Angebot', PlainText::ofBlocks($title->blocks));
        $manifest = $document->manifest;
        self::assertNotNull($manifest);
        self::assertSame(1, $manifest->language);
        self::assertTrue($manifest->record('tt_content', 2)?->translationSource);
    }

    #[Test]
    public function aUserWithoutAccessToThePageGetsNothing(): void
    {
        $this->expectException(PageSyncException::class);
        $this->expectExceptionCode(404);
        $this->get(PageExporter::class)->export(999, 0, $this->backendUser(1));
    }
}

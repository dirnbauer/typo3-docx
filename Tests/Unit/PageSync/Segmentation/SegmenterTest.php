<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Unit\PageSync\Segmentation;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\DocxEditor\PageSync\Document\Bookmark;
use Webconsulting\DocxEditor\PageSync\Document\CodeBlock;
use Webconsulting\DocxEditor\PageSync\Document\Figure;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\HorizontalRule;
use Webconsulting\DocxEditor\PageSync\Document\Image;
use Webconsulting\DocxEditor\PageSync\Document\ImageData;
use Webconsulting\DocxEditor\PageSync\Document\Link;
use Webconsulting\DocxEditor\PageSync\Document\ListBlock;
use Webconsulting\DocxEditor\PageSync\Document\ListItem;
use Webconsulting\DocxEditor\PageSync\Document\Marks;
use Webconsulting\DocxEditor\PageSync\Document\PageBreak;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\ParagraphRole;
use Webconsulting\DocxEditor\PageSync\Document\Quote;
use Webconsulting\DocxEditor\PageSync\Document\Table;
use Webconsulting\DocxEditor\PageSync\Document\TableCell;
use Webconsulting\DocxEditor\PageSync\Document\TableRow;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Segmentation\Part;
use Webconsulting\DocxEditor\PageSync\Segmentation\PartAnalyzer;
use Webconsulting\DocxEditor\PageSync\Segmentation\Segmenter;

final class SegmenterTest extends UnitTestCase
{
    #[Test]
    public function headingsOpenSectionsAndDeeperHeadingsBecomeItems(): void
    {
        $parts = $this->segment([
            self::p('An introduction without heading.'),
            new Heading(2, [new Text('Our services')]),
            new Paragraph([new Text('We plan and build websites.')], ParagraphRole::Subtitle),
            self::p('Everything from one hand.'),
            self::figure('photo'),
            new Heading(2, [new Text('Frequently asked questions')]),
            new Heading(3, [new Text('What does it cost?')]),
            self::p('It depends on the scope.'),
            new Heading(3, [new Text('How long does it take?')]),
            self::p('Usually two weeks.'),
            self::p('Sometimes three.'),
        ]);

        self::assertCount(3, $parts);
        self::assertNull($parts[0]->shape->heading);
        self::assertSame('An introduction without heading.', $parts[0]->shape->bodyText());

        $services = $parts[1]->shape;
        self::assertSame('Our services', $services->headingText());
        self::assertNotNull($services->subtitle);
        self::assertCount(1, $services->images);
        self::assertSame([], $services->items);

        $faq = $parts[2]->shape;
        self::assertSame('Frequently asked questions', $faq->headingText());
        self::assertCount(2, $faq->items);
        self::assertTrue($faq->items[0]->isQuestion());
        self::assertSame("Usually two weeks.\nSometimes three.", $faq->items[1]->bodyText());
        self::assertSame([], $faq->body);
    }

    #[Test]
    public function breaksAndRulesAreHardBoundaries(): void
    {
        $parts = $this->segment([
            self::p('First.'),
            new PageBreak(),
            self::p('Second.'),
            new HorizontalRule(),
            self::p('Third.'),
        ]);

        self::assertSame(['First.', 'Second.', 'Third.'], array_map(static fn(Part $part): string => $part->shape->bodyText(), $parts));
    }

    #[Test]
    public function tablesQuotesAndCodeAmidTextStandAlone(): void
    {
        $table = new Table([
            new TableRow([new TableCell([self::p('Plan')]), new TableCell([self::p('Price')])], true),
            new TableRow([new TableCell([self::p('Basic')]), new TableCell([self::p('10')])]),
        ]);
        $parts = $this->segment([
            new Heading(2, [new Text('Pricing')]),
            $table,
            self::p('All prices plus VAT.'),
            new Quote([self::p('Best agency ever.')], [new Text('A client')]),
            new CodeBlock('npm install'),
        ]);

        self::assertCount(4, $parts);
        self::assertSame('Pricing', $parts[0]->shape->headingText(), 'The heading stays with the table it announces');
        self::assertSame($table, $parts[0]->shape->table);
        self::assertSame('All prices plus VAT.', $parts[1]->shape->bodyText());
        self::assertNotNull($parts[2]->shape->quote);
        self::assertNotNull($parts[3]->shape->code);
    }

    #[Test]
    public function questionsAndBoldLeadInsFormItemsOnlyWhenTheyMakeUpThePart(): void
    {
        $faq = $this->segment([
            self::p('Is it fast?'),
            self::p('Yes.'),
            self::p('Is it safe?'),
            self::p('Also yes.'),
        ]);
        self::assertCount(2, $faq[0]->shape->items);

        $features = $this->segment([
            new Paragraph([new Text('Fast', new Marks(bold: true))]),
            self::p('Loads in a blink.'),
            new Paragraph([new Text('Secure', new Marks(bold: true))]),
            self::p('Audited yearly.'),
        ]);
        self::assertSame(['Fast', 'Secure'], array_map(static fn($item): string => $item->titleText(), $features[0]->shape->items));

        $prose = $this->segment([
            self::p('A long text. ' . str_repeat('Words and more words. ', 10)),
            self::p('Why would anyone do that?'),
            self::p('Another long paragraph. ' . str_repeat('Words and more words. ', 10)),
            self::p('And again?'),
            self::p('More prose follows here.'),
            self::p('Even more prose.'),
            self::p('The end.'),
        ]);
        self::assertSame([], $prose[0]->shape->items, 'Two questions in a long text are prose');
    }

    #[Test]
    public function aClosingLinkIsTheCallToAction(): void
    {
        $parts = $this->segment([
            new Heading(2, [new Text('Ready?')]),
            self::p('Talk to us.'),
            new Paragraph([new Link('t3://page?uid=9', [new Text('Contact us')])]),
        ]);

        self::assertCount(1, $parts[0]->shape->links);
        self::assertSame('t3://page?uid=9', $parts[0]->shape->links[0]->href);
        self::assertSame('Talk to us.', $parts[0]->shape->bodyText());
    }

    #[Test]
    public function aRoundTripBookmarkKeepsAnElementTogether(): void
    {
        $parts = $this->segment([
            self::p('Before.'),
            new Bookmark('_t3_tt_content_42', true),
            new Heading(2, [new Text('Kept together')]),
            self::p('One.'),
            new HorizontalRule(),
            new Heading(2, [new Text('Still the same element')]),
            new Bookmark('_t3_tt_content_42', false),
            self::p('After.'),
        ]);

        self::assertCount(3, $parts);
        self::assertSame(['tt_content', 42], $parts[1]->recordHint);
        self::assertSame('Kept together', $parts[1]->shape->headingText());
        self::assertNull($parts[0]->recordHint);
    }

    #[Test]
    public function aListOnItsOwnIsTheBody(): void
    {
        $parts = $this->segment([
            new Heading(2, [new Text('Checklist')]),
            new ListBlock([new ListItem(0, false, [new Text('one')]), new ListItem(0, false, [new Text('two')])]),
        ]);

        self::assertTrue($parts[0]->shape->bodyIsOnlyList());
        self::assertStringContainsString('1 list', $parts[0]->shape->describe());
    }

    /**
     * @param list<\Webconsulting\DocxEditor\PageSync\Document\Block> $blocks
     *
     * @return list<Part>
     */
    private function segment(array $blocks): array
    {
        return new Segmenter(new PartAnalyzer())->segment($blocks);
    }

    private static function p(string $text): Paragraph
    {
        return new Paragraph([new Text($text)]);
    }

    private static function figure(string $name): Figure
    {
        return new Figure(new Image(new ImageData('bytes-' . $name, 'image/png', $name . '.png'), 'Alt ' . $name));
    }
}

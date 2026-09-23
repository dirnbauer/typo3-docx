<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Unit\PageSync\Matching;

use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\Backend\NullBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\DocxEditor\PageSync\Configuration\PageSyncSettings;
use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Document\Figure;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\Image;
use Webconsulting\DocxEditor\PageSync\Document\ImageData;
use Webconsulting\DocxEditor\PageSync\Document\Link;
use Webconsulting\DocxEditor\PageSync\Document\ListBlock;
use Webconsulting\DocxEditor\PageSync\Document\ListItem;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\ParagraphRole;
use Webconsulting\DocxEditor\PageSync\Document\Quote;
use Webconsulting\DocxEditor\PageSync\Document\Table;
use Webconsulting\DocxEditor\PageSync\Document\TableCell;
use Webconsulting\DocxEditor\PageSync\Document\TableRow;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Matching\ContentTypeMatcher;
use Webconsulting\DocxEditor\PageSync\Matching\FieldPlacer;
use Webconsulting\DocxEditor\PageSync\Matching\Jev\JevContentTypeChooser;
use Webconsulting\DocxEditor\PageSync\Matching\MatchContext;
use Webconsulting\DocxEditor\PageSync\Matching\PartMatch;
use Webconsulting\DocxEditor\PageSync\Matching\Proposal;
use Webconsulting\DocxEditor\PageSync\Matching\Rule\CoreContentTypeRule;
use Webconsulting\DocxEditor\PageSync\Matching\Rule\NameHintRule;
use Webconsulting\DocxEditor\PageSync\Matching\Rule\StructuralRule;
use Webconsulting\DocxEditor\PageSync\Matching\Value\CollectionValue;
use Webconsulting\DocxEditor\PageSync\Matching\Value\TextValue;
use Webconsulting\DocxEditor\PageSync\Schema\ElementShapeFactory;
use Webconsulting\DocxEditor\PageSync\Schema\FieldRoleClassifier;
use Webconsulting\DocxEditor\PageSync\Segmentation\PartAnalyzer;
use Webconsulting\DocxEditor\PageSync\Segmentation\Segmenter;
use Webconsulting\DocxEditor\Tests\Fixtures\PageSync\FakeJevClient;
use Webconsulting\DocxEditor\Tests\Fixtures\PageSync\LabShapes;
use Webconsulting\WebconJev\Configuration\Settings;
use Webconsulting\WebconJev\Service\DecisionRunner;
use Webconsulting\WebconJev\Service\RunLogger;
use Webconsulting\WebconJev\Service\StateBuilder;

/**
 * The matcher against the lab's element types: core types and desiderio Content Blocks.
 */
final class ContentTypeMatcherTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    #[Test]
    public function plainTextGoesIntoTheTextElement(): void
    {
        $match = $this->matchOne([
            new Heading(2, [new Text('About us')]),
            self::p('We are a small agency in Vienna.'),
            self::p('We build websites since 2004.'),
        ]);

        $chosen = self::chosen($match);
        self::assertSame('text', $chosen->cType);
        self::assertFalse($match->needsReview);
        self::assertSame(PartMatch::BY_STRUCTURE, $match->decidedBy);
        self::assertSame('About us', self::text($chosen->mapping()->assignment('header')?->value));
        self::assertNotNull($chosen->mapping()->assignment('bodytext'));
    }

    #[Test]
    public function aHeadingOnItsOwnIsAHeader(): void
    {
        self::assertSame('header', $this->matchOne([new Heading(2, [new Text('Chapter two')])])->chosen?->cType);
    }

    #[Test]
    public function textWithAPictureGoesIntoTextAndMedia(): void
    {
        $match = $this->matchOne([
            new Heading(2, [new Text('Our office')]),
            self::p('Come and see us.'),
            self::figure('office'),
        ]);

        $chosen = self::chosen($match);
        self::assertSame('textmedia', $chosen->cType);
        self::assertNotNull($chosen->mapping()->assignment('assets'));
    }

    #[Test]
    public function aTableGoesIntoTheTableElement(): void
    {
        $match = $this->matchOne([
            new Heading(2, [new Text('Prices')]),
            new Table([
                new TableRow([new TableCell([self::p('Plan')]), new TableCell([self::p('Price')])], true),
                new TableRow([new TableCell([self::p('Basic')]), new TableCell([self::p('10 €')])]),
            ], 'All prices plus VAT'),
        ]);

        $chosen = self::chosen($match);
        self::assertSame('table', $chosen->cType);
        self::assertSame(1, $chosen->mapping()->settings['table_header_position'] ?? null);
        self::assertNotNull($chosen->mapping()->assignment('table_caption'));
    }

    #[Test]
    public function aListOnItsOwnGoesIntoTheBulletList(): void
    {
        $match = $this->matchOne([
            new Heading(2, [new Text('Checklist')]),
            new ListBlock([new ListItem(0, true, [new Text('Plan')]), new ListItem(0, true, [new Text('Build')])]),
        ]);

        $chosen = self::chosen($match);
        self::assertSame('bullets', $chosen->cType);
        self::assertSame(1, $chosen->mapping()->settings['bullets_type'] ?? null);
    }

    #[Test]
    public function aQuoteWithAttributionGoesIntoTheQuoteElement(): void
    {
        $match = $this->matchOne([
            new Quote([self::p('They understood us from day one.')], [new Text('Anna Berger, CEO of Beispiel GmbH')]),
        ]);

        $chosen = self::chosen($match);
        self::assertSame('desiderio_quote', $chosen->cType);
        self::assertSame('Anna Berger', self::text($chosen->mapping()->assignment('author')?->value));
        self::assertSame('CEO of Beispiel GmbH', self::text($chosen->mapping()->assignment('role')?->value));
    }

    #[Test]
    public function figuresWithLabelsBecomeStatistics(): void
    {
        $match = $this->matchOne([
            new Heading(2, [new Text('In numbers')]),
            new Heading(3, [new Text('98 %')]),
            self::p('happy clients'),
            new Heading(3, [new Text('250+')]),
            self::p('websites launched'),
            new Heading(3, [new Text('20')]),
            self::p('years of experience'),
        ]);

        $chosen = self::chosen($match);
        self::assertSame('desiderio_stats', $chosen->cType);
        $items = $chosen->mapping()->assignment('desiderio_stats_stats')?->value;
        self::assertInstanceOf(CollectionValue::class, $items);
        self::assertCount(3, $items->items);
        self::assertSame('98 %', self::text($items->items[0]->assignment('value')?->value));
        self::assertSame('happy clients', self::text($items->items[0]->assignment('label')?->value));
    }

    #[Test]
    public function picturesOnTheirOwnGoIntoTheImageElement(): void
    {
        self::assertSame('image', $this->matchOne([self::figure('a'), self::figure('b'), self::figure('c')])->chosen?->cType);
    }

    #[Test]
    public function aHeroIsAHeadingASubtitleAndAButton(): void
    {
        $match = $this->matchOne([
            new Heading(1, [new Text('Websites that work')]),
            new Paragraph([new Text('Built with TYPO3 in Vienna')], ParagraphRole::Subtitle),
            new Paragraph([new Link('t3://page?uid=12', [new Text('Get in touch')])]),
        ]);

        $chosen = self::chosen($match);
        self::assertSame('desiderio_herominimal', $chosen->cType);
        self::assertSame('Get in touch', self::text($chosen->mapping()->assignment('button_text')?->value));
        self::assertNotNull($chosen->mapping()->assignment('button_link'));
    }

    #[Test]
    public function contestedPartsWithoutJevKeepTheBestStructuralTypeAndNeedReview(): void
    {
        $match = $this->matchOne($this->faq());

        self::assertSame('desiderio_accordion', $match->chosen?->cType);
        self::assertTrue($match->needsReview);
        self::assertSame(PartMatch::BY_STRUCTURE, $match->decidedBy);
        self::assertNotNull($match->jev);
        self::assertNull($match->jev->choice);
        self::assertSame('webcon_jev is not installed', $match->jev->fallbackReason);
    }

    #[Test]
    public function jevDecidesContestedPartsWhenItIsSure(): void
    {
        $client = new FakeJevClient(static fn(string $name, $question): array => ['desiderio_pricingfaq', 0.91]);
        $match = $this->matchOne($this->faq(), $client);

        self::assertSame('desiderio_pricingfaq', $match->chosen?->cType);
        self::assertSame(PartMatch::BY_JEV, $match->decidedBy);
        self::assertFalse($match->needsReview);
        self::assertSame(0.91, $match->jev?->confidence);
        self::assertCount(1, $client->calls);
        $question = $client->calls[0]['questions']['p1'] ?? null;
        self::assertNotNull($question);
        self::assertArrayHasKey('desiderio_accordion', $question->criteria);
        self::assertArrayHasKey('desiderio_pricingfaq', $question->criteria);
        $state = $client->calls[0]['state'];
        self::assertIsArray($state);
        self::assertStringContainsString('What does it cost?', (string)json_encode($state));
    }

    #[Test]
    public function anUnsureJevAnswerIsShownButNotTaken(): void
    {
        $client = new FakeJevClient(static fn(string $name, $question): array => ['desiderio_pricingfaq', 0.34]);
        $match = $this->matchOne($this->faq(), $client);

        self::assertSame('desiderio_accordion', $match->chosen?->cType);
        self::assertTrue($match->needsReview);
        self::assertSame('desiderio_pricingfaq', $match->jev?->choice);
        self::assertFalse($match->jev->confident);
    }

    #[Test]
    public function clearPartsAreNeverSentToJev(): void
    {
        $client = new FakeJevClient(static fn(string $name, $question): array => ['header', 0.99]);
        $match = $this->matchOne([new Heading(2, [new Text('About us')]), self::p('Text.')], $client);

        self::assertSame('text', $match->chosen?->cType);
        self::assertSame([], $client->calls);
    }

    #[Test]
    public function htmlIsNeverProposed(): void
    {
        $match = $this->matchOne([self::p('<script>alert(1)</script>')]);

        self::assertNull($match->proposal('html'));
    }

    /**
     * @return list<Block>
     */
    private function faq(): array
    {
        return [
            new Heading(2, [new Text('Frequently asked questions')]),
            new Heading(3, [new Text('What does it cost?')]),
            self::p('It depends on the scope.'),
            new Heading(3, [new Text('How long does it take?')]),
            self::p('Usually two weeks.'),
        ];
    }

    /**
     * @param list<Block> $blocks
     */
    private function matchOne(array $blocks, ?FakeJevClient $client = null): PartMatch
    {
        $parts = new Segmenter(new PartAnalyzer())->segment($blocks);
        self::assertCount(1, $parts);

        return $this->matcher($client)->match($parts, LabShapes::candidates(), new MatchContext(7, languageTag: 'en-GB'))[0];
    }

    private function matcher(?FakeJevClient $client): ContentTypeMatcher
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['docx_editor']['pageSync'] = ['jevEnabled' => '1', 'jevConfidenceThreshold' => '0.6'];
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['webcon_jev'] = ['enabled' => '1', 'logRuns' => '0', 'cacheLifetime' => '0', 'maxCallsPerMinute' => '0'];
        $settings = new PageSyncSettings(new ExtensionConfiguration());

        $languageService = self::createStub(LanguageService::class);
        $languageService->method('sL')->willReturnArgument(0);
        $languageServiceFactory = self::createStub(LanguageServiceFactory::class);
        $languageServiceFactory->method('create')->willReturn($languageService);
        $shapes = new ElementShapeFactory(self::createStub(TcaSchemaFactory::class), new FieldRoleClassifier(), $languageServiceFactory);

        $runner = null;
        if ($client !== null) {
            $jevSettings = new Settings(new ExtensionConfiguration());
            $runner = new DecisionRunner(
                $client,
                new StateBuilder(),
                new RunLogger(new ConnectionPool(), $jevSettings),
                $jevSettings,
                new VariableFrontend('webcon_jev', new NullBackend()),
                new NullLogger(),
            );
        }
        $placer = new FieldPlacer();
        $dispatcher = new class implements EventDispatcherInterface {
            #[\Override]
            public function dispatch(object $event): object
            {
                return $event;
            }
        };

        return new ContentTypeMatcher(
            [new CoreContentTypeRule($placer), new StructuralRule($placer), new NameHintRule($placer)],
            $dispatcher,
            new JevContentTypeChooser($settings, $shapes, $runner),
            $settings,
        );
    }

    private static function chosen(PartMatch $match): Proposal
    {
        self::assertNotNull($match->chosen);

        return $match->chosen;
    }

    private static function text(mixed $value): string
    {
        return $value instanceof TextValue ? $value->text : '';
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

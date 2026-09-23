<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Functional\PageSync;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;
use Webconsulting\DocxEditor\PageSync\Apply\PlanApplier;
use Webconsulting\DocxEditor\PageSync\Document\ContentControl;
use Webconsulting\DocxEditor\PageSync\Document\ControlLock;
use Webconsulting\DocxEditor\PageSync\Document\DocxDocument;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\Link;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\PlainText;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Plan\PlanDecisions;
use Webconsulting\DocxEditor\Tests\Fixtures\PageSync\DocumentEditor;

/**
 * A link field is read-only in Word and shows what it points to; its stored value travels in
 * the manifest and is never written back.
 *
 * Page 3 holds buttons (tt_content 20–26) whose link fields point to a page, an e-mail address,
 * a URL with target and title, a file, a page that does not exist, a phone number, and nowhere.
 */
final class LinkFieldTest extends AbstractPageSyncTestCase
{
    private const array BUTTONS = [
        20 => ['Our services', 't3://page?uid=1'],
        21 => ['Write to us', 'mailto:office@example.com'],
        22 => ['TYPO3', 'https://typo3.org/ _blank - "The CMS"'],
        23 => ['Brochure', 't3://file?uid=%file%'],
        24 => ['Gone', 't3://page?uid=999'],
        25 => ['Call us', 'tel:+43123456'],
        26 => ['Nowhere', ''],
    ];

    protected array $testExtensionsToLoad = [
        'webconsulting/docx-editor',
        __DIR__ . '/../../Fixtures/PageSync/Extensions/pagesync_test',
        __DIR__ . '/../../Fixtures/PageSync/Extensions/pagesync_link_test',
    ];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $file = $this->get(StorageRepository::class)->findByUid(1)?->getFile('/pagesync/office.png');
        self::assertNotNull($file);
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('tt_content');
        foreach (self::BUTTONS as $uid => [$header, $link]) {
            $connection->insert('tt_content', [
                'uid' => $uid,
                'pid' => 3,
                'sorting' => $uid * 128,
                'CType' => 'pagesynctest_button',
                'header' => $header,
                'pagesynctest_link' => str_replace('%file%', (string)$file->getUid(), $link),
            ]);
        }
    }

    #[Test]
    public function aLinkFieldShowsWhatItPointsToAndKeepsItsTarget(): void
    {
        $exported = $this->export(3, 0, $this->backendUser(1));

        self::assertSame(['Page: Our services (/Our services/)', 't3://page?uid=1'], self::shown($exported, 20));
        self::assertSame(['E-mail: office@example.com', 'mailto:office@example.com'], self::shown($exported, 21));
        self::assertSame(['https://typo3.org/', 'https://typo3.org/'], self::shown($exported, 22));
        self::assertSame('File: office.png', self::shown($exported, 23)[0]);
        self::assertSame(['t3://page?uid=999 (not found, or not visible to you)', 't3://page?uid=999'], self::shown($exported, 24));
        self::assertSame('Phone: +43123456', self::shown($exported, 25)[0]);
        self::assertSame('Link — edited in TYPO3.', PlainText::ofBlocks(self::linkControl($exported, 26)->blocks));

        $control = self::linkControl($exported, 20);
        self::assertSame(ControlLock::SdtContentLocked, $control->lock);
        self::assertSame('Button link (read-only)', $control->alias);
    }

    #[Test]
    public function theStoredLinkTravelsInTheSignedManifest(): void
    {
        $manifest = $this->export(3, 0, $this->backendUser(1))->manifest;

        self::assertNotNull($manifest);
        self::assertTrue($manifest->trusted);
        $field = $manifest->record('tt_content', 22)?->field('pagesynctest_link');
        self::assertNotNull($field);
        self::assertSame('https://typo3.org/ _blank - "The CMS"', $field->value);
        self::assertSame('', $field->hash, 'Read-only: nothing to compare');
        self::assertNull($manifest->record('tt_content', 26)?->field('pagesynctest_link'), 'An empty link has nothing to keep');
    }

    #[Test]
    public function aTranslationNamesThePageInItsLanguage(): void
    {
        $exported = $this->export(3, 1, $this->backendUser(1));

        self::assertSame('Page: Unser Angebot (/Our services/)', self::shown($exported, 20)[0]);
    }

    #[Test]
    public function importingTheDocumentNeverWritesALinkField(): void
    {
        $user = $this->backendUser(1);
        $exported = $this->export(3, 0, $user);
        self::assertFalse($this->plan($exported, 3, 0, $user)->hasWrites(), 'An untouched export changes nothing');

        // The label typed over, and the heading changed: only the heading is written.
        $edited = new DocumentEditor($exported)
            ->replace('typo3:tt_content:20:header', [new Heading(2, [new Text('All our services')])])
            ->replace('typo3:tt_content:20:pagesynctest_link', [new Paragraph([new Link('https://example.com/', [new Text('Somewhere else')])])])
            ->document();
        $plan = $this->plan($edited, 3, 0, $user);
        $button = self::entryFor($plan, 20);
        self::assertSame(['header'], array_map(static fn($change): string => $change->field->name, $button->fields));

        $result = $this->get(PlanApplier::class)->apply($plan, new PlanDecisions(), $user);
        self::assertSame([], $result->errors);
        $row = $this->row('tt_content', 20);
        self::assertSame('All our services', $row['header']);
        self::assertSame('t3://page?uid=1', $row['pagesynctest_link']);
    }

    /**
     * What a link field's control shows, and the target its link points to.
     *
     * @return array{0: string, 1: string}
     */
    private static function shown(DocxDocument $document, int $uid): array
    {
        $paragraph = self::linkControl($document, $uid)->blocks[0] ?? null;
        self::assertInstanceOf(Paragraph::class, $paragraph);
        $link = $paragraph->inlines[0] ?? null;
        self::assertInstanceOf(Link::class, $link);

        return [PlainText::ofInlines($link->children), $link->href];
    }

    private static function linkControl(DocxDocument $document, int $uid): ContentControl
    {
        $control = self::control($document->blocks, 'typo3:tt_content:' . $uid . ':pagesynctest_link');
        self::assertNotNull($control);

        return $control;
    }
}

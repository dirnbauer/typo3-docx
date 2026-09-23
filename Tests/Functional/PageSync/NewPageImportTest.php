<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Functional\PageSync;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Document\DocxDocument;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\ListBlock;
use Webconsulting\DocxEditor\PageSync\Document\ListItem;
use Webconsulting\DocxEditor\PageSync\Document\PageBreak;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Exception\PageSyncException;
use Webconsulting\DocxEditor\PageSync\Plan\EntryAction;
use Webconsulting\DocxEditor\PageSync\Plan\PageSplit;
use Webconsulting\DocxEditor\PageSync\Plan\PlanDecisions;
use Webconsulting\DocxEditor\PageSync\Service\PageSyncService;

/**
 * A Word document that was never exported from TYPO3, imported as new pages.
 */
final class NewPageImportTest extends AbstractPageSyncTestCase
{
    #[Test]
    public function aDocumentBecomesAHiddenPageTitledByItsFirstHeadingOne(): void
    {
        $user = $this->backendUser(1);
        $service = $this->get(PageSyncService::class);

        $preview = $service->previewNewPages($this->document(self::careers()), 1, PageSplit::None, $user, false);

        self::assertCount(1, $preview->plans);
        $plan = $preview->plans[0];
        self::assertTrue($plan->newPage);
        self::assertSame('Careers', $plan->page?->title);
        self::assertSame(EntryAction::Create, $plan->page->action);
        self::assertNotSame([], $plan->entries);
        foreach ($plan->entries as $entry) {
            self::assertSame(EntryAction::Create, $entry->action);
            self::assertNotSame('', $entry->type);
        }

        $results = $service->apply($preview->id, new PlanDecisions(), $user);

        self::assertCount(1, $results);
        self::assertSame([], $results[0]->errors);
        $page = $this->row('pages', $results[0]->pageUid);
        self::assertSame(1, (int)$page['pid']);
        self::assertSame('Careers', $page['title']);
        self::assertSame(1, (int)$page['hidden'], 'New pages are created hidden by default');

        $elements = $this->elements($results[0]->pageUid);
        self::assertCount(count($plan->entries), $elements);
        $headers = array_column($elements, 'header');
        self::assertSame(['', 'Open positions', 'How to apply'], $headers, 'The introduction, then a section per heading 2, in document order');
        self::assertSame('bullets', $elements[2]['CType'], 'A heading with a list is a bullet list');
        self::assertStringContainsString('Frontend developer', (string)$elements[1]['bodytext']);
        foreach ($elements as $element) {
            self::assertSame(0, (int)$element['colPos']);
        }
    }

    #[Test]
    public function aDocumentSplitAtHeadingsOneBecomesOnePagePerHeading(): void
    {
        $user = $this->backendUser(1);
        $service = $this->get(PageSyncService::class);
        $blocks = [
            ...self::careers(),
            new Heading(1, [new Text('Imprint')]),
            new Paragraph([new Text('webconsulting, Vienna.')]),
        ];

        $preview = $service->previewNewPages($this->document($blocks), 1, PageSplit::Heading1, $user, false);
        self::assertSame(['Careers', 'Imprint'], array_map(static fn($plan): string => $plan->page->title ?? '', $preview->plans));

        $results = $service->apply($preview->id, new PlanDecisions(), $user);

        self::assertCount(2, $results);
        $pages = $this->subpages(1);
        self::assertSame(['Sub page', 'Careers', 'Imprint'], array_column($pages, 'title'), 'The new pages follow the existing ones, in document order');
        self::assertSame('webconsulting, Vienna.', strip_tags((string)$this->elements($results[1]->pageUid)[0]['bodytext']));
    }

    #[Test]
    public function aDocumentSplitAtPageBreaksTakesTitlesFromTheFirstHeadingOfEachPage(): void
    {
        $user = $this->backendUser(1);
        $blocks = [
            new Heading(2, [new Text('Team')]),
            new Paragraph([new Text('Twelve people.')]),
            new PageBreak(),
            new Heading(2, [new Text('History')]),
            new Paragraph([new Text('Since 2004.')]),
        ];

        $preview = $this->get(PageSyncService::class)->previewNewPages($this->document($blocks), 1, PageSplit::PageBreak, $user, false);

        self::assertSame(['Team', 'History'], array_map(static fn($plan): string => $plan->page->title ?? '', $preview->plans));
    }

    #[Test]
    public function aUserWhoMayNotCreatePagesGetsNoPlan(): void
    {
        $user = $this->backendUser(3);

        $this->expectException(PageSyncException::class);
        $this->expectExceptionCode(403);
        $this->get(PageSyncService::class)->previewNewPages($this->document(self::careers()), 1, PageSplit::None, $user, false);
    }

    /**
     * @return list<Block>
     */
    private static function careers(): array
    {
        return [
            new Heading(1, [new Text('Careers')]),
            new Paragraph([new Text('Join a small team that builds TYPO3 sites.')]),
            new Heading(2, [new Text('Open positions')]),
            new Paragraph([new Text('Frontend developer, full time, Vienna.')]),
            new Paragraph([new Text('Project manager, 30 hours, remote.')]),
            new Heading(2, [new Text('How to apply')]),
            new ListBlock([
                new ListItem(0, true, [new Text('Send your CV')]),
                new ListItem(0, true, [new Text('Meet the team')]),
                new ListItem(0, true, [new Text('Start')]),
            ]),
        ];
    }

    /**
     * @param list<Block> $blocks
     */
    private function document(array $blocks): string
    {
        return $this->binary(new DocxDocument($blocks));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function subpages(int $parent): array
    {
        $query = $this->get(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $query->getRestrictions()->removeAll();

        return $query->select('*')->from('pages')
            ->where(
                $query->expr()->eq('pid', $query->createNamedParameter($parent, Connection::PARAM_INT)),
                $query->expr()->eq('sys_language_uid', 0),
                $query->expr()->eq('deleted', 0),
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}

<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Functional\PageSync;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Exception\PageSyncException;
use Webconsulting\DocxEditor\PageSync\Plan\EntryAction;
use Webconsulting\DocxEditor\PageSync\Plan\PlanDecisions;
use Webconsulting\DocxEditor\PageSync\Plan\PlanEntry;
use Webconsulting\DocxEditor\PageSync\Service\PageSyncService;
use Webconsulting\DocxEditor\Tests\Fixtures\PageSync\DocumentEditor;

/**
 * Preview and apply are two requests: what is applied must be what the editor reviewed.
 */
final class PageSyncServiceTest extends AbstractPageSyncTestCase
{
    #[Test]
    public function applyWritesWhatThePreviewShowedAndForgetsThePreview(): void
    {
        $user = $this->backendUser(1);
        $service = $this->get(PageSyncService::class);

        $preview = $service->preview($this->editedExport($user), 1, 0, $user);
        self::assertSame(EntryAction::Update, self::entryAction($preview->plans[0]->entries, 2));
        $results = $service->apply($preview->id, new PlanDecisions(), $user);

        self::assertSame([2], $results[0]->updated);
        self::assertStringContainsString('We host websites too.', (string)$this->row('tt_content', 2)['bodytext']);

        $this->expectException(PageSyncException::class);
        $this->expectExceptionCode(404);
        $service->apply($preview->id, new PlanDecisions(), $user);
    }

    #[Test]
    public function applyRefusesWhenThePageChangedAfterThePreview(): void
    {
        $user = $this->backendUser(1);
        $service = $this->get(PageSyncService::class);
        $preview = $service->preview($this->editedExport($user), 1, 0, $user);

        // Someone else edits the element in TYPO3 while the preview is open.
        $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')
            ->update('tt_content', ['bodytext' => '<p>Changed by a colleague.</p>'], ['uid' => 2]);

        try {
            $service->apply($preview->id, new PlanDecisions(), $user);
            self::fail('The outdated plan was applied');
        } catch (PageSyncException $exception) {
            self::assertSame('error.planOutdated', $exception->labelKey);
            self::assertSame(409, $exception->getStatusCode());
        }
        self::assertSame('<p>Changed by a colleague.</p>', $this->row('tt_content', 2)['bodytext']);
    }

    #[Test]
    public function aPreviewBelongsToTheUserWhoMadeIt(): void
    {
        $service = $this->get(PageSyncService::class);
        $preview = $service->preview($this->editedExport($this->backendUser(1)), 1, 0, $this->backendUser(1));

        $this->expectException(PageSyncException::class);
        $this->expectExceptionCode(404);
        $service->apply($preview->id, new PlanDecisions(), $this->backendUser(2));
    }

    #[Test]
    public function aPreviewMadeInAnotherWorkspaceIsNotApplied(): void
    {
        $service = $this->get(PageSyncService::class);
        $preview = $service->preview($this->editedExport($this->backendUser(1)), 1, 0, $this->backendUser(1));

        $this->expectException(PageSyncException::class);
        $this->expectExceptionCode(409);
        $service->apply($preview->id, new PlanDecisions(), $this->backendUser(1, 1));
    }

    private function editedExport(BackendUserAuthentication $user): string
    {
        $exported = $this->read($this->get(PageSyncService::class)->export(1, 0, $user)->binary);

        return $this->binary(new DocumentEditor($exported)
            ->replace('typo3:tt_content:2:bodytext', [new Paragraph([new Text('We host websites too.')])])
            ->document());
    }

    /**
     * @param list<PlanEntry> $entries
     */
    private static function entryAction(array $entries, int $uid): ?EntryAction
    {
        foreach ($entries as $entry) {
            if ($entry->table === 'tt_content' && $entry->uid === $uid) {
                return $entry->action;
            }
        }

        return null;
    }
}

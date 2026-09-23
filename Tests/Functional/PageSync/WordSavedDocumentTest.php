<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Functional\PageSync;

use PHPUnit\Framework\Attributes\Test;
use Webconsulting\DocxEditor\PageSync\Plan\EntryAction;
use Webconsulting\DocxEditor\PageSync\Plan\FieldStatus;
use Webconsulting\DocxEditor\PageSync\Plan\PlanDecisions;
use Webconsulting\DocxEditor\PageSync\Plan\PlanEntry;
use Webconsulting\DocxEditor\PageSync\Plan\SyncPlan;
use Webconsulting\DocxEditor\PageSync\Service\PageSyncService;

/**
 * A document exported from the fixture page, opened in Microsoft Word for Mac (16.x), edited with
 * Find & Replace ("Welcome" → "Welcome to webconsulting", "Visit us in Vienna." → "Visit us in
 * Graz, too.") and saved by Word. Word rewrites the whole package — styles, settings, theme, fonts,
 * the custom XML part — so this proves the round trip survives the real thing, not just our writer.
 */
final class WordSavedDocumentTest extends AbstractPageSyncTestCase
{
    private const string DOCUMENT = self::FIXTURES . '/Word/exported-then-edited-in-word.docx';

    #[Test]
    public function wordKeepsTheControlsAndTheManifestSoOnlyTheEditedFieldsChange(): void
    {
        $user = $this->backendUser(1);
        $service = $this->get(PageSyncService::class);

        $preview = $service->preview((string)file_get_contents(self::DOCUMENT), 1, 0, $user, false);
        $plan = $preview->plans[0];

        self::assertTrue($plan->manifestFound);
        self::assertTrue($plan->manifestTrusted, 'Word keeps the custom XML part intact');
        self::assertSame([], $plan->moves);
        $changed = [];
        foreach ($plan->entries as $entry) {
            self::assertNotSame(EntryAction::Create, $entry->action, 'Nothing is new: ' . $entry->title);
            self::assertNotSame(EntryAction::Delete, $entry->action, 'Nothing is removed: ' . $entry->title);
            foreach ($entry->fields as $change) {
                if ($change->status !== FieldStatus::Unchanged && $change->status !== FieldStatus::ReadOnly) {
                    $changed[] = $entry->table . ':' . $entry->uid . ':' . $change->field->name . '=' . $change->status->value;
                }
            }
        }
        self::assertSame(['tt_content:1:header=changed', 'tt_content:3:bodytext=changed'], $changed);
        self::assertSame(EntryAction::Unchanged, self::entry($plan, 6)->action, 'The accordion and its items came through untouched');

        $results = $service->apply($preview->id, new PlanDecisions(), $user);

        self::assertSame([], $results[0]->errors);
        self::assertSame('Welcome to webconsulting', $this->row('tt_content', 1)['header']);
        self::assertSame('<p>Visit us in Graz, too.</p>', $this->row('tt_content', 3)['bodytext']);
        self::assertSame(3, (int)$this->row('tt_content', 3)['header_layout'], 'The heading level Word kept is not a change');
    }

    private static function entry(SyncPlan $plan, int $uid): PlanEntry
    {
        foreach ($plan->entries as $entry) {
            if ($entry->table === 'tt_content' && $entry->uid === $uid) {
                return $entry;
            }
        }
        self::fail('No plan entry for tt_content:' . $uid);
    }
}

<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Functional\PageSync;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Webconsulting\DocxEditor\PageSync\Apply\PlanApplier;
use Webconsulting\DocxEditor\PageSync\Document\Figure;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\Image;
use Webconsulting\DocxEditor\PageSync\Document\ImageData;
use Webconsulting\DocxEditor\PageSync\Document\Link;
use Webconsulting\DocxEditor\PageSync\Document\ListBlock;
use Webconsulting\DocxEditor\PageSync\Document\ListItem;
use Webconsulting\DocxEditor\PageSync\Document\Marks;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\Table;
use Webconsulting\DocxEditor\PageSync\Document\TableCell;
use Webconsulting\DocxEditor\PageSync\Document\TableRow;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Plan\EntryAction;
use Webconsulting\DocxEditor\PageSync\Plan\FieldStatus;
use Webconsulting\DocxEditor\PageSync\Plan\PlanDecisions;
use Webconsulting\DocxEditor\PageSync\Plan\PlanEntry;
use Webconsulting\DocxEditor\Tests\Fixtures\PageSync\DocumentEditor;
use Webconsulting\DocxEditor\Tests\Fixtures\PageSync\DocxFixtureBuilder;

final class PageRoundTripTest extends AbstractPageSyncTestCase
{
    #[Test]
    public function wordEditsComeBackAsExactlyTheChangedFieldsOfTheRightRecords(): void
    {
        $user = $this->backendUser(1);
        $exported = $this->export(1, 0, $user);

        $edited = new DocumentEditor($exported)
            // Text edited in an RTE field, and a heading edited and raised from level 3 to 2.
            ->replace('typo3:tt_content:2:bodytext', [
                new Paragraph([new Text('We plan, build and '), new Text('host', new Marks(bold: true)), new Text(' websites.')]),
                new ListBlock([new ListItem(0, false, [new Text('Plan')]), new ListItem(0, false, [new Text('Build')]), new ListItem(0, false, [new Text('Host')])]),
            ])
            ->replace('typo3:tt_content:3:header', [new Heading(2, [new Text('Our Vienna office')])])
            // A collection item changed, and a new one typed below the last.
            ->replace('typo3:tx_pagesynctest_item:1:content', [new Paragraph([new Text('It depends on scope and timeline.')])])
            ->append('typo3:tt_content:6:pagesynctest_items', [
                new Heading(3, [new Text('Do you offer hosting?')]),
                new Paragraph([new Text('Yes, in Austria.')]),
            ])
            // The quote moved to the top, the table deleted.
            ->move('typo3:tt_content:7', 'typo3:tt_content:1')
            ->remove('typo3:tt_content:4')
            // A new section after the office, and a table and a picture at the end.
            ->insertAfter('typo3:tt_content:3', [
                new Heading(2, [new Text('Contact')]),
                new Paragraph([new Text('Write to us any time.')]),
                new Paragraph([new Link('mailto:office@example.com', [new Text('office@example.com')])]),
            ])
            ->appendToDocument([
                new Heading(2, [new Text('Opening hours')]),
                new Table([
                    new TableRow([new TableCell([new Paragraph([new Text('Day')])]), new TableCell([new Paragraph([new Text('Hours')])])], true),
                    new TableRow([new TableCell([new Paragraph([new Text('Mon–Fri')])]), new TableCell([new Paragraph([new Text('9–17')])])]),
                ]),
                new Heading(2, [new Text('Our team')]),
                new Paragraph([new Text('Twelve people who care.')]),
                new Figure(new Image(new ImageData(DocxFixtureBuilder::png(200, 30, 30, 20, 20), 'image/png', 'team.png'), 'The team at the office')),
            ])
            ->document();

        $plan = $this->plan($edited, 1, 0, $user);

        self::assertTrue($plan->manifestTrusted);
        $text = self::entryFor($plan, 2);
        self::assertSame(EntryAction::Update, $text->action);
        self::assertSame(['bodytext'], array_map(static fn($change): string => $change->field->name, $text->fields));
        self::assertSame(FieldStatus::Changed, $text->fields[0]->status);

        $office = self::entryFor($plan, 3);
        self::assertSame(EntryAction::Update, $office->action);
        self::assertSame(['header'], array_map(static fn($change): string => $change->field->name, $office->fields));
        self::assertSame(2, $office->fields[0]->update->settings['header_layout'] ?? null);

        $accordion = self::entryFor($plan, 6);
        self::assertSame(EntryAction::Update, $accordion->action);
        self::assertSame([EntryAction::Update, EntryAction::Unchanged, EntryAction::Create], array_map(static fn(PlanEntry $child): EntryAction => $child->action, $accordion->children));

        self::assertSame(EntryAction::Unchanged, self::entryFor($plan, 1)->action);
        self::assertSame(EntryAction::ReadOnly, self::entryFor($plan, 5)->action);
        self::assertSame(EntryAction::Delete, self::entryFor($plan, 4)->action);
        self::assertSame([7], array_map(static fn($move): int => $move->uid, $plan->moves));

        $created = array_values(array_filter($plan->entries, static fn(PlanEntry $entry): bool => $entry->action === EntryAction::Create));
        self::assertSame(['Contact', 'Opening hours', 'Our team'], array_map(static fn(PlanEntry $entry): string => $entry->title, $created));
        self::assertSame(['text', 'table', 'textmedia'], array_map(static fn(PlanEntry $entry): string => $entry->type, $created));
        self::assertSame(3, $created[0]->afterUid, 'The contact section follows the office');

        $result = $this->get(PlanApplier::class)->apply($plan, new PlanDecisions(confirmedDeletions: [self::entryFor($plan, 4)->id]), $user);
        self::assertSame([], $result->errors);
        self::assertCount(3, $result->created);

        // Stored as TYPO3's RTE transformation writes it: blocks separated by CRLF.
        self::assertSame('<p>We plan, build and <strong>host</strong> websites.</p>' . "\r\n" . '<ul><li>Plan</li><li>Build</li><li>Host</li></ul>', $this->row('tt_content', 2)['bodytext']);
        $office = $this->row('tt_content', 3);
        self::assertSame('Our Vienna office', $office['header']);
        self::assertSame(2, (int)$office['header_layout']);
        self::assertSame('<p>Visit us in Vienna.</p>', $office['bodytext'], 'Fields not changed in Word are not written');
        self::assertSame(1, (int)$this->row('tt_content', 4)['deleted']);
        self::assertSame('<p>It depends on scope and timeline.</p>', $this->row('tx_pagesynctest_item', 1)['content']);
        $items = $this->children(6);
        self::assertSame(['What does it cost?', 'How long does it take?', 'Do you offer hosting?'], array_column($items, 'title'));
        self::assertSame('<p>Yes, in Austria.</p>', $items[2]['content']);

        $order = array_map(static fn(array $row): string => $row['CType'] . ':' . ($row['header'] ?: $row['quote_text']), $this->elements(1));
        self::assertSame([
            'pagesynctest_quote:They understood us.',
            'header:Welcome',
            'text:About us',
            'textmedia:Our Vienna office',
            'text:Contact',
            'pagesynctest_plugin:Upcoming events',
            'pagesynctest_accordion:FAQ',
            'table:Opening hours',
            'textmedia:Our team',
        ], $order);

        $contact = $this->row('tt_content', $result->created[$created[0]->id]);
        self::assertSame('<p>Write to us any time.</p>' . "\r\n" . '<p><a href="mailto:office@example.com">office@example.com</a></p>', $contact['bodytext']);
        $hours = $this->row('tt_content', $result->created[$created[1]->id]);
        self::assertSame("Day|Hours\nMon–Fri|9–17", $hours['bodytext']);
        self::assertSame(1, (int)$hours['table_header_position']);
        $team = $this->row('tt_content', $result->created[$created[2]->id]);
        self::assertSame(1, (int)$team['assets']);
        $reference = $this->references('tt_content', (int)$team['uid'], 'assets')[0] ?? [];
        self::assertSame('The team at the office', $reference['alternative'] ?? null);
        $file = $this->row('sys_file', (int)($reference['uid_local'] ?? 0));
        self::assertStringStartsWith('/user_upload/word/1/the-team-at-the-office-', (string)$file['identifier']);

        // Exported again, the page reads as the edited document did, and importing it changes nothing.
        $again = $this->export(1, 0, $user);
        $replan = $this->plan($again, 1, 0, $user);
        self::assertFalse($replan->hasWrites(), 'A fresh export imports without any change');
        $bodytext = self::control($again->blocks, 'typo3:tt_content:2:bodytext');
        self::assertNotNull($bodytext);
        self::assertInstanceOf(ListBlock::class, $bodytext->blocks[1]);
    }

    #[Test]
    public function aFieldChangedInTypo3AndInWordIsAConflictAndTypo3OnlyChangesAreKept(): void
    {
        $user = $this->backendUser(1);
        $exported = $this->export(1, 0, $user);

        // Another editor changes two fields in TYPO3 after the export.
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('tt_content');
        $connection->update('tt_content', ['bodytext' => '<p>Changed in TYPO3.</p>'], ['uid' => 2]);
        $connection->update('tt_content', ['header' => 'Welcome back'], ['uid' => 1]);

        $edited = new DocumentEditor($exported)
            ->replace('typo3:tt_content:2:bodytext', [new Paragraph([new Text('Changed in Word.')])])
            ->document();
        $plan = $this->plan($edited, 1, 0, $user);

        $text = self::entryFor($plan, 2);
        self::assertSame(EntryAction::Conflict, $text->action);
        self::assertSame(FieldStatus::Conflict, $text->fields[0]->status);
        self::assertSame('Changed in Word.', $text->fields[0]->wordPreview);
        self::assertSame('Changed in TYPO3.', $text->fields[0]->typo3Preview);
        $header = self::entryFor($plan, 1);
        self::assertSame(FieldStatus::ChangedInTypo3, $header->fields[0]->status);
        self::assertSame(EntryAction::Unchanged, $header->action);

        // Undecided, a conflict keeps TYPO3's value …
        $this->get(PlanApplier::class)->apply($plan, new PlanDecisions(), $user);
        self::assertSame('<p>Changed in TYPO3.</p>', $this->row('tt_content', 2)['bodytext']);
        self::assertSame('Welcome back', $this->row('tt_content', 1)['header']);

        // … and Word wins where the editor says so.
        $this->get(PlanApplier::class)->apply($plan, new PlanDecisions(resolutions: [$text->id => ['bodytext' => PlanDecisions::WORD]]), $user);
        self::assertSame('<p>Changed in Word.</p>', $this->row('tt_content', 2)['bodytext']);
    }

    #[Test]
    public function inAWorkspaceTheImportWritesDraftsAndLeavesLiveAlone(): void
    {
        $user = $this->backendUser(1, 1);
        $exported = $this->export(1, 0, $user);
        $edited = new DocumentEditor($exported)
            ->replace('typo3:tt_content:2:bodytext', [new Paragraph([new Text('Draft text.')])])
            ->appendToDocument([new Heading(2, [new Text('Draft section')]), new Paragraph([new Text('Only in the workspace.')])])
            ->document();
        $plan = $this->plan($edited, 1, 0, $user);
        self::assertSame(1, $plan->workspaceId);

        $result = $this->get(PlanApplier::class)->apply($plan, new PlanDecisions(), $user);
        self::assertSame([], $result->errors);

        self::assertStringContainsString('websites', (string)$this->row('tt_content', 2)['bodytext'], 'Live is untouched');
        $version = $this->workspaceVersion(2, 1);
        self::assertSame('<p>Draft text.</p>', $version['bodytext'] ?? null);
        $new = $this->row('tt_content', array_values($result->created)[0]);
        self::assertSame(1, (int)$new['t3ver_wsid'], 'A new element is a workspace record');
        self::assertSame('Draft section', $new['header']);
    }

    #[Test]
    public function aTranslationIsWrittenAsTranslatedRecords(): void
    {
        $user = $this->backendUser(1);
        $exported = $this->export(1, 1, $user);
        $edited = new DocumentEditor($exported)
            ->replace('typo3:pages:2:title', [new Heading(1, [new Text('Unsere Leistungen')])])
            ->replace('typo3:tt_content:2:bodytext', [new Paragraph([new Text('Wir bauen Websites mit TYPO3.')])])
            ->appendToDocument([new Heading(2, [new Text('Nur auf Deutsch')]), new Paragraph([new Text('Neuer Abschnitt.')])])
            ->document();
        $plan = $this->plan($edited, 1, 1, $user);

        self::assertSame(EntryAction::Update, $plan->page?->action);
        self::assertSame(EntryAction::Translate, self::entryFor($plan, 2)->action);
        $skipped = array_values(array_filter($plan->entries, static fn(PlanEntry $entry): bool => $entry->action === EntryAction::Skip));
        self::assertCount(1, $skipped, 'New content cannot be added to a connected translation');
        self::assertSame('plan.message.connectedTranslation', $skipped[0]->messages[0]->key);

        $result = $this->get(PlanApplier::class)->apply($plan, new PlanDecisions(), $user);
        self::assertSame([], $result->errors);
        self::assertSame('Unsere Leistungen', $this->row('pages', 2)['title']);
        $translation = $this->row('tt_content', (int)array_values($result->translated)[0]);
        self::assertSame(1, (int)$translation['sys_language_uid']);
        self::assertSame(2, (int)$translation['l18n_parent']);
        self::assertSame('<p>Wir bauen Websites mit TYPO3.</p>', $translation['bodytext']);
        self::assertStringContainsString('websites', (string)$this->row('tt_content', 2)['bodytext'], 'The default language stays');
    }

    #[Test]
    public function aUserWhoMayNotEditThePageGetsNoPlan(): void
    {
        $exported = $this->export(1, 0, $this->backendUser(1));

        $this->expectExceptionCode(403);
        $this->plan($exported, 1, 0, $this->backendUser(3));
    }

    #[Test]
    public function anElementWhoseContentControlWasRemovedIsRecognisedByItsBookmark(): void
    {
        $user = $this->backendUser(1);
        $exported = $this->export(1, 0, $user);
        $edited = new DocumentEditor($exported)
            ->replace('typo3:tt_content:2:bodytext', [new Paragraph([new Text('Unwrapped, but still ours.')])])
            ->unwrap('typo3:tt_content:2:header')
            ->unwrap('typo3:tt_content:2:bodytext')
            ->unwrap('typo3:tt_content:2')
            ->document();
        $plan = $this->plan($edited, 1, 0, $user);

        $text = self::entryFor($plan, 2);
        self::assertSame('bookmark', $text->recognisedBy);
        self::assertSame(EntryAction::Update, $text->action);
        self::assertSame(['bodytext'], array_map(static fn($change): string => $change->field->name, $text->fields));
        self::assertSame([], array_values(array_filter($plan->entries, static fn(PlanEntry $entry): bool => $entry->action === EntryAction::Create)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function children(int $parent): array
    {
        $query = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tx_pagesynctest_item');
        $query->getRestrictions()->removeAll();

        return $query->select('*')->from('tx_pagesynctest_item')
            ->where(
                $query->expr()->eq('foreign_table_parent_uid', $query->createNamedParameter($parent, Connection::PARAM_INT)),
                $query->expr()->eq('deleted', 0),
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<string, mixed>
     */
    private function workspaceVersion(int $uid, int $workspace): array
    {
        $query = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $query->getRestrictions()->removeAll();
        $row = $query->select('*')->from('tt_content')
            ->where(
                $query->expr()->eq('t3ver_oid', $query->createNamedParameter($uid, Connection::PARAM_INT)),
                $query->expr()->eq('t3ver_wsid', $query->createNamedParameter($workspace, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row, 'No workspace version of tt_content:' . $uid);

        return $row;
    }
}

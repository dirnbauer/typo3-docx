<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Functional\PageSync;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Webconsulting\DocxEditor\PageSync\Document\Figure;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\Image;
use Webconsulting\DocxEditor\PageSync\Document\ImageData;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Matching\PartMatch;
use Webconsulting\DocxEditor\PageSync\Plan\EntryAction;
use Webconsulting\DocxEditor\PageSync\Plan\PlanDecisions;
use Webconsulting\DocxEditor\PageSync\Plan\PlanEntry;
use Webconsulting\DocxEditor\PageSync\Plan\SyncPlan;
use Webconsulting\DocxEditor\PageSync\Service\PageSyncService;
use Webconsulting\DocxEditor\Tests\Fixtures\PageSync\DocumentEditor;
use Webconsulting\DocxEditor\Tests\Fixtures\PageSync\DocxFixtureBuilder;
use Webconsulting\DocxEditor\Tests\Fixtures\PageSync\FakeJevClient;
use Webconsulting\WebconJev\Client\Dto\Question;

/**
 * With webcon_jev installed, Jev chooses among the content types that fit a new part about
 * equally well — through webcon_jev's own DecisionRunner (switch, cache, budget, run log), with
 * TypeSafe's API replaced by a scripted client.
 */
final class JevContentTypeTest extends AbstractPageSyncTestCase
{
    protected array $testExtensionsToLoad = [
        'webconsulting/docx-editor',
        'netresearch/nr-vault',
        'webconsulting/webcon-jev',
        __DIR__ . '/../../Fixtures/PageSync/Extensions/pagesync_test',
        __DIR__ . '/../../Fixtures/PageSync/Extensions/pagesync_jev_test',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'docx_editor' => [
                'pageSync' => [
                    'jevEnabled' => '1',
                    'jevConfidenceThreshold' => '0.6',
                ],
            ],
            'webcon_jev' => [
                'enabled' => '1',
                'logRuns' => '1',
                'cacheLifetime' => '0',
            ],
        ],
    ];

    #[Test]
    public function aConfidentAnswerDecidesAndIsKeptUntilTheImportIsApplied(): void
    {
        $user = $this->backendUser(1);
        $client = $this->get(FakeJevClient::class);
        $client->answerWith(static fn(string $name, Question $question): array => [
            array_key_exists('textpic', $question->criteria) ? 'textpic' : (string)array_key_first($question->criteria),
            0.87,
        ]);
        $service = $this->get(PageSyncService::class);

        $preview = $service->preview($this->documentWithNewSection($user), 1, 0, $user);

        $entry = self::created($preview->plans[0]);
        self::assertNotNull($entry->match);
        self::assertSame(PartMatch::BY_JEV, $entry->match->decidedBy);
        self::assertSame('textpic', $entry->type);
        self::assertEqualsWithDelta(0.87, $entry->match->jev->confidence ?? 0.0, 0.001);
        self::assertCount(1, $client->calls, 'One request for the whole document');
        $question = array_values($client->calls[0]['questions'])[0];
        self::assertArrayHasKey('textmedia', $question->criteria, 'The structurally best type is among the options');
        self::assertGreaterThanOrEqual(2, count($question->criteria));

        $run = $this->lastRun();
        self::assertSame('docx_editor_page_import', $run['context']);
        self::assertSame('docx_editor.content_type', $run['decision_identifier']);
        self::assertSame(0, (int)$run['decision'], 'A transient decision is logged under uid 0');
        self::assertSame(0, (int)$run['is_fallback']);

        // Jev would now answer differently — the reviewed choice still wins, and Jev is not asked again.
        $client->answerWith(static fn(string $name, Question $question): array => ['textmedia', 0.99]);
        $results = $service->apply($preview->id, new PlanDecisions(), $user);

        self::assertCount(1, $client->calls);
        $uid = array_values($results[0]->created)[0] ?? 0;
        self::assertSame('textpic', $this->row('tt_content', $uid)['CType']);
    }

    #[Test]
    public function anUnsureAnswerKeepsTheStructuralChoiceAndAsksForReview(): void
    {
        $user = $this->backendUser(1);
        $this->get(FakeJevClient::class)->answerWith(static fn(string $name, Question $question): array => [
            array_key_exists('textpic', $question->criteria) ? 'textpic' : (string)array_key_first($question->criteria),
            0.35,
        ]);

        $preview = $this->get(PageSyncService::class)->preview($this->documentWithNewSection($user), 1, 0, $user);

        $entry = self::created($preview->plans[0]);
        self::assertNotNull($entry->match);
        self::assertSame(PartMatch::BY_STRUCTURE, $entry->match->decidedBy);
        self::assertSame('textmedia', $entry->type);
        self::assertTrue($entry->match->needsReview);
        self::assertFalse($entry->match->jev->confident ?? true);
    }

    private function documentWithNewSection(BackendUserAuthentication $user): string
    {
        $exported = $this->read($this->get(PageSyncService::class)->export(1, 0, $user)->binary);

        return $this->binary(new DocumentEditor($exported)
            ->insertAfter('typo3:tt_content:3', [
                new Heading(2, [new Text('Contact')]),
                new Paragraph([new Text('Write to us any time, we answer within a day.')]),
                new Figure(new Image(new ImageData(DocxFixtureBuilder::png(30, 120, 60, 24, 16), 'image/png', 'map.png'), 'How to find us')),
            ])
            ->document());
    }

    private static function created(SyncPlan $plan): PlanEntry
    {
        foreach ($plan->entries as $entry) {
            if ($entry->action === EntryAction::Create) {
                return $entry;
            }
        }
        self::fail('The plan creates nothing');
    }

    /**
     * @return array<string, mixed>
     */
    private function lastRun(): array
    {
        $row = $this->get(ConnectionPool::class)->getConnectionForTable('tx_webconjev_run')
            ->select(['*'], 'tx_webconjev_run', [], [], ['uid' => 'DESC'], 1)
            ->fetchAssociative();
        self::assertIsArray($row, 'The run was not logged');

        return $row;
    }
}

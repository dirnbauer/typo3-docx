<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Functional\PageSync;

use PHPUnit\Framework\Attributes\Test;
use Webconsulting\DocxEditor\PageSync\Document\Figure;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\Image;
use Webconsulting\DocxEditor\PageSync\Document\ImageData;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Matching\PartMatch;
use Webconsulting\DocxEditor\PageSync\Plan\EntryAction;
use Webconsulting\DocxEditor\PageSync\Service\PageSyncService;
use Webconsulting\DocxEditor\Tests\Fixtures\PageSync\DocumentEditor;
use Webconsulting\DocxEditor\Tests\Fixtures\PageSync\DocxFixtureBuilder;

/**
 * webcon_jev is optional: with Jev switched on but the extension not installed, the import boots
 * and chooses by structure, saying why Jev was not asked.
 */
final class WithoutJevTest extends AbstractPageSyncTestCase
{
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'docx_editor' => [
                'pageSync' => [
                    'jevEnabled' => '1',
                ],
            ],
        ],
    ];

    #[Test]
    public function newContentIsMatchedByStructureAlone(): void
    {
        $user = $this->backendUser(1);
        $exported = $this->read($this->get(PageSyncService::class)->export(1, 0, $user)->binary);
        $binary = $this->binary(new DocumentEditor($exported)
            ->insertAfter('typo3:tt_content:3', [
                new Heading(2, [new Text('Contact')]),
                new Paragraph([new Text('Write to us any time, we answer within a day.')]),
                new Figure(new Image(new ImageData(DocxFixtureBuilder::png(30, 120, 60, 24, 16), 'image/png', 'map.png'), 'How to find us')),
            ])
            ->document());

        $plan = $this->get(PageSyncService::class)->preview($binary, 1, 0, $user)->plans[0];

        $created = array_values(array_filter($plan->entries, static fn($entry): bool => $entry->action === EntryAction::Create));
        self::assertCount(1, $created);
        // Text & Media and Text & Images fit about equally well: Jev would be asked.
        self::assertSame('textmedia', $created[0]->type);
        self::assertSame(PartMatch::BY_STRUCTURE, $created[0]->match?->decidedBy);
        self::assertTrue($created[0]->match->needsReview);
        self::assertSame('webcon_jev is not installed', $created[0]->match->jev?->fallbackReason);
    }
}

<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\DocxEditor\Service\CollaborationSessionService;

final class CollaborationSessionServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = ['webconsulting/docx-editor'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
    }

    #[Test]
    public function joiningTwiceReusesTheSessionAndLeavingRemovesPresence(): void
    {
        $this->setUpBackendUser(2);
        $service = $this->get(CollaborationSessionService::class);
        $fileHash = hash('sha256', '1:/user_upload/example.docx');

        $sessionUid = $service->join($fileHash, '1:/user_upload/example.docx');
        self::assertSame($sessionUid, $service->join($fileHash, '1:/user_upload/example.docx'));

        $participants = $service->getActiveParticipants($fileHash);
        self::assertCount(1, $participants);
        self::assertSame(2, $participants[0]['userId']);
        self::assertSame('Erika Musterfrau', $participants[0]['userName']);
        self::assertSame((int)$sessionUid, $participants[0]['sessionUid']);
        self::assertSame([], $service->getActiveParticipants('other-file'));

        $service->leave($fileHash, (int)$sessionUid);
        self::assertSame([], $service->getActiveParticipants($fileHash));
    }
}

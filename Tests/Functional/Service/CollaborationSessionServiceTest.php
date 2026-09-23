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

    #[\Override]
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
        self::assertIsInt($service->join($fileHash, '1:/user_upload/example.docx'));

        $sessionUid = $service->join($fileHash, '1:/user_upload/example.docx');
        self::assertSame($sessionUid, $service->join($fileHash, '1:/user_upload/example.docx'));

        $participants = $service->getActiveParticipants($fileHash);
        self::assertCount(1, $participants);
        self::assertSame(2, $participants[0]['userId']);
        self::assertSame('Erika Musterfrau', $participants[0]['userName']);
        self::assertSame($sessionUid, $participants[0]['sessionUid']);
        self::assertSame([], $service->getActiveParticipants('other-file'));

        $service->leave($fileHash, $sessionUid);
        self::assertSame([], $service->getActiveParticipants($fileHash));
    }

    #[Test]
    public function sessionsWithoutRecentHeartbeatAreDroppedAndPurged(): void
    {
        $this->setUpBackendUser(1);
        $service = $this->get(CollaborationSessionService::class);
        $fileHash = hash('sha256', '1:/user_upload/stale.docx');
        $sessionUid = $service->join($fileHash, '1:/user_upload/stale.docx');

        $connection = $this->getConnectionPool()->getConnectionForTable('tx_docx_editor_session');
        $connection->update('tx_docx_editor_session', ['last_heartbeat' => time() - 3600], ['uid' => $sessionUid]);

        self::assertSame([], $service->getActiveParticipants($fileHash));
        self::assertSame(0, $connection->count('*', 'tx_docx_editor_session', ['uid' => $sessionUid]));
    }
}

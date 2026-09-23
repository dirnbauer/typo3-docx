<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Functional\Controller\Backend;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Webconsulting\DocxEditor\Controller\Backend\CollaborationApiController;
use Webconsulting\DocxEditor\Service\RevisionService;
use Webconsulting\DocxEditor\Tests\Functional\AbstractBackendRouteTestCase;

final class CollaborationApiControllerTest extends AbstractBackendRouteTestCase
{
    #[Test]
    public function joinHeartbeatPresenceAndLeaveTrackWhoIsEditing(): void
    {
        $joined = self::json($this->post(2, 'join', ['file' => self::DOCX]));
        self::assertTrue($joined['ok']);
        self::assertIsInt($joined['sessionUid']);
        self::assertSame([['userId' => 2, 'userName' => 'Erika Musterfrau', 'sessionUid' => $joined['sessionUid']]], $joined['participants']);

        $beat = self::json($this->post(2, 'heartbeat', ['file' => self::DOCX, 'sessionUid' => $joined['sessionUid']]));
        self::assertCount(1, $beat['participants']);

        $seenByOther = self::json($this->controller()->presenceAction(
            $this->ajaxRequest(1, 'docx_editor_collab_presence', ['file' => self::DOCX]),
        ));
        self::assertSame(2, $seenByOther['participants'][0]['userId']);

        self::assertTrue(self::json($this->post(2, 'leave', ['file' => self::DOCX, 'sessionUid' => $joined['sessionUid']]))['ok']);
        $afterLeave = self::json($this->controller()->presenceAction(
            $this->ajaxRequest(1, 'docx_editor_collab_presence', ['file' => self::DOCX]),
        ));
        self::assertSame([], $afterLeave['participants']);
    }

    #[Test]
    public function heartbeatWithoutASessionIsRejected(): void
    {
        $response = $this->post(1, 'heartbeat', ['file' => self::DOCX]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('The editing session is missing.', self::json($response)['error']);
    }

    #[Test]
    public function revisionReportsTheLatestSaveOfTheFile(): void
    {
        $initial = self::json($this->controller()->revisionAction(
            $this->ajaxRequest(1, 'docx_editor_collab_revision', ['file' => self::DOCX]),
        ));
        self::assertSame(['ok' => true, 'revision' => 0, 'contentHash' => '', 'savedBy' => 0, 'changedAt' => 0], $initial);

        $this->get(RevisionService::class)->registerSave(self::DOCX, 'hash-1', 2);

        $afterSave = self::json($this->controller()->revisionAction(
            $this->ajaxRequest(1, 'docx_editor_collab_revision', ['file' => self::DOCX]),
        ));
        self::assertSame(1, $afterSave['revision']);
        self::assertSame('hash-1', $afterSave['contentHash']);
        self::assertSame(2, $afterSave['savedBy']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(int $backendUserUid, string $action, array $body): ResponseInterface
    {
        $request = $this->ajaxRequest($backendUserUid, 'docx_editor_collab_' . $action, [], $body);

        return $this->controller()->{$action . 'Action'}($request);
    }

    private function controller(): CollaborationApiController
    {
        return $this->get(CollaborationApiController::class);
    }
}

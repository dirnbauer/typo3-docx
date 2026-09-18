<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Resource\File;
use Webconsulting\DocxEditor\Exception\DocxEditorException;
use Webconsulting\DocxEditor\Service\CollaborationSessionService;
use Webconsulting\DocxEditor\Service\DocxFileService;
use Webconsulting\DocxEditor\Service\RevisionService;

/**
 * Presence (who is editing) and revision polling for one .docx file.
 */
final readonly class CollaborationApiController extends AbstractDocxApiController
{
    public function __construct(
        private DocxFileService $docxFileService,
        private CollaborationSessionService $collaborationSessionService,
        private RevisionService $revisionService,
    ) {}

    public function joinAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond(function () use ($request): array {
            $body = $this->parseRequestPayload($request);
            $file = $this->readableFile($this->stringValue($body, 'file'));
            $fileHash = $this->docxFileService->getFileHash($file);

            return [
                'sessionUid' => $this->collaborationSessionService->join($fileHash, $file->getCombinedIdentifier()),
                'participants' => $this->collaborationSessionService->getActiveParticipants($fileHash),
            ];
        });
    }

    public function heartbeatAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond(function () use ($request): array {
            $body = $this->parseRequestPayload($request);
            $file = $this->readableFile($this->stringValue($body, 'file'));
            $sessionUid = $this->intValue($body, 'sessionUid');
            if ($sessionUid <= 0) {
                throw new DocxEditorException('Missing session.', 400);
            }
            $fileHash = $this->docxFileService->getFileHash($file);
            $this->collaborationSessionService->heartbeat($fileHash, $sessionUid);

            return ['participants' => $this->collaborationSessionService->getActiveParticipants($fileHash)];
        });
    }

    public function leaveAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond(function () use ($request): array {
            $body = $this->parseRequestPayload($request);
            $file = $this->docxFileService->resolveFile($this->stringValue($body, 'file'));
            $sessionUid = $this->intValue($body, 'sessionUid');
            if ($sessionUid > 0) {
                $this->collaborationSessionService->leave($this->docxFileService->getFileHash($file), $sessionUid);
            }

            return [];
        });
    }

    public function presenceAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond(function () use ($request): array {
            $file = $this->readableFile($this->fileIdentifierFromQuery($request));

            return [
                'participants' => $this->collaborationSessionService->getActiveParticipants(
                    $this->docxFileService->getFileHash($file),
                ),
            ];
        });
    }

    public function revisionAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond(function () use ($request): array {
            $file = $this->readableFile($this->fileIdentifierFromQuery($request));

            return $this->revisionService->getRevisionState($file->getCombinedIdentifier());
        });
    }

    private function readableFile(string $identifier): File
    {
        $file = $this->docxFileService->resolveFile($identifier);
        $this->docxFileService->assertCanRead($file);

        return $file;
    }
}

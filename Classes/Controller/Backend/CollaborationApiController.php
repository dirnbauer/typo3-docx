<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Webconsulting\DocxEditor\Exception\DocxEditorException;
use Webconsulting\DocxEditor\Service\CollaborationSessionService;
use Webconsulting\DocxEditor\Service\DocxFileService;
use Webconsulting\DocxEditor\Service\RevisionService;

final class CollaborationApiController extends AbstractDocxApiController
{
    public function __construct(
        private readonly DocxFileService $docxFileService,
        private readonly CollaborationSessionService $collaborationSessionService,
        private readonly RevisionService $revisionService,
    ) {}

    public function joinAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->runJson(function () use ($request): ResponseInterface {
            $payload = $this->parseRequestPayload($request);
            $file = $this->docxFileService->resolveFile((string)$payload['file']);
            $this->docxFileService->assertCanRead($file);
            $fileHash = $this->docxFileService->getFileHash($file);
            $sessionUid = $this->collaborationSessionService->join(
                $fileHash,
                $file->getCombinedIdentifier(),
            );

            return $this->jsonSuccess([
                'sessionUid' => (int)$sessionUid,
                'participants' => $this->collaborationSessionService->getActiveParticipants($fileHash),
            ]);
        });
    }

    public function heartbeatAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->runJson(function () use ($request): ResponseInterface {
            $payload = $this->parseRequestPayload($request);
            $file = $this->docxFileService->resolveFile((string)$payload['file']);
            $this->docxFileService->assertCanRead($file);
            $fileHash = $this->docxFileService->getFileHash($file);
            $sessionUid = (int)($payload['sessionUid'] ?? 0);
            if ($sessionUid <= 0) {
                throw new DocxEditorException('Missing session.', 400);
            }
            $this->collaborationSessionService->heartbeat($fileHash, $sessionUid);

            return $this->jsonSuccess([
                'participants' => $this->collaborationSessionService->getActiveParticipants($fileHash),
            ]);
        });
    }

    public function leaveAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->runJson(function () use ($request): ResponseInterface {
            $payload = $this->parseRequestPayload($request);
            $file = $this->docxFileService->resolveFile((string)$payload['file']);
            $fileHash = $this->docxFileService->getFileHash($file);
            $sessionUid = (int)($payload['sessionUid'] ?? 0);
            if ($sessionUid > 0) {
                $this->collaborationSessionService->leave($fileHash, $sessionUid);
            }

            return $this->jsonSuccess([]);
        });
    }

    public function presenceAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->runJson(function () use ($request): ResponseInterface {
            $fileIdentifier = (string)($request->getQueryParams()['file'] ?? '');
            $file = $this->docxFileService->resolveFile($fileIdentifier);
            $this->docxFileService->assertCanRead($file);
            $fileHash = $this->docxFileService->getFileHash($file);

            return $this->jsonSuccess([
                'participants' => $this->collaborationSessionService->getActiveParticipants($fileHash),
            ]);
        });
    }

    public function revisionAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->runJson(function () use ($request): ResponseInterface {
            $fileIdentifier = (string)($request->getQueryParams()['file'] ?? '');
            $file = $this->docxFileService->resolveFile($fileIdentifier);
            $this->docxFileService->assertCanRead($file);
            $revision = $this->revisionService->getRevisionState($file->getCombinedIdentifier());

            return $this->jsonSuccess([
                'revision' => $revision['revision'],
                'contentHash' => $revision['contentHash'],
                'changedAt' => $revision['changedAt'],
                'savedBy' => $revision['savedBy'],
            ]);
        });
    }

}

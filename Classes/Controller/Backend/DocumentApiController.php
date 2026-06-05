<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use Webconsulting\DocxEditor\Exception\DocxEditorException;
use Webconsulting\DocxEditor\Service\DocxFileService;
use Webconsulting\DocxEditor\Service\RevisionService;

final class DocumentApiController extends AbstractDocxApiController
{
    public function __construct(
        private readonly DocxFileService $docxFileService,
        private readonly RevisionService $revisionService,
    ) {}

    public function loadAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->runJson(function () use ($request): ResponseInterface {
            $fileIdentifier = (string)($request->getQueryParams()['file'] ?? '');
            $file = $this->docxFileService->resolveFile($fileIdentifier);
            $this->docxFileService->assertCanRead($file);
            $binary = $this->docxFileService->readBinary($file);
            $revision = $this->revisionService->getRevisionState($file->getCombinedIdentifier());

            return $this->jsonSuccess([
                'file' => $file->getCombinedIdentifier(),
                'name' => $file->getName(),
                'mimeType' => $file->getMimeType(),
                'data' => base64_encode($binary),
                'revision' => $revision['revision'],
                'contentHash' => $revision['contentHash'],
            ]);
        });
    }

    public function saveAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->runJson(function () use ($request): ResponseInterface {
            $body = $this->parseRequestPayload($request);

            $fileIdentifier = (string)($body['file'] ?? '');
            $expectedRevision = (int)($body['revision'] ?? -1);
            $encoded = (string)($body['data'] ?? '');
            if ($encoded === '') {
                throw new DocxEditorException('Missing document payload.', 400);
            }

            $binary = base64_decode($encoded, true);
            if ($binary === false) {
                throw new DocxEditorException('Invalid base64 payload.', 400);
            }

            $file = $this->docxFileService->resolveFile($fileIdentifier);
            $this->docxFileService->assertCanWrite($file);

            $current = $this->revisionService->getRevisionState($file->getCombinedIdentifier());
            if ($expectedRevision >= 0 && $current['revision'] !== $expectedRevision) {
                throw new DocxEditorException(
                    'Document was updated by another editor. Reload to continue.',
                    409,
                );
            }

            $this->docxFileService->writeBinary($file, $binary);
            $contentHash = $this->revisionService->computeContentHash($binary);
            $userId = (int)$this->getBackendUser()->user['uid'];
            $revision = $this->revisionService->registerSave(
                $file->getCombinedIdentifier(),
                $contentHash,
                $userId,
            );

            return $this->jsonSuccess([
                'revision' => $revision,
                'contentHash' => $contentHash,
            ]);
        });
    }

    public function saveAsAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->runJson(function () use ($request): ResponseInterface {
            $body = $this->parseRequestPayload($request);

            $folderIdentifier = (string)($body['folder'] ?? '');
            $fileName = (string)($body['fileName'] ?? '');
            $encoded = (string)($body['data'] ?? '');
            if ($folderIdentifier === '' || $encoded === '') {
                throw new DocxEditorException('Missing folder or document payload.', 400);
            }

            $binary = base64_decode($encoded, true);
            if ($binary === false) {
                throw new DocxEditorException('Invalid base64 payload.', 400);
            }

            $folder = $this->docxFileService->resolveFolder($folderIdentifier);
            $file = $this->docxFileService->createDocxInFolder(
                $folder,
                $fileName,
                $binary,
                DuplicationBehavior::RENAME,
            );

            $contentHash = $this->revisionService->computeContentHash($binary);
            $userId = (int)$this->getBackendUser()->user['uid'];
            $revision = $this->revisionService->registerSave(
                $file->getCombinedIdentifier(),
                $contentHash,
                $userId,
            );

            return $this->jsonSuccess([
                'file' => $file->getCombinedIdentifier(),
                'name' => $file->getName(),
                'revision' => $revision,
                'contentHash' => $contentHash,
            ]);
        });
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}

<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Webconsulting\DocxEditor\Exception\DocxEditorException;
use Webconsulting\DocxEditor\Service\DocxFileService;
use Webconsulting\DocxEditor\Service\RevisionService;

/**
 * Loads and stores the .docx binary (base64 in JSON) with revision checks.
 */
final readonly class DocumentApiController extends AbstractDocxApiController
{
    public function __construct(
        private DocxFileService $docxFileService,
        private RevisionService $revisionService,
    ) {}

    public function loadAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond(function () use ($request): array {
            $file = $this->docxFileService->resolveFile($this->fileIdentifierFromQuery($request));
            $this->docxFileService->assertCanRead($file);
            $revision = $this->revisionService->getRevisionState($file->getCombinedIdentifier());

            return [
                'file' => $file->getCombinedIdentifier(),
                'name' => $file->getName(),
                'mimeType' => $file->getMimeType(),
                'data' => base64_encode($this->docxFileService->readBinary($file)),
                'revision' => $revision['revision'],
                'contentHash' => $revision['contentHash'],
            ];
        });
    }

    public function saveAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond(function () use ($request): array {
            $body = $this->parseRequestPayload($request);
            $binary = $this->decodeDocument($body);

            $file = $this->docxFileService->resolveFile($this->stringValue($body, 'file'));
            $this->docxFileService->assertCanWrite($file);

            $expectedRevision = $this->intValue($body, 'revision', -1);
            $current = $this->revisionService->getRevisionState($file->getCombinedIdentifier());
            if ($expectedRevision >= 0 && $current['revision'] !== $expectedRevision) {
                throw new DocxEditorException('error.conflict', 409);
            }

            $file->setContents($binary);

            return $this->registerSave($request, $file->getCombinedIdentifier(), $binary);
        });
    }

    public function saveAsAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond(function () use ($request): array {
            $body = $this->parseRequestPayload($request);
            $folderIdentifier = $this->stringValue($body, 'folder');
            if ($folderIdentifier === '') {
                throw new DocxEditorException('error.missingFolderIdentifier', 400);
            }
            $binary = $this->decodeDocument($body);

            $file = $this->docxFileService->createDocxInFolder(
                $this->docxFileService->resolveFolder($folderIdentifier),
                $this->stringValue($body, 'fileName'),
                $binary,
            );

            return [
                'file' => $file->getCombinedIdentifier(),
                'name' => $file->getName(),
            ] + $this->registerSave($request, $file->getCombinedIdentifier(), $binary);
        });
    }

    /**
     * @param array<string, mixed> $body
     */
    private function decodeDocument(array $body): string
    {
        $encoded = $this->stringValue($body, 'data');
        if ($encoded === '') {
            throw new DocxEditorException('error.missingDocument', 400);
        }
        $binary = base64_decode($encoded, true);
        if ($binary === false) {
            throw new DocxEditorException('error.invalidPayload', 400);
        }

        return $binary;
    }

    /**
     * @return array{revision: int, contentHash: string}
     */
    private function registerSave(ServerRequestInterface $request, string $fileIdentifier, string $binary): array
    {
        $backendUser = $request->getAttribute('backend.user');
        $userId = $backendUser instanceof BackendUserAuthentication ? ($backendUser->getUserId() ?? 0) : 0;
        $contentHash = $this->revisionService->computeContentHash($binary);

        return [
            'revision' => $this->revisionService->registerSave($fileIdentifier, $contentHash, $userId),
            'contentHash' => $contentHash,
        ];
    }
}

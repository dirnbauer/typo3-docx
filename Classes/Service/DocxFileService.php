<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFileAccessPermissionsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use Webconsulting\DocxEditor\Exception\DocxEditorException;

/**
 * Resolves FAL files and enforces backend user read/write permissions.
 */
final class DocxFileService
{
    private const DOCX_EXTENSION = 'docx';

    public function __construct(
        private readonly ResourceFactory $resourceFactory,
    ) {}

    public function isDocxFile(File $file): bool
    {
        return strtolower($file->getExtension()) === self::DOCX_EXTENSION;
    }

    public function resolveFile(string $combinedIdentifier): File
    {
        $combinedIdentifier = trim($combinedIdentifier);
        if ($combinedIdentifier === '') {
            throw new DocxEditorException('Missing file identifier.', 400);
        }

        try {
            $file = $this->resourceFactory->getFileObject($combinedIdentifier);
        } catch (\Throwable $exception) {
            throw new DocxEditorException('File not found.', 404, $exception);
        }

        if (!$this->isDocxFile($file)) {
            throw new DocxEditorException('Only .docx files can be edited.', 415);
        }

        return $file;
    }

    public function assertCanRead(File $file): void
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser->isAdmin() && !$backendUser->check('read', $file->getStorage()->getUid() . ':')) {
            throw new DocxEditorException('No read permission for this storage.', 403);
        }

        try {
            $file->getStorage()->checkFileActionPermission('read', $file);
        } catch (InsufficientFileAccessPermissionsException $exception) {
            throw new DocxEditorException('No read permission for this file.', 403, $exception);
        }
    }

    public function assertCanWrite(File $file): void
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser->isAdmin() && !$backendUser->check('write', $file->getStorage()->getUid() . ':')) {
            throw new DocxEditorException('No write permission for this storage.', 403);
        }

        try {
            $file->getStorage()->checkFileActionPermission('write', $file);
        } catch (InsufficientFileAccessPermissionsException $exception) {
            throw new DocxEditorException('No write permission for this file.', 403, $exception);
        }
    }

    public function readBinary(File $file): string
    {
        $this->assertCanRead($file);
        $contents = $file->getContents();
        if ($contents === '' && $file->getSize() > 0) {
            throw new DocxEditorException('Could not read file contents.', 500);
        }
        return $contents;
    }

    public function writeBinary(File $file, string $binary): void
    {
        $this->assertCanWrite($file);
        $file->setContents($binary);
    }

    public function getFileHash(File $file): string
    {
        return hash('sha256', $file->getCombinedIdentifier());
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}

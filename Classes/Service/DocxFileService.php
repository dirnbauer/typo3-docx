<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFileAccessPermissionsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
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

        $resource = $this->resourceFactory->retrieveFileOrFolderObject($combinedIdentifier);
        if (!$resource instanceof File) {
            throw new DocxEditorException('File not found.', 404);
        }
        $file = $resource;

        if (!$this->isDocxFile($file)) {
            throw new DocxEditorException('Only .docx files can be edited.', 415);
        }

        return $file;
    }

    public function resolveFolder(string $combinedIdentifier): Folder
    {
        $combinedIdentifier = trim($combinedIdentifier);
        if ($combinedIdentifier === '') {
            throw new DocxEditorException('Missing folder identifier.', 400);
        }

        $resource = $this->resourceFactory->retrieveFileOrFolderObject($combinedIdentifier);
        if (!$resource instanceof Folder) {
            throw new DocxEditorException('Folder not found.', 404);
        }

        return $resource;
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

    public function assertCanWriteFolder(Folder $folder): void
    {
        $backendUser = $this->getBackendUser();
        $storage = $folder->getStorage();
        if (!$backendUser->isAdmin() && !$backendUser->check('write', $storage->getUid() . ':')) {
            throw new DocxEditorException('No write permission for this storage.', 403);
        }

        if (!$folder->checkActionPermission('write')) {
            throw new DocxEditorException('No write permission for this folder.', 403);
        }

        if (!$storage->checkUserActionPermission('add', 'File')) {
            throw new DocxEditorException('You are not allowed to add files in this storage.', 403);
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

    public function createDocxInFolder(
        Folder $folder,
        string $fileName,
        string $binary,
        DuplicationBehavior $conflictMode = DuplicationBehavior::RENAME,
    ): File {
        $this->assertCanWriteFolder($folder);
        $fileName = $this->normalizeDocxFileName($fileName);

        $temporaryFile = GeneralUtility::tempnam('docx_editor_');
        if (file_put_contents($temporaryFile, $binary) === false) {
            throw new DocxEditorException('Could not prepare file for upload.', 500);
        }

        try {
            $file = $folder->getStorage()->addFile(
                $temporaryFile,
                $folder,
                $fileName,
                $conflictMode,
            );
        } finally {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }

        if (!$this->isDocxFile($file)) {
            throw new DocxEditorException('Only .docx files can be created.', 415);
        }

        return $file;
    }

    public function getFileHash(File $file): string
    {
        return hash('sha256', $file->getCombinedIdentifier());
    }

    private function normalizeDocxFileName(string $fileName): string
    {
        $fileName = trim($fileName);
        if ($fileName === '') {
            throw new DocxEditorException('File name is required.', 400);
        }

        $fileName = basename(str_replace(['\\', '/'], '/', $fileName));
        if ($fileName === '' || $fileName === '.' || $fileName === '..') {
            throw new DocxEditorException('Invalid file name.', 400);
        }

        if (!str_ends_with(strtolower($fileName), '.' . self::DOCX_EXTENSION)) {
            $fileName .= '.' . self::DOCX_EXTENSION;
        }

        return $fileName;
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}

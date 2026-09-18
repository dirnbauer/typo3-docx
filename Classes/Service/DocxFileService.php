<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\Exception as ResourceException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFileAccessPermissionsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Validation\ResultException;
use Webconsulting\DocxEditor\Exception\DocxEditorException;

/**
 * Resolves FAL files and enforces backend user read/write permissions.
 */
final readonly class DocxFileService
{
    private const EXTENSION = 'docx';

    public function __construct(
        private ResourceFactory $resourceFactory,
    ) {}

    public function isDocxFile(File $file): bool
    {
        return strtolower($file->getExtension()) === self::EXTENSION;
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
        if (!$this->isDocxFile($resource)) {
            throw new DocxEditorException('Only .docx files can be edited.', 415);
        }

        return $resource;
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
        $this->assertFileAction($file, 'read');
    }

    public function assertCanWrite(File $file): void
    {
        $this->assertFileAction($file, 'write');
    }

    public function canWrite(File $file): bool
    {
        try {
            $this->assertCanWrite($file);
            return true;
        } catch (DocxEditorException) {
            return false;
        }
    }

    public function readBinary(File $file): string
    {
        $contents = $file->getContents();
        if ($contents === '' && $file->getSize() > 0) {
            throw new DocxEditorException('Could not read file contents.', 500);
        }

        return $contents;
    }

    /**
     * Stores a new .docx in the folder; an existing name is suffixed (FAL RENAME).
     */
    public function createDocxInFolder(Folder $folder, string $fileName, string $binary): File
    {
        $this->assertCanWriteFolder($folder);
        $fileName = $this->normalizeDocxFileName($fileName);

        $temporaryFile = GeneralUtility::tempnam('docx_editor_');
        if (file_put_contents($temporaryFile, $binary) === false) {
            throw new DocxEditorException('Could not prepare file for upload.', 500);
        }

        try {
            $file = $folder->getStorage()->addFile($temporaryFile, $folder, $fileName, DuplicationBehavior::RENAME);
        } catch (ResultException $exception) {
            // FAL's consistency check: the bytes are not a Word document.
            throw new DocxEditorException('The content is not a valid .docx document.', 415, $exception);
        } catch (ResourceException $exception) {
            throw new DocxEditorException($exception->getMessage(), 400, $exception);
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

    /**
     * Presence sessions are keyed by this hash instead of the (long) identifier.
     */
    public function getFileHash(File $file): string
    {
        return hash('sha256', $file->getCombinedIdentifier());
    }

    /**
     * Human-readable location shown in the save notification,
     * e.g. "fileadmin / user_upload/report.docx".
     */
    public function buildFilePathLabel(File $file): string
    {
        $storageName = trim($file->getStorage()->getName());
        $identifier = ltrim($file->getIdentifier(), '/');

        return $storageName !== '' ? $storageName . ' / ' . $identifier : $identifier;
    }

    /**
     * Strips directories from a user-supplied "save as" name and enforces the
     * .docx extension.
     */
    public function normalizeDocxFileName(string $fileName): string
    {
        $fileName = trim($fileName);
        if ($fileName === '') {
            throw new DocxEditorException('File name is required.', 400);
        }

        $fileName = basename(str_replace('\\', '/', $fileName));
        if ($fileName === '' || $fileName === '.' || $fileName === '..') {
            throw new DocxEditorException('Invalid file name.', 400);
        }

        if (!str_ends_with(strtolower($fileName), '.' . self::EXTENSION)) {
            $fileName .= '.' . self::EXTENSION;
        }

        return $fileName;
    }

    /**
     * @param 'read'|'write' $action
     */
    private function assertFileAction(File $file, string $action): void
    {
        $storage = $file->getStorage();
        $backendUser = $this->getBackendUser();
        if (!$backendUser->isAdmin() && !$backendUser->check($action, $storage->getUid() . ':')) {
            throw new DocxEditorException(sprintf('No %s permission for this storage.', $action), 403);
        }

        try {
            $storage->checkFileActionPermission($action, $file);
        } catch (InsufficientFileAccessPermissionsException $exception) {
            throw new DocxEditorException(sprintf('No %s permission for this file.', $action), 403, $exception);
        }
    }

    private function assertCanWriteFolder(Folder $folder): void
    {
        $storage = $folder->getStorage();
        $backendUser = $this->getBackendUser();
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

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}

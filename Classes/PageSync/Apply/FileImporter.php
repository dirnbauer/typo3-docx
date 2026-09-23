<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Apply;

use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\DocxEditor\PageSync\Document\Image;
use Webconsulting\DocxEditor\PageSync\Exception\PageSyncException;

/**
 * Stores pictures from Word in FAL: in the configured folder (created when missing), under a
 * name made from the alt text, and only once — a picture whose bytes are already in the folder
 * is reused.
 */
final readonly class FileImporter
{
    public function __construct(
        private ResourceFactory $resourceFactory,
        private StorageRepository $storageRepository,
    ) {}

    public function import(Image $image, string $folderIdentifier): File
    {
        if ($image->fileUid > 0) {
            try {
                $existing = $this->resourceFactory->getFileObject($image->fileUid);
                if ($existing->getSha1() === $image->data->sha1()) {
                    return $existing;
                }
            } catch (\Throwable) {
                // The file is gone; store the bytes again.
            }
        }

        $folder = $this->folder($folderIdentifier);
        if (!$folder->checkActionPermission('write') || !$folder->getStorage()->checkUserActionPermission('add', 'File')) {
            throw new PageSyncException('error.noFolderAccess', 403, [$folderIdentifier]);
        }
        foreach ($folder->getFiles() as $file) {
            if ($file->getSha1() === $image->data->sha1()) {
                return $file;
            }
        }

        $temporaryFile = GeneralUtility::tempnam('docx_pagesync_image_');
        if (file_put_contents($temporaryFile, $image->data->bytes) === false) {
            throw new PageSyncException('error.unreadable', 500);
        }
        try {
            $file = $folder->addFile($temporaryFile, $this->fileName($image, $folder), DuplicationBehavior::RENAME);
        } catch (\Throwable $exception) {
            throw new PageSyncException('error.imageRejected', 422, [$image->data->fileName], $exception);
        } finally {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }

        return $file;
    }

    /**
     * The folder, with every missing level created — as far as the user may create folders.
     */
    private function folder(string $identifier): Folder
    {
        try {
            return $this->resourceFactory->getFolderObjectFromCombinedIdentifier($identifier);
        } catch (FolderDoesNotExistException) {
            // created below
        } catch (InsufficientFolderAccessPermissionsException $exception) {
            throw new PageSyncException('error.noFolderAccess', 403, [$identifier], $exception);
        }

        [$storageUid, $path] = array_pad(explode(':', $identifier, 2), 2, '');
        $storageUid = (int)$storageUid;
        if ($storageUid <= 0) {
            throw new PageSyncException('error.noFolderAccess', 403, [$identifier]);
        }
        $storage = $this->storageRepository->getStorageObject($storageUid);
        $folder = $storage->getRootLevelFolder(false);
        foreach (array_filter(explode('/', trim($path, '/')), static fn(string $segment): bool => $segment !== '') as $segment) {
            try {
                $folder = $folder->hasFolder($segment) ? $folder->getSubfolder($segment) : $folder->createFolder($segment);
            } catch (\Throwable $exception) {
                throw new PageSyncException('error.noFolderAccess', 403, [$identifier], $exception);
            }
        }

        return $folder;
    }

    private function fileName(Image $image, Folder $folder): string
    {
        $base = $image->alternative !== '' ? $image->alternative : pathinfo($image->data->fileName, PATHINFO_FILENAME);
        $slug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: ''), '-'));
        $slug = $slug === '' ? 'image' : substr($slug, 0, 60);

        return $folder->getStorage()->sanitizeFileName($slug . '-' . substr($image->data->sha1(), 0, 8) . '.' . $image->data->extension(), $folder);
    }
}

<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tracks document revisions for collaborative save detection.
 */
final class RevisionService
{
    private const TABLE = 'tx_docx_editor_revision';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array{revision: int, contentHash: string, savedBy: int, changedAt: int}
     */
    public function getRevisionState(string $fileIdentifier): array
    {
        $row = $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->select(
                ['revision', 'content_hash', 'saved_by', 'tstamp'],
                self::TABLE,
                ['file_identifier' => $fileIdentifier],
                [],
                ['uid' => 'DESC'],
                1
            )
            ->fetchAssociative();

        if ($row === false) {
            return [
                'revision' => 0,
                'contentHash' => '',
                'savedBy' => 0,
                'changedAt' => 0,
            ];
        }

        return [
            'revision' => (int)$row['revision'],
            'contentHash' => (string)$row['content_hash'],
            'savedBy' => (int)$row['saved_by'],
            'changedAt' => (int)$row['tstamp'],
        ];
    }

    public function registerSave(string $fileIdentifier, string $contentHash, int $backendUserId): int
    {
        $current = $this->getRevisionState($fileIdentifier);
        $nextRevision = $current['revision'] + 1;
        $now = time();

        $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->insert(
                self::TABLE,
                [
                    'pid' => 0,
                    'tstamp' => $now,
                    'crdate' => $now,
                    'file_identifier' => $fileIdentifier,
                    'revision' => $nextRevision,
                    'content_hash' => $contentHash,
                    'saved_by' => $backendUserId,
                ],
            );

        return $nextRevision;
    }

    public function computeContentHash(string $binary): string
    {
        return hash('sha256', $binary);
    }
}

<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Tracks active backend users editing the same .docx file (presence).
 */
final class CollaborationSessionService
{
    private const TABLE = 'tx_docx_editor_session';
    private const HEARTBEAT_TTL = 45;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function join(string $fileHash, string $fileIdentifier): string
    {
        $user = $this->getBackendUser();
        $now = time();
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);

        $existing = $connection->select(
            ['uid'],
            self::TABLE,
            [
                'file_hash' => $fileHash,
                'backend_user' => $user->user['uid'],
                'deleted' => 0,
            ],
        )->fetchOne();

        if ($existing !== false) {
            $connection->update(
                self::TABLE,
                [
                    'last_heartbeat' => $now,
                    'user_name' => $this->resolveDisplayName($user),
                    'file_identifier' => $fileIdentifier,
                ],
                ['uid' => (int)$existing],
            );
            return (string)$existing;
        }

        $connection->insert(
            self::TABLE,
            [
                'pid' => 0,
                'tstamp' => $now,
                'crdate' => $now,
                'deleted' => 0,
                'file_hash' => $fileHash,
                'file_identifier' => $fileIdentifier,
                'backend_user' => (int)$user->user['uid'],
                'user_name' => $this->resolveDisplayName($user),
                'last_heartbeat' => $now,
            ],
        );

        return (string)$connection->lastInsertId();
    }

    public function heartbeat(string $fileHash, int $sessionUid): void
    {
        $user = $this->getBackendUser();
        $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->update(
                self::TABLE,
                ['last_heartbeat' => time()],
                [
                    'uid' => $sessionUid,
                    'file_hash' => $fileHash,
                    'backend_user' => (int)$user->user['uid'],
                    'deleted' => 0,
                ],
            );
    }

    public function leave(string $fileHash, int $sessionUid): void
    {
        $user = $this->getBackendUser();
        $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->update(
                self::TABLE,
                ['deleted' => 1, 'tstamp' => time()],
                [
                    'uid' => $sessionUid,
                    'file_hash' => $fileHash,
                    'backend_user' => (int)$user->user['uid'],
                ],
            );
    }

    /**
     * @return list<array{userId: int, userName: string, sessionUid: int}>
     */
    public function getActiveParticipants(string $fileHash): array
    {
        $this->purgeStaleSessions();
        $threshold = time() - self::HEARTBEAT_TTL;
        $rows = $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->select(
                ['uid', 'backend_user', 'user_name', 'last_heartbeat'],
                self::TABLE,
                [
                    'file_hash' => $fileHash,
                    'deleted' => 0,
                ],
            )
            ->fetchAllAssociative();

        $participants = [];
        foreach ($rows as $row) {
            if ((int)($row['last_heartbeat'] ?? 0) < $threshold) {
                continue;
            }
            $participants[] = [
                'userId' => (int)$row['backend_user'],
                'userName' => (string)$row['user_name'],
                'sessionUid' => (int)$row['uid'],
            ];
        }

        return $participants;
    }

    private function purgeStaleSessions(): void
    {
        $threshold = time() - self::HEARTBEAT_TTL;
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder
            ->update(self::TABLE)
            ->set('deleted', 1)
            ->where(
                $queryBuilder->expr()->lt('last_heartbeat', $queryBuilder->createNamedParameter($threshold)),
                $queryBuilder->expr()->eq('deleted', 0),
            )
            ->executeStatement();
    }

    private function resolveDisplayName(BackendUserAuthentication $user): string
    {
        $realName = trim((string)($user->user['realName'] ?? ''));
        if ($realName !== '') {
            return $realName;
        }
        return trim((string)($user->user['username'] ?? 'Editor'));
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}

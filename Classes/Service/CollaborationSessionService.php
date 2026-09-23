<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Presence: which backend users currently have the same .docx open. A session
 * is alive while its heartbeat is younger than HEARTBEAT_TTL; stale rows are
 * removed whenever participants are listed.
 */
final readonly class CollaborationSessionService
{
    private const string TABLE = 'tx_docx_editor_session';
    private const int HEARTBEAT_TTL = 45;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Returns the session uid; a user re-joining a file keeps the same session.
     */
    public function join(string $fileHash, string $fileIdentifier): int
    {
        $user = $this->getBackendUser();
        $now = time();
        $connection = $this->connection();
        $existing = $connection->select(
            ['uid'],
            self::TABLE,
            ['file_hash' => $fileHash, 'backend_user' => $user->getUserId() ?? 0],
        )->fetchOne();

        if ($existing !== false) {
            $connection->update(
                self::TABLE,
                ['last_heartbeat' => $now, 'user_name' => $this->resolveDisplayName($user), 'file_identifier' => $fileIdentifier],
                ['uid' => (int)$existing],
            );

            return (int)$existing;
        }

        $connection->insert(self::TABLE, [
            'pid' => 0,
            'tstamp' => $now,
            'crdate' => $now,
            'file_hash' => $fileHash,
            'file_identifier' => $fileIdentifier,
            'backend_user' => $user->getUserId() ?? 0,
            'user_name' => $this->resolveDisplayName($user),
            'last_heartbeat' => $now,
        ]);

        return (int)$connection->lastInsertId();
    }

    public function heartbeat(string $fileHash, int $sessionUid): void
    {
        $this->connection()->update(
            self::TABLE,
            ['last_heartbeat' => time()],
            ['uid' => $sessionUid, 'file_hash' => $fileHash, 'backend_user' => $this->getBackendUser()->getUserId() ?? 0],
        );
    }

    public function leave(string $fileHash, int $sessionUid): void
    {
        $this->connection()->delete(
            self::TABLE,
            ['uid' => $sessionUid, 'file_hash' => $fileHash, 'backend_user' => $this->getBackendUser()->getUserId() ?? 0],
        );
    }

    /**
     * @return list<array{userId: int, userName: string, sessionUid: int}>
     */
    public function getActiveParticipants(string $fileHash): array
    {
        $threshold = time() - self::HEARTBEAT_TTL;
        $this->purgeStaleSessions($threshold);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->select('uid', 'backend_user', 'user_name')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('file_hash', $queryBuilder->createNamedParameter($fileHash)),
                $queryBuilder->expr()->gte('last_heartbeat', $queryBuilder->createNamedParameter($threshold, Connection::PARAM_INT)),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn(array $row): array => [
                'userId' => (int)$row['backend_user'],
                'userName' => (string)$row['user_name'],
                'sessionUid' => (int)$row['uid'],
            ],
            $rows,
        );
    }

    private function purgeStaleSessions(int $threshold): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder
            ->delete(self::TABLE)
            ->where($queryBuilder->expr()->lt('last_heartbeat', $queryBuilder->createNamedParameter($threshold, Connection::PARAM_INT)))
            ->executeStatement();
    }

    private function resolveDisplayName(BackendUserAuthentication $user): string
    {
        $realName = trim((string)($user->user['realName'] ?? ''));

        return $realName !== '' ? $realName : trim((string)($user->user['username'] ?? 'Editor'));
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}

<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Record;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Database\RelationHandler;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * Reads the records of a page the way the Page module shows them: in the backend user's
 * workspace (versions overlaid, delete placeholders gone), per language, in sorting order.
 */
final readonly class RecordRepository
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaSchemaFactory $schemaFactory,
        private FileRepository $fileRepository,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function record(string $table, int $uid, int $workspaceId): ?array
    {
        if ($uid <= 0 || !$this->schemaFactory->has($table)) {
            return null;
        }
        $query = $this->connectionPool->getQueryBuilderForTable($table);
        $query->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspaceId));
        $row = $query->select('*')->from($table)
            ->where($query->expr()->eq('uid', $query->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $this->overlay($table, $row, $workspaceId) : null;
    }

    /**
     * The page, or its translation record for a language other than the default.
     *
     * @return array<string, mixed>|null
     */
    public function pageInLanguage(int $pageUid, int $languageId, int $workspaceId): ?array
    {
        if ($languageId <= 0) {
            return $this->record('pages', $pageUid, $workspaceId);
        }
        $query = $this->connectionPool->getQueryBuilderForTable('pages');
        $query->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspaceId));
        $row = $query->select('*')->from('pages')
            ->where(
                $query->expr()->eq('l10n_parent', $query->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $query->expr()->eq('sys_language_uid', $query->createNamedParameter($languageId, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $this->overlay('pages', $row, $workspaceId) : null;
    }

    /**
     * The content elements of a page in one language (records for "all languages" included),
     * sorted as the Page module sorts them.
     *
     * @return list<array<string, mixed>>
     */
    public function contentElements(int $pageUid, int $languageId, int $workspaceId): array
    {
        $query = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $query->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspaceId));
        $result = $query->select('*')->from('tt_content')
            ->where(
                $query->expr()->eq('pid', $query->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $query->expr()->in('sys_language_uid', $query->createNamedParameter([$languageId, -1], Connection::PARAM_INT_ARRAY)),
            )
            ->orderBy('sorting')
            ->executeQuery();

        $rows = [];
        while ($row = $result->fetchAssociative()) {
            $row = $this->overlay('tt_content', $row, $workspaceId);
            // A record moved to another page in this workspace no longer belongs here.
            if ($row !== null && (int)($row['pid'] ?? 0) === $pageUid) {
                $rows[] = $row;
            }
        }
        usort($rows, static fn(array $a, array $b): int => ((int)($a['sorting'] ?? 0)) <=> ((int)($b['sorting'] ?? 0)));

        return $rows;
    }

    /**
     * The child records of a collection (inline) field, in their sorting order.
     *
     * @param array<string, mixed> $parent
     *
     * @return list<array<string, mixed>>
     */
    public function children(string $parentTable, array $parent, string $field, int $workspaceId): array
    {
        if (!$this->schemaFactory->has($parentTable) || !$this->schemaFactory->get($parentTable)->hasField($field)) {
            return [];
        }
        $schemaField = $this->schemaFactory->get($parentTable)->getField($field);
        $childTable = (string)($schemaField->getConfiguration()['foreign_table'] ?? '');
        if ($childTable === '') {
            return [];
        }
        $relationHandler = GeneralUtility::makeInstance(RelationHandler::class);
        $relationHandler->setWorkspaceId($workspaceId);
        $relationHandler->initializeForField($parentTable, $schemaField, self::liveUid($parent), (string)($parent[$field] ?? ''));
        $relationHandler->processDeletePlaceholder();
        $uids = $relationHandler->tableArray[$childTable] ?? [];

        $children = [];
        foreach ($uids as $uid) {
            $child = $this->record($childTable, (int)$uid, $workspaceId);
            if ($child !== null) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return list<FileReference>
     */
    public function fileReferences(string $table, array $record, string $field, int $workspaceId): array
    {
        if (!$this->schemaFactory->has($table) || !$this->schemaFactory->get($table)->hasField($field)) {
            return [];
        }

        return array_values($this->fileRepository->findByRelation($table, $field, self::liveUid($record), $workspaceId));
    }

    /**
     * The uid other records point to: the live uid, also for a workspace version.
     *
     * @param array<string, mixed> $record
     */
    public static function liveUid(array $record): int
    {
        $uid = (int)($record['uid'] ?? 0);
        $versionOf = (int)($record['t3ver_oid'] ?? 0);

        return $versionOf > 0 ? $versionOf : $uid;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    private function overlay(string $table, array $row, int $workspaceId): ?array
    {
        if ($workspaceId > 0) {
            BackendUtility::workspaceOL($table, $row, $workspaceId, true);
        }
        if (!is_array($row) || VersionState::tryFrom((int)($row['t3ver_state'] ?? 0)) === VersionState::DELETE_PLACEHOLDER) {
            return null;
        }

        return $row;
    }
}

<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Apply;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\DocxEditor\PageSync\Configuration\PageSyncSettings;
use Webconsulting\DocxEditor\PageSync\Document\Figure;
use Webconsulting\DocxEditor\PageSync\Document\PlainText;
use Webconsulting\DocxEditor\PageSync\Exception\PageSyncException;
use Webconsulting\DocxEditor\PageSync\Field\FieldCodec;
use Webconsulting\DocxEditor\PageSync\Matching\CandidateProvider;
use Webconsulting\DocxEditor\PageSync\Matching\FieldAssignment;
use Webconsulting\DocxEditor\PageSync\Matching\FieldMapping;
use Webconsulting\DocxEditor\PageSync\Matching\FieldPlacer;
use Webconsulting\DocxEditor\PageSync\Matching\Value\CollectionValue;
use Webconsulting\DocxEditor\PageSync\Matching\Value\ImagesValue;
use Webconsulting\DocxEditor\PageSync\Matching\Value\LinkValue;
use Webconsulting\DocxEditor\PageSync\Matching\Value\ValueBlocks;
use Webconsulting\DocxEditor\PageSync\Plan\EntryAction;
use Webconsulting\DocxEditor\PageSync\Plan\PlanDecisions;
use Webconsulting\DocxEditor\PageSync\Plan\PlanEntry;
use Webconsulting\DocxEditor\PageSync\Plan\SyncPlan;
use Webconsulting\DocxEditor\PageSync\Record\RecordRepository;

/**
 * Carries out a reviewed plan through the DataHandler, as the backend user and in the user's
 * workspace — so permissions, workspace versioning, the RTE transformation, the HTML sanitizer,
 * the backend layout's content restrictions and the reference index all apply as they would to
 * an edit in the backend.
 *
 * Commands run first (confirmed deletions, moves, localizations), then one data map with every
 * update and every new record.
 */
#[Autoconfigure(public: true)]
final readonly class PlanApplier
{
    public function __construct(
        private FileImporter $files,
        private RecordRepository $records,
        private FieldPlacer $placer,
        private CandidateProvider $candidates,
        private FieldCodec $codec,
        private PageSyncSettings $settings,
    ) {}

    /**
     * Applies the plans of a document imported as new pages: after the last subpage of their
     * parent, each page after the one before it.
     *
     * @param list<SyncPlan> $plans
     *
     * @return list<ApplyResult>
     */
    public function applyNewPages(array $plans, PlanDecisions $decisions, BackendUserAuthentication $user): array
    {
        $results = [];
        $afterPage = $plans === [] ? 0 : $this->records->lastSubpage($plans[0]->parentPageUid, (int)$user->workspace);
        foreach ($plans as $plan) {
            $result = $this->apply($plan, $decisions, $user, $afterPage);
            $results[] = $result;
            $afterPage = $result->pageUid > 0 ? $result->pageUid : $afterPage;
        }

        return $results;
    }

    /**
     * @param int $afterPageUid For a new page: the page it goes after (0: first under its parent)
     */
    public function apply(SyncPlan $plan, PlanDecisions $decisions, BackendUserAuthentication $user, int $afterPageUid = 0): ApplyResult
    {
        if ((int)$user->workspace !== $plan->workspaceId) {
            throw new PageSyncException('error.workspaceChanged', 409);
        }
        $job = new ApplyJob($plan, $decisions, $user, $this->settings->imageFolder($plan->pageUid));
        if ($plan->newPage) {
            // First in the data map, so the content's "NEW" page id resolves.
            $job->data['pages'][ApplyJob::NEW_PAGE] = [
                'pid' => $afterPageUid > 0 ? -$afterPageUid : $plan->parentPageUid,
                'title' => $plan->page->title ?? 'Untitled',
                'doktype' => 1,
                'hidden' => $this->settings->newPagesHidden() ? 1 : 0,
            ];
        }

        $this->commands($job);
        $translated = $job->commandsRun === [] ? [] : $this->runCommands($job, $job->commandsRun);

        $pageEntry = $plan->page;
        if ($pageEntry !== null && !$plan->newPage && $decisions->includes($pageEntry)) {
            $pageUid = $pageEntry->action === EntryAction::Translate ? ($translated['pages'][$plan->pageUid] ?? 0) : $pageEntry->uid;
            if ($pageUid > 0) {
                $this->update($job, $pageEntry, 'pages', $pageUid, []);
            }
        }
        foreach ($plan->entries as $entry) {
            if (!$decisions->includes($entry)) {
                continue;
            }
            match ($entry->action) {
                EntryAction::Update, EntryAction::Conflict => $this->update($job, $entry, $entry->table, $entry->uid, []),
                EntryAction::Translate => $this->translateUpdate($job, $entry, $translated),
                EntryAction::Create => $this->create($job, $entry),
                default => null,
            };
        }
        $this->queueCreates($job);

        $created = [];
        $newPageUid = 0;
        if ($job->data !== []) {
            $dataHandler = $this->dataHandler($job->data, [], $user);
            $dataHandler->process_datamap();
            $this->collectErrors($dataHandler, $job);
            if ($plan->newPage) {
                $newPageUid = (int)($dataHandler->substNEWwithIDs[ApplyJob::NEW_PAGE] ?? 0);
                if ($newPageUid <= 0) {
                    $job->errors[] = 'The new page was not created.';
                }
            }
            foreach ($job->newIds as $entryId => $newId) {
                $uid = (int)($dataHandler->substNEWwithIDs[$newId] ?? 0);
                if ($uid > 0) {
                    $created[$entryId] = $uid;
                } else {
                    $job->errors[] = sprintf('The new record of plan entry %s was not created.', $entryId);
                }
            }
        }
        if ($job->referenceDeletions !== []) {
            $dataHandler = $this->dataHandler([], $job->referenceDeletions, $user);
            $dataHandler->process_cmdmap();
            $this->collectErrors($dataHandler, $job);
        }

        $translatedEntries = [];
        foreach ($job->translations as $entryId => $sourceUid) {
            $uid = $translated['tt_content'][$sourceUid] ?? 0;
            if ($uid > 0) {
                $translatedEntries[$entryId] = $uid;
            }
        }

        return new ApplyResult(
            created: $created,
            updated: array_values(array_unique($job->updated)),
            deleted: $job->deleted,
            moved: $job->moved,
            translated: $translatedEntries,
            errors: $job->errors,
            workspaceId: $plan->workspaceId,
            pageUid: $plan->newPage ? $newPageUid : $plan->pageUid,
        );
    }

    /**
     * Confirmed deletions, moves and localizations.
     */
    private function commands(ApplyJob $job): void
    {
        $plan = $job->plan;
        foreach ($plan->entries as $entry) {
            if ($entry->action === EntryAction::Delete && $job->decisions->includes($entry)) {
                $job->commandsRun[$entry->table][$entry->uid]['delete'] = 1;
                $job->deleted[] = $entry->uid;
            }
            foreach ($entry->children as $child) {
                if ($child->action === EntryAction::Delete && $job->decisions->includes($child) && !in_array($entry->id, $job->decisions->excluded, true)) {
                    $job->commandsRun[$child->table][$child->uid]['delete'] = 1;
                    $job->deleted[] = $child->uid;
                }
            }
            if ($entry->action === EntryAction::Translate && $job->decisions->includes($entry)) {
                $job->commandsRun['tt_content'][$entry->uid]['localize'] = $plan->languageId;
                $job->translations[$entry->id] = $entry->uid;
            }
        }
        if ($plan->page !== null && $plan->page->action === EntryAction::Translate && $job->decisions->includes($plan->page)) {
            $job->commandsRun['pages'][$plan->pageUid]['localize'] = $plan->languageId;
        }
        if ($job->decisions->applyMoves) {
            foreach ($plan->moves as $move) {
                if (isset($job->commandsRun['tt_content'][$move->uid])) {
                    continue;
                }
                $target = $move->afterUid > 0 ? -$move->afterUid : $plan->pageUid;
                $job->commandsRun['tt_content'][$move->uid]['move'] = $move->columnChanged
                    ? ['action' => 'paste', 'target' => $target, 'update' => ['colPos' => $move->colPos]]
                    : $target;
                $job->moved[] = $move->uid;
            }
        }
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $commands
     *
     * @return array<string, array<int, int>> Table => source uid => uid of the localized copy
     */
    private function runCommands(ApplyJob $job, array $commands): array
    {
        $dataHandler = $this->dataHandler([], $commands, $job->user);
        $dataHandler->process_cmdmap();
        $this->collectErrors($dataHandler, $job);

        $translated = [];
        foreach ($dataHandler->copyMappingArray_merged as $table => $mapping) {
            if (!is_string($table) || !is_array($mapping)) {
                continue;
            }
            foreach ($mapping as $source => $copy) {
                if (is_numeric($source) && is_numeric($copy)) {
                    $translated[$table][(int)$source] = (int)$copy;
                }
            }
        }

        return $translated;
    }

    /**
     * Writes the fields Word changed, and the changes to a record's collections.
     *
     * @param array<string, array<int, int>> $childMapping Child table => source uid => uid to write to (localized copies)
     */
    private function update(ApplyJob $job, PlanEntry $entry, string $table, int $uid, array $childMapping): void
    {
        $record = $this->records->record($table, $uid, $job->plan->workspaceId);
        if ($record === null) {
            $job->errors[] = sprintf('%s:%d no longer exists.', $table, $uid);

            return;
        }
        foreach ($entry->fields as $change) {
            if ($change->update === null || !$job->decisions->takesWord($entry, $change)) {
                continue;
            }
            $value = $change->update->value;
            if (is_array($value)) {
                $value = implode(',', $this->references($job, $table, $record, $change->field->name, $value));
            }
            $job->data[$table][$uid][$change->field->name] = $value;
            foreach ($change->update->settings as $name => $setting) {
                $job->data[$table][$uid][$name] = $setting;
            }
            $job->updated[] = $uid;
        }

        // Collections: changed items, new items, and the items' order.
        $byField = [];
        foreach ($entry->children as $child) {
            $byField[$child->parentField][] = $child;
        }
        foreach ($byField as $field => $children) {
            $this->collection($job, $table, $record, $uid, $field, $children, $childMapping);
        }
    }

    /**
     * @param array<string, mixed> $parent
     * @param list<PlanEntry> $children
     * @param array<string, array<int, int>> $childMapping
     */
    private function collection(ApplyJob $job, string $table, array $parent, int $uid, string $field, array $children, array $childMapping): void
    {
        $existing = array_map(
            static fn(array $record): int => (int)$record['uid'],
            $this->records->children($table, $parent, $field, $job->plan->workspaceId),
        );
        $order = [];
        $changed = false;
        foreach ($children as $child) {
            $included = $job->decisions->includes($child);
            if ($child->action === EntryAction::Delete) {
                $changed = $changed || $included;
                continue;
            }
            if ($child->action === EntryAction::Create) {
                if (!$included || $child->mapping === null) {
                    continue;
                }
                $newId = $job->newId();
                $job->data[$child->table][$newId] = $this->row($job, $child->mapping, (int)($parent['pid'] ?? $job->plan->pageUid)) + [
                    'pid' => (int)($parent['pid'] ?? $job->plan->pageUid),
                    'sys_language_uid' => $job->plan->languageId,
                ];
                $order[] = $newId;
                $changed = true;
                continue;
            }
            $target = $childMapping[$child->table][$child->uid] ?? $child->uid;
            $order[] = (string)$target;
            if ($included) {
                $this->update($job, $child, $child->table, $target, []);
            }
        }
        if (!$changed) {
            return;
        }
        // Items the document did not show (added after the export) stay, at the end.
        $deleted = array_map(
            static fn(PlanEntry $child): string => (string)$child->uid,
            array_values(array_filter($children, static fn(PlanEntry $child): bool => $child->action === EntryAction::Delete && $job->decisions->includes($child))),
        );
        foreach ($existing as $childUid) {
            if (!in_array((string)$childUid, $order, true) && !in_array((string)$childUid, $deleted, true)) {
                $order[] = (string)$childUid;
            }
        }
        $job->data[$table][$uid][$field] = implode(',', $order);
        $job->updated[] = $uid;
    }

    /**
     * @param array<string, array<int, int>> $translated
     */
    private function translateUpdate(ApplyJob $job, PlanEntry $entry, array $translated): void
    {
        $uid = $translated['tt_content'][$entry->uid] ?? 0;
        if ($uid <= 0) {
            $job->errors[] = sprintf('tt_content:%d could not be localized.', $entry->uid);

            return;
        }
        $this->update($job, $entry, 'tt_content', $uid, $translated);
    }

    /**
     * Queues a new element; the data map receives the queue in an order that keeps the
     * document's order (see queueCreates()).
     */
    private function create(ApplyJob $job, PlanEntry $entry): void
    {
        $match = $entry->match;
        if ($match === null) {
            return;
        }
        $type = $job->decisions->typeFor($entry);
        $contextPage = $job->plan->newPage ? $job->plan->parentPageUid : $job->plan->pageUid;
        $candidates = $job->candidates[$entry->colPos] ??= $this->candidates->forColumn($contextPage, $entry->colPos, $job->user);
        $candidate = $candidates[$type] ?? null;
        if ($candidate === null) {
            $job->errors[] = sprintf('Content type "%s" may not be created in column %d.', $type, $entry->colPos);

            return;
        }
        $mapping = $match->proposal($type)?->mapping() ?? $this->placer->place($match->part->shape, $candidate->shape)->mapping;
        $newId = $job->newId();
        $row = [
            'CType' => $type,
            'colPos' => $entry->colPos,
            'sys_language_uid' => $job->plan->languageId,
        ] + $this->row($job, $mapping, $job->plan->newPage ? 0 : $job->plan->pageUid);
        $job->creates[] = ['entry' => $entry, 'newId' => $newId, 'row' => $row];
        $job->newIds[$entry->id] = $newId;
    }

    /**
     * New elements after the same predecessor are written last-first: each goes directly after
     * the predecessor, which pushes the earlier ones down into the document's order.
     */
    private function queueCreates(ApplyJob $job): void
    {
        $groups = [];
        foreach ($job->creates as $create) {
            $groups[$create['entry']->colPos . ':' . $create['entry']->afterUid][] = $create;
        }
        foreach ($groups as $group) {
            foreach (array_reverse($group) as $create) {
                $afterUid = $create['entry']->afterUid;
                $pid = match (true) {
                    $afterUid > 0 => -$afterUid,
                    $job->plan->newPage => ApplyJob::NEW_PAGE,
                    default => $job->plan->pageUid,
                };
                $job->data['tt_content'][$create['newId']] = ['pid' => $pid] + $create['row'];
            }
        }
    }

    /**
     * The fields of a new record from a mapping; collections and pictures become records of
     * their own in the data map.
     *
     * @return array<string, int|string>
     */
    private function row(ApplyJob $job, FieldMapping $mapping, int $pid): array
    {
        $row = [];
        foreach ($mapping->assignments as $assignment) {
            foreach ($this->assignmentValue($job, $assignment, $pid) as $name => $value) {
                $row[$name] = $value;
            }
        }
        foreach ($mapping->settings as $name => $value) {
            $row[$name] = $value;
        }

        return $row;
    }

    /**
     * @return array<string, int|string>
     */
    private function assignmentValue(ApplyJob $job, FieldAssignment $assignment, int $pid): array
    {
        $field = $assignment->field;
        $value = $assignment->value;
        if ($value instanceof CollectionValue) {
            $ids = [];
            foreach ($value->items as $item) {
                $newId = $job->newId();
                $job->data[$value->childTable][$newId] = ['pid' => $this->pid($job, $pid), 'sys_language_uid' => $job->plan->languageId] + $this->row($job, $item, $pid);
                $ids[] = $newId;
            }

            return [$field->name => implode(',', $ids)];
        }
        if ($value instanceof ImagesValue) {
            return [$field->name => implode(',', $this->newReferences($job, $value->figures, $pid))];
        }
        if ($value instanceof LinkValue) {
            return [$field->name => $value->href];
        }
        $update = $this->codec->update($field, ValueBlocks::of($value));
        $result = [$field->name => is_string($update->value) ? $update->value : ''];
        foreach ($update->settings as $name => $setting) {
            $result[$name] = $setting;
        }

        return $result;
    }

    /**
     * The references a file field gets for the pictures Word holds: the reference an exported
     * picture came from, or else one to the same file, is kept (with alt text and caption
     * updated); other pictures are stored in FAL and referenced — an exported picture used once
     * more refers to its file again — and references to pictures no longer in Word are deleted.
     *
     * @param array<string, mixed> $record
     * @param list<Figure> $figures
     *
     * @return list<string> Reference uids and NEW ids, in Word's order
     */
    private function references(ApplyJob $job, string $table, array $record, string $field, array $figures): array
    {
        $current = $this->records->fileReferences($table, $record, $field, $job->plan->workspaceId);
        $used = [];
        $ids = [];
        foreach ($figures as $figure) {
            $image = $figure->image;
            $available = static fn(FileReference $reference): bool => !isset($used[$reference->getUid()])
                && $reference->getOriginalFile()->getSha1() === $image->identity();
            $match = array_find($current, static fn(FileReference $reference): bool => $image->referenceUid > 0 && $reference->getUid() === $image->referenceUid && $available($reference))
                ?? array_find($current, $available);
            if ($match === null) {
                array_push($ids, ...$this->newReferences($job, [$figure], (int)($record['pid'] ?? $job->plan->pageUid)));
                continue;
            }
            $used[$match->getUid()] = true;
            $ids[] = (string)$match->getUid();
            $caption = trim(PlainText::ofInlines($figure->caption));
            $changes = [];
            if ($figure->image->alternative !== (string)($match->getProperty('alternative') ?? '')) {
                $changes['alternative'] = $figure->image->alternative;
            }
            if ($caption !== trim((string)($match->getProperty('description') ?? ''))) {
                $changes['description'] = $caption;
            }
            if ($changes !== []) {
                $job->data['sys_file_reference'][$match->getUid()] = $changes;
            }
        }
        // An inline child left out of the list is not deleted by the DataHandler; say so explicitly.
        foreach ($current as $reference) {
            if (!isset($used[$reference->getUid()])) {
                $job->referenceDeletions['sys_file_reference'][$reference->getUid()]['delete'] = 1;
            }
        }

        return $ids;
    }

    /**
     * @param list<Figure> $figures
     *
     * @return list<string>
     */
    private function newReferences(ApplyJob $job, array $figures, int $pid): array
    {
        $ids = [];
        foreach ($figures as $figure) {
            try {
                $file = $this->files->import($figure->image, $job->imageFolder);
            } catch (PageSyncException $exception) {
                $job->errors[] = $exception->getMessage();
                continue;
            }
            $newId = $job->newId();
            $job->data['sys_file_reference'][$newId] = [
                'uid_local' => $file->getUid(),
                'pid' => $this->pid($job, $pid),
                'alternative' => $figure->image->alternative,
                'title' => $figure->image->title,
                'description' => trim(PlainText::ofInlines($figure->caption)),
            ];
            $ids[] = $newId;
        }

        return $ids;
    }

    /**
     * The page records go on: the page itself, or the page being created.
     */
    private function pid(ApplyJob $job, int $pid): int|string
    {
        return $pid === 0 && $job->plan->newPage ? ApplyJob::NEW_PAGE : $pid;
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $data
     * @param array<string, array<int, array<string, mixed>>> $commands
     */
    private function dataHandler(array $data, array $commands, BackendUserAuthentication $user): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, $commands, $user);

        return $dataHandler;
    }

    private function collectErrors(DataHandler $dataHandler, ApplyJob $job): void
    {
        foreach ($dataHandler->errorLog as $error) {
            if (is_string($error) && $error !== '') {
                $job->errors[] = $error;
            }
        }
    }
}

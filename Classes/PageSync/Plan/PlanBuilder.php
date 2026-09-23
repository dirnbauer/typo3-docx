<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Plan;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Document\Bookmark;
use Webconsulting\DocxEditor\PageSync\Document\ContentControl;
use Webconsulting\DocxEditor\PageSync\Document\DocxDocument;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\PlainText;
use Webconsulting\DocxEditor\PageSync\Exception\PageSyncException;
use Webconsulting\DocxEditor\PageSync\Export\Fingerprint;
use Webconsulting\DocxEditor\PageSync\Export\PageExporter;
use Webconsulting\DocxEditor\PageSync\Field\FieldCodec;
use Webconsulting\DocxEditor\PageSync\Field\FieldContent;
use Webconsulting\DocxEditor\PageSync\Field\FieldUpdate;
use Webconsulting\DocxEditor\PageSync\Manifest\ControlTag;
use Webconsulting\DocxEditor\PageSync\Manifest\ManifestRecord;
use Webconsulting\DocxEditor\PageSync\Matching\CandidateProvider;
use Webconsulting\DocxEditor\PageSync\Matching\ContentTypeMatcher;
use Webconsulting\DocxEditor\PageSync\Matching\FieldMapping;
use Webconsulting\DocxEditor\PageSync\Matching\FieldPlacer;
use Webconsulting\DocxEditor\PageSync\Matching\MatchContext;
use Webconsulting\DocxEditor\PageSync\Matching\Value\CollectionValue;
use Webconsulting\DocxEditor\PageSync\Matching\Value\ValueBlocks;
use Webconsulting\DocxEditor\PageSync\Record\FigureLoader;
use Webconsulting\DocxEditor\PageSync\Record\RecordRepository;
use Webconsulting\DocxEditor\PageSync\Schema\ElementShape;
use Webconsulting\DocxEditor\PageSync\Schema\ElementShapeFactory;
use Webconsulting\DocxEditor\PageSync\Schema\FieldInfo;
use Webconsulting\DocxEditor\PageSync\Schema\FieldKind;
use Webconsulting\DocxEditor\PageSync\Schema\FieldRole;
use Webconsulting\DocxEditor\PageSync\Segmentation\Part;
use Webconsulting\DocxEditor\PageSync\Segmentation\PartAnalyzer;
use Webconsulting\DocxEditor\PageSync\Segmentation\PartItem;
use Webconsulting\DocxEditor\PageSync\Segmentation\Segmenter;

/**
 * Compares a Word document with the page it came from — or will go to — and plans what to do.
 *
 * - Elements are found by their content control (typo3:tt_content:12); failing that, by the
 *   round-trip bookmark around their content; failing that, by the fingerprint of their words.
 * - Each field is compared three ways: Word, TYPO3 now, and the export (the manifest's hash).
 *   Changed in Word only → written; in TYPO3 only → kept; in both → a conflict for the editor.
 * - Content without a control is segmented into parts, and each part is matched to the content
 *   element type that fits it best among the types the editor may create in that column.
 * - Exported elements missing from Word are proposed for deletion, never deleted unconfirmed.
 * - Elements in another order or column than in TYPO3 are moved.
 */
#[Autoconfigure(public: true)]
final readonly class PlanBuilder
{
    private const float FINGERPRINT_MATCH = 0.6;
    private const float MIN_RECOGNITION_FIT = 0.5;
    private const int PREVIEW_LENGTH = 160;

    public function __construct(
        private RecordRepository $records,
        private FigureLoader $figures,
        private ElementShapeFactory $shapes,
        private FieldCodec $codec,
        private Segmenter $segmenter,
        private PartAnalyzer $analyzer,
        private FieldPlacer $placer,
        private ContentTypeMatcher $matcher,
        private CandidateProvider $candidates,
        private BackendLayoutView $backendLayoutView,
        private SiteFinder $siteFinder,
    ) {}

    public function build(DocxDocument $document, string $documentHash, int $pageUid, int $languageId, BackendUserAuthentication $user, bool $useJev = true): SyncPlan
    {
        $workspaceId = (int)$user->workspace;
        $page = $this->records->record('pages', $pageUid, $workspaceId);
        if ($page === null) {
            throw new PageSyncException('error.pageNotFound', 404, [$pageUid]);
        }
        if (!$user->isAdmin() && (!$user->doesUserHaveAccess($page, Permission::CONTENT_EDIT) || !$user->check('tables_modify', 'tt_content'))) {
            throw new PageSyncException('error.noPageAccess', 403, [$pageUid]);
        }
        if (!$user->checkLanguageAccess($languageId)) {
            throw new PageSyncException('error.noLanguageAccess', 403, [$languageId]);
        }

        $messages = [];
        $manifest = $document->manifest;
        if ($manifest !== null && ($manifest->pageUid !== $pageUid || $manifest->language !== $languageId)) {
            $messages[] = new PlanMessage('plan.message.otherPage', [$manifest->pageUid, $manifest->language]);
            $manifest = null;
        } elseif ($manifest !== null && !$manifest->trusted) {
            $messages[] = new PlanMessage('plan.message.untrustedManifest');
        } elseif ($manifest === null) {
            $messages[] = new PlanMessage('plan.message.noManifest');
        }
        if ($manifest !== null && $manifest->workspace !== $workspaceId) {
            $messages[] = new PlanMessage('plan.message.otherWorkspace', [$manifest->workspace, $workspaceId]);
        }
        foreach ($document->warnings as $warning) {
            $messages[] = new PlanMessage($warning->labelKey, $warning->arguments);
        }

        $elements = $this->records->contentElements($pageUid, $languageId, $workspaceId);
        $connected = $languageId > 0 && !array_any(
            $elements,
            static fn(array $element): bool => (int)($element['sys_language_uid'] ?? 0) === $languageId && (int)($element['l18n_parent'] ?? 0) === 0,
        );
        $state = new PlanState($pageUid, $languageId, $workspaceId, $user, $manifest, $connected);
        foreach ($elements as $element) {
            $state->current[(int)$element['uid']] = $element;
        }
        if ($connected) {
            $translated = [];
            foreach ($elements as $element) {
                $translated[(int)($element['l18n_parent'] ?? 0)] = true;
            }
            foreach ($this->records->contentElements($pageUid, 0, $workspaceId) as $default) {
                if ((int)($default['sys_language_uid'] ?? 0) === 0 && !isset($translated[(int)$default['uid']])) {
                    $state->sources[(int)$default['uid']] = $default;
                }
            }
        }
        $state->messages = $messages;

        $columns = array_keys($this->backendLayoutView->getBackendLayoutForPage($pageUid)->getUsedColumns());
        $defaultColPos = (int)($columns[0] ?? 0);
        $this->walk($document->blocks, $defaultColPos, $state);
        $this->flushGap($state);
        $this->recogniseByFingerprint($state);
        $entries = $this->resolveParts($state, $useJev, $this->languageTag($pageUid, $languageId), $document->title);
        foreach ($this->deletions($state) as $deletion) {
            $entries[] = $deletion;
        }

        return new SyncPlan(
            pageUid: $pageUid,
            languageId: $languageId,
            workspaceId: $workspaceId,
            userId: (int)($user->user['uid'] ?? 0),
            documentHash: $documentHash,
            manifestFound: $document->manifest !== null,
            manifestTrusted: $state->trusted(),
            entries: $entries,
            page: $state->page,
            moves: $this->moves($state),
            messages: $state->messages,
        );
    }

    /**
     * @param list<Block> $blocks
     */
    private function walk(array $blocks, int $colPos, PlanState $state): void
    {
        foreach ($blocks as $block) {
            if (!$block instanceof ContentControl) {
                $this->gap($block, $colPos, $state);
                continue;
            }
            $tag = $this->resolveTag($block->tag, $state);
            if ($tag === null) {
                foreach ($block->blocks as $inner) {
                    $this->gap($inner, $colPos, $state);
                }
                continue;
            }
            if ($tag->isSummary()) {
                continue;
            }
            if ($tag->isColumn()) {
                $this->flushGap($state);
                $this->walk($block->blocks, $tag->uid, $state);
                $this->flushGap($state);
                continue;
            }
            if ($tag->table === 'pages' && $tag->field === 'title') {
                $this->pageTitle($block, $tag->uid, $state);
                continue;
            }
            if ($tag->isRecord() && $tag->table === 'tt_content') {
                $record = $state->record($tag->uid);
                if ($record !== null && !isset($state->seen[$tag->uid])) {
                    $this->flushGap($state);
                    [$entry, $leftover] = $this->elementEntry($record, $block, $colPos, $state);
                    $this->placeEntry($entry, $colPos, $state);
                    foreach ($leftover as $inner) {
                        $this->gap($inner, $colPos, $state);
                    }
                    continue;
                }
                if ($record !== null) {
                    $state->messages[] = new PlanMessage('plan.message.duplicate', [$tag->uid]);
                }
            }
            // A copy of an element, one from another page, or a stray field: its content is new content.
            foreach (self::withoutRoundTripMarkers($block->blocks) as $inner) {
                $this->gap($inner, $colPos, $state);
            }
        }
    }

    private function gap(Block $block, int $colPos, PlanState $state): void
    {
        if ($state->gap !== [] && $state->gapColPos !== $colPos) {
            $this->flushGap($state);
        }
        $state->addToGap($block, $colPos);
    }

    private function placeEntry(PlanEntry $entry, int $colPos, PlanState $state): void
    {
        $state->items[] = $entry;
        $state->seen[$entry->uid] = true;
        $state->documentOrder[$colPos][] = $entry->uid;
        $state->lastUid[$colPos] = $entry->uid;
    }

    private function flushGap(PlanState $state): void
    {
        if ($state->gap === []) {
            return;
        }
        $blocks = $state->gap;
        $colPos = $state->gapColPos;
        $afterUid = $state->gapAfterUid;
        $state->gap = [];
        foreach ($this->segmenter->segment($blocks, $state->nextId('g') . 'p') as $part) {
            if ($part->recordHint !== null && $part->recordHint[0] === 'tt_content') {
                $uid = $part->recordHint[1];
                $record = $state->record($uid);
                if ($record !== null && !isset($state->seen[$uid])) {
                    $entry = $this->recognisedEntry($record, $part, $colPos, 'bookmark', $state);
                    if ($entry !== null) {
                        $this->placeEntry($entry, $colPos, $state);
                        $afterUid = $entry->uid;
                        continue;
                    }
                }
            }
            $state->items[] = ['part' => $part, 'colPos' => $colPos, 'afterUid' => $afterUid];
        }
    }

    /**
     * An element found by its content control.
     *
     * @param array<string, mixed> $record
     *
     * @return array{0: PlanEntry, 1: list<Block>} The entry and content inside the element's
     *                                              control that belongs to none of its fields
     */
    private function elementEntry(array $record, ContentControl $control, int $colPos, PlanState $state): array
    {
        $uid = (int)$record['uid'];
        $cType = (string)($record['CType'] ?? '');
        $shape = $this->shapes->forContentType($cType);
        $manifestRecord = $state->manifest?->record('tt_content', $uid);
        $title = trim((string)($record['header'] ?? ''));
        $translationSource = isset($state->sources[$uid]);
        $readOnly = $shape === null || $control->lock->forbidsEdit() || ($manifestRecord !== null && $manifestRecord->locked)
            || (!$state->user->isAdmin() && !$state->user->recordEditAccessInternals('tt_content', $record));
        if ($readOnly || $shape === null) {
            return [new PlanEntry($state->nextId(), EntryAction::ReadOnly, 'tt_content', $uid, $cType, $colPos, title: $title), []];
        }

        [$fieldControls, $collectionControls, $leftover] = $this->fieldControls($control, 'tt_content', $uid, $state);
        $fieldBlocks = array_map(static fn(ContentControl $fieldControl): array => $fieldControl->blocks, $fieldControls);
        $locked = array_keys(array_filter($fieldControls, static fn(ContentControl $fieldControl): bool => $fieldControl->lock->forbidsEdit()));
        [$changes, $children] = $this->compare('tt_content', $record, $shape, $fieldBlocks, $collectionControls, $manifestRecord, $locked, $state);

        $entry = new PlanEntry(
            id: $state->nextId(),
            action: $this->action($changes, $children, $translationSource),
            table: 'tt_content',
            uid: $uid,
            type: $cType,
            colPos: $colPos,
            title: $title,
            fields: $changes,
            children: $children,
            messages: $leftover === [] ? [] : [new PlanMessage('plan.message.contentOutsideFields')],
        );

        return [$entry, $leftover];
    }

    /**
     * An element whose content control is gone, recognised by its bookmark or its wording: its
     * content is fitted into its own type's fields and compared from there.
     *
     * @param array<string, mixed> $record
     */
    private function recognisedEntry(array $record, Part $part, int $colPos, string $by, PlanState $state): ?PlanEntry
    {
        $uid = (int)$record['uid'];
        $cType = (string)($record['CType'] ?? '');
        $shape = $this->shapes->forContentType($cType);
        if ($shape === null || !$shape->hasContentFields()) {
            return null;
        }
        $placement = $this->placer->place($part->shape, $shape);
        if ($placement->score() < self::MIN_RECOGNITION_FIT) {
            return null;
        }
        $fieldBlocks = [];
        foreach ($placement->mapping->assignments as $assignment) {
            if ($assignment->value instanceof CollectionValue) {
                continue;
            }
            $fieldBlocks[$assignment->field->name] = ValueBlocks::of($assignment->value);
        }
        $manifestRecord = $state->manifest?->record('tt_content', $uid);
        [$changes, $children] = $this->compare('tt_content', $record, $shape, $fieldBlocks, [], $manifestRecord, [], $state);

        return new PlanEntry(
            id: $state->nextId(),
            action: $this->action($changes, $children, isset($state->sources[$uid])),
            table: 'tt_content',
            uid: $uid,
            type: $cType,
            colPos: $colPos,
            title: trim((string)($record['header'] ?? '')),
            fields: $changes,
            children: $children,
            messages: [new PlanMessage('plan.message.recognisedBy.' . $by)],
            recognisedBy: $by,
        );
    }

    /**
     * Compares the fields of a record with what Word holds for them.
     *
     * @param array<string, mixed> $record
     * @param array<string, list<Block>> $fieldBlocks Field name => what Word holds
     * @param array<string, ContentControl> $collectionControls Collection field name => its control
     * @param list<string> $lockedFields Fields whose control Word shows read-only
     *
     * @return array{0: list<FieldChange>, 1: list<PlanEntry>}
     */
    private function compare(string $table, array $record, ElementShape $shape, array $fieldBlocks, array $collectionControls, ?ManifestRecord $manifestRecord, array $lockedFields, PlanState $state): array
    {
        $changes = [];
        $children = [];
        $headingLevel = $table === 'tt_content' ? PageExporter::headerLevel($record) : 3;
        foreach ($shape->fields as $field) {
            if ($field->kind === FieldKind::Collection) {
                $control = $collectionControls[$field->name] ?? null;
                $child = $shape->child($field);
                if ($control !== null && $child !== null && !$control->lock->forbidsEdit()) {
                    array_push($children, ...$this->compareCollection($table, $record, $field, $child, $control, $state));
                }
                continue;
            }
            if (!array_key_exists($field->name, $fieldBlocks) || in_array($field->name, $lockedFields, true) || $field->kind === FieldKind::Link) {
                continue;
            }
            if (!$state->user->isAdmin() && $field->accessControlled && !$state->user->check('non_exclude_fields', $field->table . ':' . $field->name)) {
                continue;
            }
            if ($state->languageId > 0 && $field->excludedFromTranslation) {
                continue;
            }
            $manifestField = $manifestRecord?->field($field->name);
            if ($manifestRecord !== null && ($manifestField === null || $manifestField->hash === '') && $state->trusted()) {
                // Read-only in the export (no hash was recorded for it).
                continue;
            }
            $change = $this->compareField($field, $record, $fieldBlocks[$field->name], $headingLevel, $manifestField?->hash, $manifestField->level ?? 0, $state);
            if ($change !== null) {
                $changes[] = $change;
            }
        }

        return [$changes, $children];
    }

    /**
     * @param array<string, mixed> $record
     * @param list<Block> $blocks
     */
    private function compareField(FieldInfo $field, array $record, array $blocks, int $headingLevel, ?string $base, int $exportedLevel, PlanState $state): ?FieldChange
    {
        $figures = $field->kind === FieldKind::File ? $this->figures->figures($field->table, $record, $field->name, $state->workspaceId) : [];
        if ($field->kind === FieldKind::File && !$this->figures->onlyPictures($field->table, $record, $field->name, $state->workspaceId)) {
            return null;
        }
        $current = $this->codec->export($field, $record, $headingLevel, $figures);
        if ($current->locked) {
            return null;
        }
        $word = FieldContent::hashOf($this->codec->canonical($field, $blocks, $record));
        $status = self::threeWay($word, $current->hash(), $state->trusted() ? $base : null);

        $update = null;
        $levelOnly = false;
        if ($field->table === 'tt_content' && $field->name === 'header') {
            $wordLevel = self::headingLevel($blocks);
            $exported = $exportedLevel > 0 ? $exportedLevel : $headingLevel;
            if ($wordLevel > 0 && $wordLevel !== $exported && $wordLevel <= 5) {
                $update = $this->codec->update($field, $blocks, $record);
                $update = new FieldUpdate($update->value, [...$update->settings, 'header_layout' => $wordLevel]);
                if ($status === FieldStatus::Unchanged || $status === FieldStatus::ChangedInTypo3) {
                    $status = FieldStatus::Changed;
                    $levelOnly = true;
                }
            }
        }
        if ($status === FieldStatus::Unchanged) {
            return null;
        }

        return new FieldChange(
            field: $field,
            status: $status,
            wordPreview: self::preview(PlainText::ofBlocks($blocks)),
            typo3Preview: self::preview(PlainText::ofBlocks($current->blocks)),
            update: $update ?? $this->codec->update($field, $blocks, $record),
            lossy: $current->lossy,
            levelOnly: $levelOnly,
        );
    }

    /**
     * The items of a collection: kept, changed, added in Word, or gone from it.
     *
     * @param array<string, mixed> $parent
     *
     * @return list<PlanEntry>
     */
    private function compareCollection(string $parentTable, array $parent, FieldInfo $field, ElementShape $child, ContentControl $control, PlanState $state): array
    {
        $current = [];
        foreach ($this->records->children($parentTable, $parent, $field->name, $state->workspaceId) as $record) {
            $current[(int)$record['uid']] = $record;
        }
        $entries = [];
        $seen = [];
        $gap = [];
        $afterUid = 0;
        foreach ($control->blocks as $block) {
            $tag = $block instanceof ContentControl ? $this->resolveTag($block->tag, $state) : null;
            if ($block instanceof ContentControl && $tag !== null && $tag->isRecord() && $tag->table === $child->table
                && isset($current[$tag->uid]) && !isset($seen[$tag->uid])
            ) {
                array_push($entries, ...$this->newItems($gap, $afterUid, $child, $field, $state));
                $gap = [];
                $record = $current[$tag->uid];
                [$fieldControls] = $this->fieldControls($block, $child->table, $tag->uid, $state);
                $fieldBlocks = array_map(static fn(ContentControl $fieldControl): array => $fieldControl->blocks, $fieldControls);
                $locked = array_keys(array_filter($fieldControls, static fn(ContentControl $fieldControl): bool => $fieldControl->lock->forbidsEdit()));
                $manifestRecord = $state->manifest?->record($child->table, $tag->uid);
                [$changes] = $this->compare($child->table, $record, $child, $fieldBlocks, [], $manifestRecord, $locked, $state);
                $entries[] = new PlanEntry(
                    id: $state->nextId('c'),
                    action: $block->lock->forbidsEdit() ? EntryAction::ReadOnly : $this->action($changes, [], false),
                    table: $child->table,
                    uid: $tag->uid,
                    title: self::recordTitle($record, $child),
                    fields: $changes,
                    parentField: $field->name,
                );
                $seen[$tag->uid] = true;
                $afterUid = $tag->uid;
                continue;
            }
            if ($block instanceof ContentControl) {
                array_push($gap, ...self::withoutRoundTripMarkers($block->blocks));
                continue;
            }
            $gap[] = $block;
        }
        array_push($entries, ...$this->newItems($gap, $afterUid, $child, $field, $state));

        // Items exported with the element but gone from Word.
        foreach ($state->manifest?->childrenOf($parentTable . ':' . RecordRepository::liveUid($parent), $field->name) ?? [] as $exported) {
            if ($exported->table === $child->table && isset($current[$exported->uid]) && !isset($seen[$exported->uid]) && !$exported->locked) {
                $entries[] = new PlanEntry(
                    id: $state->nextId('c'),
                    action: EntryAction::Delete,
                    table: $child->table,
                    uid: $exported->uid,
                    title: self::recordTitle($current[$exported->uid], $child),
                    parentField: $field->name,
                );
            }
        }

        return $entries;
    }

    /**
     * New collection items from content in the collection's control that belongs to no item.
     *
     * @param list<Block> $blocks
     *
     * @return list<PlanEntry>
     */
    private function newItems(array $blocks, int $afterUid, ElementShape $child, FieldInfo $field, PlanState $state): array
    {
        $entries = [];
        foreach ($this->items($blocks) as $item) {
            $placement = $this->placer->placeItem($item, $child);
            $entries[] = new PlanEntry(
                id: $state->nextId('c'),
                action: EntryAction::Create,
                table: $child->table,
                uid: 0,
                afterUid: $afterUid,
                title: $item->titleText(),
                fields: self::newFieldChanges($placement->mapping),
                parentField: $field->name,
                mapping: $placement->mapping,
            );
        }

        return $entries;
    }

    /**
     * Unmarked content inside a collection: items as the part analysis finds them, or one item
     * with the first line as its title.
     *
     * @param list<Block> $blocks
     *
     * @return list<PartItem>
     */
    private function items(array $blocks): array
    {
        $blocks = PartAnalyzer::flatten($blocks);
        if ($blocks === []) {
            return [];
        }
        $detected = $this->analyzer->detectItems($blocks, 0);
        if ($detected !== null && $detected[0] === []) {
            return $detected[1];
        }
        $first = $blocks[0];
        $rest = array_slice($blocks, 1);
        $title = $first instanceof Heading ? $first->inlines : [];
        if ($title === []) {
            return [new PartItem([], $blocks)];
        }

        return [new PartItem($title, $rest)];
    }

    private function pageTitle(ContentControl $control, int $uid, PlanState $state): void
    {
        $field = new FieldInfo('pages', 'title', 'LLL:EXT:frontend/Resources/Private/Language/locallang_tca.xlf:pages.title', FieldKind::Input, FieldRole::Heading);
        $record = $state->languageId > 0
            ? $this->records->pageInLanguage($state->pageUid, $state->languageId, $state->workspaceId)
            : $this->records->record('pages', $state->pageUid, $state->workspaceId);
        $translate = $record === null;
        $record ??= $this->records->record('pages', $state->pageUid, $state->workspaceId) ?? [];
        if ($control->lock->forbidsEdit() || (!$state->user->isAdmin() && !$state->user->check('tables_modify', 'pages'))) {
            return;
        }
        $manifestField = $state->manifest?->record('pages', $uid)?->field('title');
        $change = $this->compareField($field, $record, $control->blocks, 1, $manifestField?->hash, 1, $state);
        if ($change === null) {
            return;
        }
        $state->page = new PlanEntry(
            id: 'page',
            action: $translate ? EntryAction::Translate : $this->action([$change], [], false),
            table: 'pages',
            uid: (int)($record['uid'] ?? $state->pageUid),
            title: $change->wordPreview,
            fields: [$change],
        );
    }

    /**
     * Parts that are no element's content but read like an exported element that lost its
     * control and its bookmark: close enough in wording, and fitting the element's type.
     */
    private function recogniseByFingerprint(PlanState $state): void
    {
        if (!$state->trusted() || $state->manifest === null) {
            return;
        }
        $missing = [];
        foreach ($state->manifest->elements() as $exported) {
            if (!isset($state->seen[$exported->uid]) && $exported->fingerprint !== '' && !$exported->locked && $state->record($exported->uid) !== null) {
                $missing[$exported->uid] = $exported;
            }
        }
        if ($missing === []) {
            return;
        }
        foreach ($state->items as $index => $item) {
            if ($item instanceof PlanEntry) {
                continue;
            }
            $fingerprint = Fingerprint::of(PlainText::ofBlocks($item['part']->blocks));
            $best = null;
            $bestSimilarity = self::FINGERPRINT_MATCH;
            foreach ($missing as $uid => $exported) {
                $similarity = Fingerprint::similarity($fingerprint, $exported->fingerprint);
                if ($similarity >= $bestSimilarity) {
                    $best = $uid;
                    $bestSimilarity = $similarity;
                }
            }
            $record = $best === null ? null : $state->record($best);
            if ($best === null || $record === null) {
                continue;
            }
            $entry = $this->recognisedEntry($record, $item['part'], $item['colPos'], 'fingerprint', $state);
            if ($entry === null) {
                continue;
            }
            $state->items[$index] = $entry;
            $state->seen[$best] = true;
            unset($missing[$best]);
        }
    }

    /**
     * Matches the new parts to content element types, column by column, and turns every item
     * into a plan entry.
     *
     * @return list<PlanEntry>
     */
    private function resolveParts(PlanState $state, bool $useJev, string $languageTag, string $documentTitle): array
    {
        $byColumn = [];
        foreach ($state->items as $item) {
            if (!$item instanceof PlanEntry) {
                $byColumn[$item['colPos']][] = $item['part'];
            }
        }
        $matches = [];
        foreach ($byColumn as $colPos => $parts) {
            if ($state->connectedTranslation) {
                continue;
            }
            $candidates = $this->candidates->forColumn($state->pageUid, $colPos, $state->user);
            $context = new MatchContext($state->pageUid, $colPos, $state->languageId, $languageTag, $documentTitle, $useJev);
            foreach ($this->matcher->match($parts, $candidates, $context) as $match) {
                $matches[$match->part->id] = $match;
            }
        }

        $entries = [];
        foreach ($state->items as $item) {
            if ($item instanceof PlanEntry) {
                $entries[] = $item;
                continue;
            }
            $part = $item['part'];
            $match = $matches[$part->id] ?? null;
            $title = $part->shape->headingText() !== '' ? $part->shape->headingText() : self::preview($part->shape->excerpt(), 80);
            if ($state->connectedTranslation) {
                $entries[] = new PlanEntry($state->nextId(), EntryAction::Skip, 'tt_content', 0, '', $item['colPos'], $item['afterUid'], $title, messages: [new PlanMessage('plan.message.connectedTranslation')]);
                continue;
            }
            if ($match === null || $match->chosen === null) {
                $entries[] = new PlanEntry($state->nextId(), EntryAction::Skip, 'tt_content', 0, '', $item['colPos'], $item['afterUid'], $title, match: $match, messages: [new PlanMessage('plan.message.noType')]);
                continue;
            }
            $entries[] = new PlanEntry(
                id: $state->nextId(),
                action: EntryAction::Create,
                table: 'tt_content',
                uid: 0,
                type: $match->chosen->cType,
                colPos: $item['colPos'],
                afterUid: $item['afterUid'],
                title: $title,
                fields: self::newFieldChanges($match->chosen->mapping()),
                match: $match,
            );
        }

        return $entries;
    }

    /**
     * Exported elements that are gone from the document.
     *
     * @return list<PlanEntry>
     */
    private function deletions(PlanState $state): array
    {
        if ($state->manifest === null) {
            return [];
        }
        $deletions = [];
        foreach ($state->manifest->elements() as $exported) {
            $record = $state->current[$exported->uid] ?? null;
            if ($record === null || isset($state->seen[$exported->uid]) || $exported->locked || $exported->translationSource) {
                continue;
            }
            $deletions[] = new PlanEntry(
                id: $state->nextId(),
                action: EntryAction::Delete,
                table: 'tt_content',
                uid: $exported->uid,
                type: (string)($record['CType'] ?? ''),
                colPos: (int)($record['colPos'] ?? 0),
                title: trim((string)($record['header'] ?? '')),
            );
        }

        return $deletions;
    }

    /**
     * The fewest moves that bring the existing elements into the document's order: elements on
     * the longest run already in TYPO3's order stay, the others move behind their predecessor.
     *
     * @return list<PlanMove>
     */
    private function moves(PlanState $state): array
    {
        if ($state->languageId > 0) {
            // A translation follows the default language's order.
            return [];
        }
        $position = [];
        $index = 0;
        foreach ($state->current as $uid => $record) {
            $position[$uid] = [(int)($record['colPos'] ?? 0), $index++];
        }
        $moves = [];
        foreach ($state->documentOrder as $colPos => $uids) {
            $uids = array_values(array_filter($uids, static fn(int $uid): bool => isset($position[$uid])));
            $inColumn = array_values(array_filter($uids, static fn(int $uid): bool => $position[$uid][0] === $colPos));
            $keep = array_flip(self::longestIncreasingRun($inColumn, $position));
            foreach ($uids as $i => $uid) {
                if (isset($keep[$uid])) {
                    continue;
                }
                $moves[] = new PlanMove($uid, $colPos, $uids[$i - 1] ?? 0, $position[$uid][0] !== $colPos);
            }
        }

        return $moves;
    }

    /**
     * @param list<int> $uids
     * @param array<int, array{0: int, 1: int}> $position
     *
     * @return list<int>
     */
    private static function longestIncreasingRun(array $uids, array $position): array
    {
        $count = count($uids);
        if ($count === 0) {
            return [];
        }
        $length = array_fill(0, $count, 1);
        $previous = array_fill(0, $count, -1);
        for ($i = 1; $i < $count; $i++) {
            for ($j = 0; $j < $i; $j++) {
                if ($position[$uids[$j]][1] < $position[$uids[$i]][1] && $length[$i] < $length[$j] + 1) {
                    $length[$i] = $length[$j] + 1;
                    $previous[$i] = $j;
                }
            }
        }
        $end = (int)array_search(max($length), $length, true);
        $run = [];
        for ($i = $end; $i >= 0; $i = $previous[$i]) {
            $run[] = $uids[$i];
        }

        return array_reverse($run);
    }

    /**
     * The controls of a record's fields, the controls of its collections, and content inside the
     * record's control that is in neither.
     *
     * @return array{0: array<string, ContentControl>, 1: array<string, ContentControl>, 2: list<Block>}
     */
    private function fieldControls(ContentControl $control, string $table, int $uid, PlanState $state): array
    {
        $fields = [];
        $collections = [];
        $leftover = [];
        foreach ($control->blocks as $block) {
            if ($block instanceof Bookmark) {
                continue;
            }
            if (!$block instanceof ContentControl) {
                $leftover[] = $block;
                continue;
            }
            $tag = $this->resolveTag($block->tag, $state);
            if ($tag !== null && $tag->isField() && $tag->table === $table && $tag->uid === $uid) {
                if ($this->holdsRecords($block, $state)) {
                    $collections[$tag->field] = $block;
                } else {
                    $fields[$tag->field] = $block;
                }
                continue;
            }
            if ($tag !== null && $tag->isSummary()) {
                continue;
            }
            array_push($leftover, ...self::withoutRoundTripMarkers($block->blocks));
        }

        return [$fields, $collections, $leftover];
    }

    /**
     * A field control that holds record controls is a collection.
     */
    private function holdsRecords(ContentControl $control, PlanState $state): bool
    {
        foreach ($control->blocks as $block) {
            if ($block instanceof ContentControl) {
                $tag = $this->resolveTag($block->tag, $state);
                if ($tag !== null && $tag->isRecord()) {
                    return true;
                }
            }
        }
        $field = null;
        $tag = $this->resolveTag($control->tag, $state);
        if ($tag !== null && $tag->table === 'tt_content') {
            $record = $state->record($tag->uid);
            $shape = $record === null ? null : $this->shapes->forContentType((string)($record['CType'] ?? ''));
            $field = $shape?->field($tag->field);
        }

        return $field !== null && $field->kind === FieldKind::Collection;
    }

    private function resolveTag(string $tag, PlanState $state): ?ControlTag
    {
        $parsed = ControlTag::parse($tag);
        if ($parsed === null || !$parsed->isReference()) {
            return $parsed;
        }

        return $state->manifest?->resolve($parsed);
    }

    /**
     * @param list<FieldChange> $changes
     * @param list<PlanEntry> $children
     */
    private function action(array $changes, array $children, bool $translationSource): EntryAction
    {
        $writes = array_any($changes, static fn(FieldChange $change): bool => $change->status->writes())
            || array_any($children, static fn(PlanEntry $child): bool => $child->hasWrites());
        if ($translationSource) {
            return $writes ? EntryAction::Translate : EntryAction::Unchanged;
        }
        if (array_any($changes, static fn(FieldChange $change): bool => $change->status === FieldStatus::Conflict)) {
            return EntryAction::Conflict;
        }

        return $writes ? EntryAction::Update : EntryAction::Unchanged;
    }

    private static function threeWay(string $word, string $current, ?string $base): FieldStatus
    {
        if ($word === $current) {
            return FieldStatus::Unchanged;
        }
        if ($base === null || $base === '') {
            return FieldStatus::Conflict;
        }
        if ($word === $base) {
            return FieldStatus::ChangedInTypo3;
        }
        if ($current === $base) {
            return FieldStatus::Changed;
        }

        return FieldStatus::Conflict;
    }

    /**
     * @return list<FieldChange>
     */
    private static function newFieldChanges(FieldMapping $mapping): array
    {
        return array_map(
            static fn($assignment): FieldChange => new FieldChange(
                field: $assignment->field,
                status: FieldStatus::New,
                wordPreview: self::preview($assignment->value->preview()),
                typo3Preview: '',
            ),
            $mapping->assignments,
        );
    }

    /**
     * @param list<Block> $blocks
     */
    private static function headingLevel(array $blocks): int
    {
        foreach ($blocks as $block) {
            if ($block instanceof Heading) {
                return $block->level;
            }
            if ($block instanceof ContentControl) {
                return self::headingLevel($block->blocks);
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function recordTitle(array $record, ElementShape $shape): string
    {
        $field = $shape->firstWithRole(FieldRole::Heading) ?? $shape->firstWithRole(FieldRole::Label) ?? $shape->firstWithRole(FieldRole::Value);
        $value = $field === null ? '' : ($record[$field->name] ?? '');

        return self::preview(is_scalar($value) ? strip_tags((string)$value) : '', 80);
    }

    /**
     * Blocks of a copied control without the markers that name the original.
     *
     * @param list<Block> $blocks
     *
     * @return list<Block>
     */
    private static function withoutRoundTripMarkers(array $blocks): array
    {
        $result = [];
        foreach ($blocks as $block) {
            if ($block instanceof Bookmark) {
                continue;
            }
            if ($block instanceof ContentControl) {
                array_push($result, ...self::withoutRoundTripMarkers($block->blocks));
                continue;
            }
            $result[] = $block;
        }

        return $result;
    }

    private static function preview(string $text, int $length = self::PREVIEW_LENGTH): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)) . '…' : $text;
    }

    private function languageTag(int $pageUid, int $languageId): string
    {
        try {
            return str_replace('_', '-', $this->siteFinder->getSiteByPageId($pageUid)->getLanguageById($languageId)->getLocale()->getName());
        } catch (SiteNotFoundException|\InvalidArgumentException) {
            return '';
        }
    }
}

<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Export;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use Webconsulting\DocxEditor\PageSync\Configuration\PageSyncSettings;
use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Document\Bookmark;
use Webconsulting\DocxEditor\PageSync\Document\ContentControl;
use Webconsulting\DocxEditor\PageSync\Document\ControlLock;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\Link;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\PlainText;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Exception\PageSyncException;
use Webconsulting\DocxEditor\PageSync\Field\FieldCodec;
use Webconsulting\DocxEditor\PageSync\Field\FieldContent;
use Webconsulting\DocxEditor\PageSync\Manifest\ControlTag;
use Webconsulting\DocxEditor\PageSync\Manifest\ManifestField;
use Webconsulting\DocxEditor\PageSync\Manifest\RoundTripManifest;
use Webconsulting\DocxEditor\PageSync\Ooxml\DocumentWriter;
use Webconsulting\DocxEditor\PageSync\Ooxml\WriteRequest;
use Webconsulting\DocxEditor\PageSync\Record\FigureLoader;
use Webconsulting\DocxEditor\PageSync\Record\RecordRepository;
use Webconsulting\DocxEditor\PageSync\Schema\ElementShape;
use Webconsulting\DocxEditor\PageSync\Schema\ElementShapeFactory;
use Webconsulting\DocxEditor\PageSync\Schema\FieldInfo;
use Webconsulting\DocxEditor\PageSync\Schema\FieldKind;
use Webconsulting\DocxEditor\PageSync\Schema\FieldRole;

/**
 * Writes one page in one language as a Word document the round trip can bring back.
 *
 * The page title comes first, then each column of the page's backend layout with its content
 * elements. Every element is a content control tagged with its record, holding one control per
 * editable field — a collection holds one control per child record. What Word cannot edit
 * (plugins, links, non-image files, rich text with embedded media) is shown read-only. The
 * manifest records the hash of every exported field, so the import can tell what changed where.
 */
#[Autoconfigure(public: true)]
final readonly class PageExporter
{
    public const int DEFAULT_HEADER_LEVEL = 2;
    private const int CHILD_HEADING_LEVEL = 3;

    public function __construct(
        private RecordRepository $records,
        private FigureLoader $figures,
        private LinkLabels $links,
        private ElementShapeFactory $shapes,
        private FieldCodec $codec,
        private DocumentWriter $writer,
        private PageSyncSettings $settings,
        private SiteFinder $siteFinder,
        private BackendLayoutView $backendLayoutView,
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function export(int $pageUid, int $languageId, BackendUserAuthentication $user): ExportResult
    {
        $workspaceId = (int)$user->workspace;
        $page = $this->records->record('pages', $pageUid, $workspaceId);
        if ($page === null) {
            throw new PageSyncException('error.pageNotFound', 404, [$pageUid]);
        }
        if (!$user->isAdmin() && (!$user->doesUserHaveAccess($page, Permission::PAGE_SHOW) || !$user->check('tables_select', 'tt_content'))) {
            throw new PageSyncException('error.noPageAccess', 403, [$pageUid]);
        }
        if (!$user->checkLanguageAccess($languageId)) {
            throw new PageSyncException('error.noLanguageAccess', 403, [$languageId]);
        }
        $site = $this->site($pageUid);
        $languageTag = '';
        if ($site !== null) {
            try {
                $languageTag = $site->getLanguageById($languageId)->getLocale()->getName();
            } catch (\InvalidArgumentException) {
                throw new PageSyncException('error.languageNotInSite', 400, [$languageId]);
            }
        }
        $pageInLanguage = $this->records->pageInLanguage($pageUid, $languageId, $workspaceId) ?? $page;
        $labels = $this->languageServiceFactory->createFromUserPreferences($user);

        $state = new ExportState();
        $blocks = [];

        // The page title.
        $titleField = new FieldInfo('pages', 'title', 'LLL:EXT:frontend/Resources/Private/Language/locallang_tca.xlf:pages.title', FieldKind::Input, FieldRole::Heading);
        $titleRecordUid = (int)($pageInLanguage['uid'] ?? $pageUid);
        $title = $this->codec->export($titleField, $pageInLanguage, 1);
        $blocks[] = new ContentControl(
            $this->tag($state, 'pages', $titleRecordUid, 'title'),
            $this->label($labels, 'alias.pageTitle'),
            $title->blocks === [] ? [new Heading(1, [new Text('')])] : $title->blocks,
        );
        $state->record('pages', $titleRecordUid, ['title' => new ManifestField('title', $title->hash(), 1)], language: $languageId);

        // The content, column by column.
        $elements = $this->elementsToExport($pageUid, $languageId, $workspaceId);
        $layout = $this->backendLayoutView->getBackendLayoutForPage($pageUid);
        $columns = $layout->getUsedColumns();
        if ($columns === []) {
            $columns = [0 => ''];
        }
        $wrapColumns = count($columns) > 1;
        $skipped = [];
        foreach ($elements as $element) {
            $colPos = (int)($element['colPos'] ?? 0);
            if (!array_key_exists($colPos, $columns) || (int)($element['tx_container_parent'] ?? 0) > 0) {
                $skipped[] = (int)$element['uid'];
            }
        }
        foreach ($columns as $colPos => $columnName) {
            $columnBlocks = [];
            foreach ($elements as $element) {
                if ((int)($element['colPos'] ?? 0) !== $colPos || in_array((int)$element['uid'], $skipped, true)) {
                    continue;
                }
                $columnBlocks[] = $this->element($element, $languageId, $user, $labels, $state);
            }
            if ($wrapColumns) {
                $name = trim($labels->sL((string)$columnName));
                $blocks[] = new ContentControl(
                    ControlTag::column((int)$colPos)->toString(),
                    sprintf($this->label($labels, 'alias.column'), $name !== '' ? $name : (string)$colPos),
                    $columnBlocks === [] ? [new Paragraph([])] : $columnBlocks,
                );
                continue;
            }
            array_push($blocks, ...$columnBlocks);
        }

        $manifest = new RoundTripManifest(
            pageUid: $pageUid,
            siteIdentifier: $site?->getIdentifier() ?? '',
            language: $languageId,
            workspace: $workspaceId,
            exportedAt: new \DateTimeImmutable(),
            records: $state->manifestRecords(),
            exportedBy: (int)($user->user['uid'] ?? 0),
        );
        $pageTitle = trim((string)($pageInLanguage['title'] ?? ''));
        $binary = $this->writer->write(new WriteRequest(
            blocks: $blocks,
            manifest: $manifest,
            title: $pageTitle,
            creator: trim((string)($user->user['realName'] ?? '')) ?: (string)($user->user['username'] ?? ''),
            languageTag: str_replace('_', '-', $languageTag),
            customProperties: [
                'TYPO3 page' => (string)$pageUid,
                'TYPO3 language' => (string)$languageId,
                'TYPO3 site' => $site?->getIdentifier() ?? '',
                'TYPO3 workspace' => (string)$workspaceId,
            ],
            templatePath: $this->settings->wordTemplate(),
        ));

        return new ExportResult(
            $binary,
            self::fileName($pageTitle, $pageUid, $languageTag),
            $manifest,
            $state->elementCount,
            $skipped,
        );
    }

    /**
     * The elements of the language; for a translation in connected mode also the default
     * language's elements that have no translation yet, as sources to translate.
     *
     * @return list<array<string, mixed>>
     */
    private function elementsToExport(int $pageUid, int $languageId, int $workspaceId): array
    {
        $elements = $this->records->contentElements($pageUid, $languageId, $workspaceId);
        if ($languageId <= 0) {
            return $elements;
        }
        $translated = [];
        $freeMode = false;
        foreach ($elements as $element) {
            $parent = (int)($element['l18n_parent'] ?? 0);
            if ((int)($element['sys_language_uid'] ?? 0) === $languageId) {
                if ($parent > 0) {
                    $translated[$parent] = true;
                } else {
                    $freeMode = true;
                }
            }
        }
        if ($freeMode) {
            return $elements;
        }

        // Connected mode: the default language's order, the translation in place of its source.
        $byParent = [];
        foreach ($elements as $element) {
            $parent = (int)($element['l18n_parent'] ?? 0);
            if ($parent > 0) {
                $byParent[$parent] = $element;
            }
        }
        $result = [];
        foreach ($this->records->contentElements($pageUid, 0, $workspaceId) as $default) {
            $uid = RecordRepository::liveUid($default);
            if ((int)($default['sys_language_uid'] ?? 0) === -1) {
                $result[] = $default;
                continue;
            }
            if (isset($byParent[$uid])) {
                $result[] = $byParent[$uid];
                continue;
            }
            $default['_docx_translation_source'] = true;
            $result[] = $default;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $element
     */
    private function element(array $element, int $languageId, BackendUserAuthentication $user, LanguageService $labels, ExportState $state): ContentControl
    {
        $uid = (int)$element['uid'];
        $cType = (string)($element['CType'] ?? '');
        $shape = $this->shapes->forContentType($cType);
        $typeLabel = $shape === null ? $cType : trim($labels->sL($shape->label));
        $typeLabel = $typeLabel !== '' ? $typeLabel : $cType;
        $translationSource = ($element['_docx_translation_source'] ?? false) === true;
        $allLanguages = (int)($element['sys_language_uid'] ?? 0) === -1 && $languageId > 0;
        $editable = $shape !== null && $shape->hasContentFields() && !$allLanguages
            && (int)($element['tx_container_parent'] ?? 0) === 0 && $this->mayEdit($user, $element);

        $state->elementCount++;
        $position = $state->elementCount;
        $bookmark = Bookmark::nameFor('tt_content', $uid);
        if (!$editable || $shape === null) {
            $summary = $this->summaryOf($element, $typeLabel, $labels);
            $state->record('tt_content', $uid, [], $cType, (int)($element['colPos'] ?? 0), $position, locked: true, language: (int)($element['sys_language_uid'] ?? 0));

            return new ContentControl(
                $this->tag($state, 'tt_content', $uid),
                sprintf($this->label($labels, 'alias.readOnly'), $typeLabel),
                [new Bookmark($bookmark, true), ...$summary, new Bookmark($bookmark, false)],
                ControlLock::SdtContentLocked,
            );
        }

        $headerLevel = self::headerLevel($element);
        [$fieldBlocks, $manifestFields] = $this->fields('tt_content', $element, $shape, $headerLevel, $languageId, $user, $labels, $state);
        $state->record(
            'tt_content',
            $uid,
            $manifestFields,
            $cType,
            (int)($element['colPos'] ?? 0),
            $position,
            language: (int)($element['sys_language_uid'] ?? 0),
            translationSource: $translationSource,
            fingerprint: Fingerprint::of(PlainText::ofBlocks($fieldBlocks)),
        );

        return new ContentControl(
            $this->tag($state, 'tt_content', $uid),
            $translationSource ? sprintf($this->label($labels, 'alias.untranslated'), $typeLabel) : $typeLabel,
            [new Bookmark($bookmark, true), ...$fieldBlocks, new Bookmark($bookmark, false)],
        );
    }

    /**
     * The field controls of a record, and the manifest entries of its fields.
     *
     * @param array<string, mixed> $record
     *
     * @return array{0: list<Block>, 1: array<string, ManifestField>}
     */
    private function fields(string $table, array $record, ElementShape $shape, int $headingLevel, int $languageId, BackendUserAuthentication $user, LanguageService $labels, ExportState $state): array
    {
        $uid = (int)$record['uid'];
        $blocks = [];
        $manifestFields = [];
        foreach ($shape->fields as $field) {
            $label = trim($labels->sL($field->label));
            $label = $label !== '' ? $label : $field->name;
            if ($field->kind === FieldKind::Collection) {
                $child = $shape->child($field);
                if ($child === null) {
                    continue;
                }
                $blocks[] = $this->collection($table, $record, $field, $child, $label, $languageId, $user, $labels, $state);
                continue;
            }
            if ($field->kind === FieldKind::Link) {
                [$control, $manifestField] = $this->link($table, $record, $field, $label, $languageId, $user, $labels, $state);
                $blocks[] = $control;
                if ($manifestField !== null) {
                    $manifestFields[$field->name] = $manifestField;
                }
                continue;
            }

            $figures = $field->kind === FieldKind::File ? $this->figures->figures($table, $record, $field->name, (int)$user->workspace) : [];
            $content = $this->codec->export($field, $record, $headingLevel, $figures);
            $writable = $this->mayEditField($user, $field) && !($languageId > 0 && $field->excludedFromTranslation)
                && ($field->kind !== FieldKind::File || $this->figures->onlyPictures($table, $record, $field->name, (int)$user->workspace));
            $locked = $content->locked || !$writable;
            $fieldBlocks = $content->blocks;
            if ($locked && $fieldBlocks === []) {
                $fieldBlocks = $this->lockedSummary($field, $content, $labels);
            }
            $blocks[] = new ContentControl(
                $this->tag($state, $table, $uid, $field->name),
                $locked ? sprintf($this->label($labels, 'alias.readOnly'), $label) : $label,
                $fieldBlocks === [] ? [self::emptyBlock($field, $headingLevel)] : $fieldBlocks,
                $locked ? ControlLock::SdtContentLocked : ControlLock::Unlocked,
            );
            if (!$locked) {
                $manifestFields[$field->name] = new ManifestField(
                    $field->name,
                    $content->hash(),
                    $field->role === FieldRole::Heading ? $headingLevel : 0,
                );
            }
        }

        return [$blocks, $manifestFields];
    }

    /**
     * A link field, read-only: what it points to in words, linked to the stored target (as rich
     * text links are), and the stored value in the manifest.
     *
     * @param array<string, mixed> $record
     *
     * @return array{0: ContentControl, 1: ?ManifestField}
     */
    private function link(string $table, array $record, FieldInfo $field, string $label, int $languageId, BackendUserAuthentication $user, LanguageService $labels, ExportState $state): array
    {
        $value = $record[$field->name] ?? '';
        $value = is_scalar($value) ? trim((string)$value) : '';
        $text = $value === '' ? '' : $this->links->label($value, (int)($record['pid'] ?? 0), $languageId, $user, $labels);
        $blocks = $text === ''
            ? $this->lockedSummary($field, new FieldContent([], '', true, 'lock.link'), $labels)
            : [new Paragraph([new Link($this->links->target($value), [new Text($text)])])];
        $control = new ContentControl(
            $this->tag($state, $table, (int)$record['uid'], $field->name),
            sprintf($this->label($labels, 'alias.readOnly'), $label),
            $blocks,
            ControlLock::SdtContentLocked,
        );

        return [$control, $value === '' ? null : new ManifestField($field->name, '', value: $value)];
    }

    /**
     * @param array<string, mixed> $parent
     */
    private function collection(string $parentTable, array $parent, FieldInfo $field, ElementShape $child, string $label, int $languageId, BackendUserAuthentication $user, LanguageService $labels, ExportState $state): ContentControl
    {
        $parentUid = (int)$parent['uid'];
        $childLabel = trim($labels->sL($child->label));
        $childLabel = $childLabel !== '' ? $childLabel : $child->table;
        $items = [];
        $writable = $this->mayEditField($user, $field) && ($user->isAdmin() || $user->check('tables_modify', $child->table));
        foreach ($this->records->children($parentTable, $parent, $field->name, (int)$user->workspace) as $position => $record) {
            $uid = (int)$record['uid'];
            [$fieldBlocks, $manifestFields] = $this->fields($child->table, $record, $child, self::CHILD_HEADING_LEVEL, $languageId, $user, $labels, $state);
            $state->record(
                $child->table,
                $uid,
                $writable ? $manifestFields : [],
                '',
                0,
                $position + 1,
                parent: $parentTable . ':' . $parentUid,
                parentField: $field->name,
                locked: !$writable,
                language: (int)($record['sys_language_uid'] ?? 0),
            );
            $items[] = new ContentControl(
                $this->tag($state, $child->table, $uid),
                $childLabel,
                $fieldBlocks,
                $writable ? ControlLock::Unlocked : ControlLock::SdtContentLocked,
            );
        }

        return new ContentControl(
            $this->tag($state, $parentTable, $parentUid, $field->name),
            $label,
            $items === [] ? [new Paragraph([])] : $items,
            $writable ? ControlLock::Unlocked : ControlLock::SdtContentLocked,
        );
    }

    /**
     * @param array<string, mixed> $element
     *
     * @return list<Block>
     */
    private function summaryOf(array $element, string $typeLabel, LanguageService $labels): array
    {
        $header = trim((string)($element['header'] ?? ''));
        $blocks = [];
        if ($header !== '') {
            $blocks[] = new Heading(self::headerLevel($element), [new Text($header)]);
        }
        $blocks[] = new Paragraph([new Text(sprintf($this->label($labels, 'summary.element'), $typeLabel))]);

        return $blocks;
    }

    /**
     * @return list<Block>
     */
    private function lockedSummary(FieldInfo $field, FieldContent $content, LanguageService $labels): array
    {
        $key = $content->lockReason !== '' ? 'summary.' . $content->lockReason : 'summary.lock.field';

        return [new Paragraph([new Text($this->label($labels, $key))])];
    }

    private static function emptyBlock(FieldInfo $field, int $headingLevel): Block
    {
        return $field->role === FieldRole::Heading ? new Heading(max(1, min(6, $headingLevel)), []) : new Paragraph([]);
    }

    /**
     * @param array<string, mixed> $element
     */
    private function mayEdit(BackendUserAuthentication $user, array $element): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->check('tables_modify', 'tt_content')
            && $user->recordEditAccessInternals('tt_content', $element);
    }

    private function mayEditField(BackendUserAuthentication $user, FieldInfo $field): bool
    {
        return $user->isAdmin() || !$field->accessControlled || $user->check('non_exclude_fields', $field->table . ':' . $field->name);
    }

    private function tag(ExportState $state, string $table, int $uid, string $field = ''): string
    {
        $tag = $field === '' ? ControlTag::record($table, $uid) : ControlTag::field($table, $uid, $field);
        if ($tag->fitsWord()) {
            return $tag->toString();
        }
        // Long Content Blocks table and field names: a short reference the manifest resolves.
        $reference = $state->referenceFor($table, $uid);
        $fieldReference = $field === '' ? 0 : $state->fieldReferenceFor($table, $uid, $field);

        return ControlTag::reference($reference, $fieldReference)->toString();
    }

    /**
     * The heading level of an element's header: its header_layout, or the site default.
     *
     * @param array<string, mixed> $element
     *
     * @return int<1, 5>
     */
    public static function headerLevel(array $element): int
    {
        $layout = (int)($element['header_layout'] ?? 0);

        return $layout >= 1 && $layout <= 5 ? $layout : self::DEFAULT_HEADER_LEVEL;
    }

    private function site(int $pageUid): ?Site
    {
        try {
            return $this->siteFinder->getSiteByPageId($pageUid);
        } catch (SiteNotFoundException) {
            return null;
        }
    }

    private function label(LanguageService $labels, string $key): string
    {
        $label = $labels->sL('docx_editor.pagesync:export.' . $key);

        return $label !== '' ? $label : $key;
    }

    private static function fileName(string $title, int $pageUid, string $languageTag): string
    {
        $slug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: ''), '-'));
        $slug = $slug !== '' ? substr($slug, 0, 60) : 'page-' . $pageUid;
        $language = strtolower((string)preg_replace('/[^A-Za-z-]/', '', str_replace('_', '-', $languageTag)));

        return $slug . ($language !== '' ? '-' . $language : '') . '.docx';
    }
}

<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Export;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\LinkHandling\TypoLinkCodecService;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use Webconsulting\DocxEditor\PageSync\Record\RecordRepository;

/**
 * What a link field points to, in words an editor recognises — the way the backend's link field
 * explains its value: the page title and its path in the page tree, the file or folder, the
 * record's title, the e-mail address or phone number, the URL.
 *
 * Only what the user may see is named; a target that is gone or hidden from the user shows the
 * stored link with "not available".
 */
final readonly class LinkLabels
{
    public function __construct(
        private LinkService $linkService,
        private TypoLinkCodecService $typoLinkCodec,
        private RecordRepository $records,
        private TcaSchemaFactory $schemaFactory,
    ) {}

    /**
     * The target of a stored link ("t3://page?uid=12 _blank - "Title"" gives "t3://page?uid=12").
     */
    public function target(string $typoLink): string
    {
        $url = $this->typoLinkCodec->decode(trim($typoLink))['url'] ?? '';

        return is_string($url) ? trim($url) : '';
    }

    /**
     * @param int $pageUid The page the link is on (its TSconfig names the tables of record links)
     */
    public function label(string $typoLink, int $pageUid, int $languageId, BackendUserAuthentication $user, LanguageService $labels): string
    {
        $target = $this->target($typoLink);
        if ($target === '') {
            return '';
        }
        try {
            $link = $this->linkService->resolve($target);
        } catch (\Throwable) {
            return $this->unavailable($target, $labels);
        }

        $label = match ($link['type'] ?? null) {
            LinkService::TYPE_PAGE => $this->page($link, $languageId, $user, $labels),
            LinkService::TYPE_FILE => $this->file($link['file'] ?? null, $labels),
            LinkService::TYPE_FOLDER => $this->folder($link['folder'] ?? null, $labels),
            LinkService::TYPE_RECORD => $this->record($link, $pageUid, $user, $labels),
            LinkService::TYPE_EMAIL => self::text($link['email'] ?? null, $this->message($labels, 'email')),
            LinkService::TYPE_TELEPHONE => self::text($link['telephone'] ?? null, $this->message($labels, 'telephone')),
            LinkService::TYPE_URL => self::text($link['url'] ?? null, '%s'),
            // A scheme TYPO3 has no handler for is shown as it is.
            default => $target,
        };

        return $label !== '' ? $label : $this->unavailable($target, $labels);
    }

    /**
     * @param array<array-key, mixed> $link
     */
    private function page(array $link, int $languageId, BackendUserAuthentication $user, LanguageService $labels): string
    {
        $uid = is_numeric($link['pageuid'] ?? null) ? (int)$link['pageuid'] : 0;
        $page = $uid > 0 ? BackendUtility::readPageAccess($uid, $user->getPagePermsClause(Permission::PAGE_SHOW)) : false;
        if (!is_array($page) || (int)($page['uid'] ?? 0) !== $uid) {
            return '';
        }
        $title = (string)($page['title'] ?? '');
        if ($languageId > 0) {
            $translated = $this->records->pageInLanguage($uid, $languageId, (int)$user->workspace);
            $title = trim((string)($translated['title'] ?? '')) ?: $title;
        }
        $label = sprintf($this->message($labels, 'page'), trim($title), (string)($page['_thePathFull'] ?? ''));

        $fragment = is_scalar($link['fragment'] ?? null) ? trim((string)$link['fragment']) : '';
        if ($fragment !== '') {
            $element = is_numeric($fragment) ? $this->records->record('tt_content', (int)$fragment, (int)$user->workspace) : null;
            $header = $element !== null && (int)($element['pid'] ?? 0) === $uid ? trim((string)($element['header'] ?? '')) : '';
            $label .= ' #' . ($header !== '' ? $header : $fragment);
        }

        return $label;
    }

    private function file(mixed $file, LanguageService $labels): string
    {
        if (!$file instanceof File || !$file->checkActionPermission('read')) {
            return '';
        }

        return sprintf($this->message($labels, 'file'), $file->getName());
    }

    private function folder(mixed $folder, LanguageService $labels): string
    {
        if (!$folder instanceof Folder || !$folder->checkActionPermission('read')) {
            return '';
        }

        return sprintf($this->message($labels, 'folder'), $folder->getReadablePath());
    }

    /**
     * A record link ("t3://record?identifier=news&uid=5"): the table comes from the link handler
     * the page's TSconfig configures under the identifier.
     *
     * @param array<array-key, mixed> $link
     */
    private function record(array $link, int $pageUid, BackendUserAuthentication $user, LanguageService $labels): string
    {
        $identifier = is_string($link['identifier'] ?? null) ? $link['identifier'] : '';
        $uid = is_numeric($link['uid'] ?? null) ? (int)$link['uid'] : 0;
        $tsConfig = BackendUtility::getPagesTSconfig($pageUid);
        $table = $tsConfig['TCEMAIN.']['linkHandler.'][$identifier . '.']['configuration.']['table'] ?? '';
        if (!is_string($table) || $table === '' || $uid <= 0 || !$this->schemaFactory->has($table)
            || (!$user->isAdmin() && !$user->check('tables_select', $table))
        ) {
            return '';
        }
        $record = $this->records->record($table, $uid, (int)$user->workspace);
        if ($record === null || BackendUtility::readPageAccess((int)($record['pid'] ?? 0), $user->getPagePermsClause(Permission::PAGE_SHOW)) === false) {
            return '';
        }
        $tableTitle = trim($labels->sL($this->schemaFactory->get($table)->getTitle()));

        return sprintf($this->message($labels, 'record'), $tableTitle !== '' ? $tableTitle : $table, trim(BackendUtility::getRecordTitle($table, $record)));
    }

    private function unavailable(string $target, LanguageService $labels): string
    {
        return sprintf($this->message($labels, 'unavailable'), $target);
    }

    private function message(LanguageService $labels, string $key): string
    {
        $label = $labels->sL('docx_editor.pagesync:export.link.' . $key);

        return $label !== '' ? $label : '%s';
    }

    private static function text(mixed $value, string $format): string
    {
        return is_string($value) && trim($value) !== '' ? sprintf($format, trim($value)) : '';
    }
}

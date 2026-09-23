<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Backend;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use Webconsulting\DocxEditor\PageSync\Record\RecordRepository;

/**
 * Who may use the page round trip where — the same checks the exporter, the plan builders and
 * the DataHandler make, asked up front so the backend only offers what will work.
 */
final readonly class PageSyncAccess
{
    /**
     * Page types that show no content of their own: links, shortcuts, mount points, spacers
     * and folders.
     */
    private const array WITHOUT_CONTENT = [
        PageRepository::DOKTYPE_LINK,
        PageRepository::DOKTYPE_SHORTCUT,
        PageRepository::DOKTYPE_MOUNTPOINT,
        PageRepository::DOKTYPE_SPACER,
        PageRepository::DOKTYPE_SYSFOLDER,
    ];

    public function __construct(
        private RecordRepository $records,
        private SiteFinder $siteFinder,
    ) {}

    /**
     * The page exists, shows content, and its content may be edited.
     */
    public function canEditContent(int $pageUid, BackendUserAuthentication $user): bool
    {
        $page = $pageUid > 0 ? $this->records->record('pages', $pageUid, (int)$user->workspace) : null;
        if ($page === null || in_array((int)($page['doktype'] ?? 0), self::WITHOUT_CONTENT, true)) {
            return false;
        }

        return $user->isAdmin() || ($user->doesUserHaveAccess($page, Permission::CONTENT_EDIT) && $user->check('tables_modify', 'tt_content'));
    }

    public function canCreateSubpages(int $pageUid, BackendUserAuthentication $user): bool
    {
        if ($user->isAdmin()) {
            return $pageUid === 0 || $this->records->record('pages', $pageUid, (int)$user->workspace) !== null;
        }
        $page = $pageUid > 0 ? $this->records->record('pages', $pageUid, (int)$user->workspace) : null;

        return $page !== null
            && $user->doesUserHaveAccess($page, Permission::PAGE_NEW)
            && $user->check('tables_modify', 'pages')
            && $user->check('tables_modify', 'tt_content');
    }

    /**
     * The site languages of a page the user may edit, default language first.
     *
     * @return array<int, SiteLanguage>
     */
    public function languages(int $pageUid, BackendUserAuthentication $user): array
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageUid);
        } catch (SiteNotFoundException) {
            return [];
        }
        $languages = [];
        foreach ($site->getAvailableLanguages($user, false, $pageUid) as $language) {
            $languages[$language->getLanguageId()] = $language;
        }
        ksort($languages);

        return $languages;
    }
}

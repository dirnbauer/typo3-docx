<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\DocxEditor\PageSync\Configuration\PageSyncSettings;
use Webconsulting\DocxEditor\PageSync\Exception\PageSyncException;
use Webconsulting\DocxEditor\PageSync\Record\RecordRepository;
use Webconsulting\DocxEditor\PageSync\Service\PageSyncService;
use Webconsulting\DocxEditor\Service\EditorRequestResolver;

/**
 * The backend screens of the page round trip, reached from the Page module and the page tree:
 *
 *   - "Edit in Word": the page as a Word document in the embedded editor; saving shows what
 *     the import would change and writes it once the editor confirmed;
 *   - "Import Word file as new pages": upload, review, create;
 *   - "Download as Word": the page as a .docx file.
 */
#[AsController]
final readonly class PageSyncController
{
    public const string ROUTE_EDIT = 'docx_editor_page';
    public const string ROUTE_NEW_PAGES = 'docx_editor_page_new';
    public const string ROUTE_DOWNLOAD = 'docx_editor_page_download';

    private const string BUNDLE_SCRIPT = 'EXT:docx_editor/Resources/Public/Vite/docx-editor.js';
    private const string BUNDLE_STYLESHEET = 'EXT:docx_editor/Resources/Public/Vite/docx-editor.css';
    private const array STYLESHEETS = [
        'EXT:docx_editor/Resources/Public/Css/Editor.tokens.css',
        'EXT:docx_editor/Resources/Public/Css/Editor.base.css',
        'EXT:docx_editor/Resources/Public/Css/PageSync.css',
    ];

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private PageSyncService $pageSync,
        private PageSyncAccess $access,
        private PageSyncSettings $settings,
        private RecordRepository $records,
        private EditorRequestResolver $editorRequestResolver,
        private UriBuilder $uriBuilder,
        private PageRenderer $pageRenderer,
        private ComponentFactory $componentFactory,
        private IconFactory $iconFactory,
    ) {}

    public function editAction(ServerRequestInterface $request): ResponseInterface
    {
        $user = self::user();
        $pageUid = self::int($request->getQueryParams(), 'id');
        $languageId = self::int($request->getQueryParams(), 'language');
        $returnUrl = $this->returnUrl($request, $pageUid);
        $page = $this->records->record('pages', $pageUid, (int)$user->workspace);
        $languages = $this->access->languages($pageUid, $user);
        if ($page === null || !$this->access->canEditContent($pageUid, $user)) {
            return $this->renderError($request, new PageSyncException($page === null ? 'error.pageNotFound' : 'error.noPageAccess', 403, [$pageUid]), $returnUrl);
        }
        if (!isset($languages[$languageId]) || !$user->checkLanguageAccess($languageId)) {
            return $this->renderError($request, new PageSyncException('error.noLanguageAccess', 403, [$languageId]), $returnUrl);
        }
        // The page title in the chosen language, for the heading.
        $pageInLanguage = $this->records->pageInLanguage($pageUid, $languageId, (int)$user->workspace) ?? $page;

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->label('ui.edit.title'), (string)($pageInLanguage['title'] ?? ''));
        $view->getDocHeaderComponent()->setPageBreadcrumb($page);
        $view->getDocHeaderComponent()->disableAutomaticReloadButton();
        $this->addCloseButton($view, $returnUrl);
        $view->addButtonToButtonBar(
            $this->componentFactory->createSaveButton()
                ->setTitle($this->label('ui.edit.save'))
                ->setDataAttributes(['page-sync-action' => 'save']),
            ButtonBar::BUTTON_POSITION_LEFT,
            20,
        );
        $this->addWordFileButtons($view, $pageUid, $languageId);
        $this->addLanguageSelector($view, $request, $languages, $languageId);

        $this->registerAssets(true);
        $bundleBuilt = is_file(GeneralUtility::getFileAbsFileName(self::BUNDLE_SCRIPT));
        $view->assignMultiple([
            'bundleBuilt' => $bundleBuilt,
            'pageUid' => $pageUid,
            'languageId' => $languageId,
            'pageTitle' => (string)($pageInLanguage['title'] ?? ''),
            'editorLocale' => $this->editorRequestResolver->resolveEditorLocale($request),
            'returnUrl' => $returnUrl,
            'reloadUrl' => $this->editUrl($pageUid, $languageId, $returnUrl),
            'maxUploadMegabytes' => $this->settings->maxUploadMegabytes(),
        ]);

        return $view->renderResponse('Backend/PageSync/Edit');
    }

    public function newPagesAction(ServerRequestInterface $request): ResponseInterface
    {
        $user = self::user();
        $parentUid = self::int($request->getQueryParams(), 'id');
        $returnUrl = $this->returnUrl($request, $parentUid);
        $parent = $this->records->record('pages', $parentUid, (int)$user->workspace);
        if ($parent === null || !$this->access->canCreateSubpages($parentUid, $user)) {
            return $this->renderError($request, new PageSyncException('error.noPageCreation', 403, [$parentUid]), $returnUrl);
        }

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->label('ui.new.title'), (string)($parent['title'] ?? ''));
        $view->getDocHeaderComponent()->setPageBreadcrumb($parent);
        $this->addCloseButton($view, $returnUrl);
        $this->registerAssets(false);
        $view->assignMultiple([
            'parentUid' => $parentUid,
            'parentTitle' => (string)($parent['title'] ?? ''),
            'returnUrl' => $returnUrl,
            'newPagesHidden' => $this->settings->newPagesHidden(),
            'maxUploadMegabytes' => $this->settings->maxUploadMegabytes(),
        ]);

        return $view->renderResponse('Backend/PageSync/New');
    }

    public function downloadAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        try {
            $result = $this->pageSync->export(self::int($query, 'id'), self::int($query, 'language'), self::user());
        } catch (PageSyncException $exception) {
            return $this->renderError($request, $exception, $this->returnUrl($request, self::int($query, 'id')));
        }

        return new Response(
            new StreamFactory()->createStream($result->binary),
            200,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Content-Disposition' => sprintf('attachment; filename="%s"', addcslashes($result->fileName, '"\\')),
                'Content-Length' => (string)strlen($result->binary),
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function renderError(ServerRequestInterface $request, PageSyncException $exception, string $returnUrl): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->label('ui.edit.title'));
        $this->addCloseButton($view, $returnUrl);
        $view->assign('errorMessage', $exception->localizedMessage(self::languageService()));

        return $view->renderResponse('Backend/PageSync/Error');
    }

    private function addCloseButton(ModuleTemplate $view, string $returnUrl): void
    {
        $view->addButtonToButtonBar(
            $this->componentFactory->createCloseButton($returnUrl)->setDataAttributes(['page-sync-action' => 'close']),
            ButtonBar::BUTTON_POSITION_LEFT,
            10,
        );
    }

    private function addWordFileButtons(ModuleTemplate $view, int $pageUid, int $languageId): void
    {
        $view->addButtonToButtonBar(
            $this->componentFactory->createDropDownButton()
                ->setLabel($this->label('ui.wordFile'))
                ->setTitle($this->label('ui.wordFile'))
                ->setShowLabelText(true)
                ->setIcon($this->iconFactory->getIcon('mimetypes-word', IconSize::SMALL))
                ->addItem(
                    $this->componentFactory->createDropDownItem()
                        ->setLabel($this->label('ui.download'))
                        ->setIcon($this->iconFactory->getIcon('actions-download', IconSize::SMALL))
                        ->setHref($this->downloadUrl($pageUid, $languageId))
                        ->setAttributes(['download' => '']),
                )
                ->addItem(
                    $this->componentFactory->createDropDownItem()
                        ->setTag('button')
                        ->setLabel($this->label('ui.upload'))
                        ->setIcon($this->iconFactory->getIcon('actions-upload', IconSize::SMALL))
                        ->setAttributes(['type' => 'button', 'data-page-sync-action' => 'upload']),
                ),
            ButtonBar::BUTTON_POSITION_LEFT,
            30,
        );
    }

    /**
     * @param array<int, SiteLanguage> $languages
     */
    private function addLanguageSelector(ModuleTemplate $view, ServerRequestInterface $request, array $languages, int $current): void
    {
        if (count($languages) < 2) {
            return;
        }
        $selector = $this->componentFactory->createDropDownButton()
            ->setLabel(self::languageService()->sL('core.core:labels.language'))
            ->setShowActiveLabelText(true)
            ->setShowLabelText(true);
        $returnUrl = $this->returnUrl($request, self::int($request->getQueryParams(), 'id'));
        foreach ($languages as $languageId => $language) {
            $item = $this->componentFactory->createDropDownRadio()
                ->setLabel($language->getTitle())
                ->setHref($this->editUrl(self::int($request->getQueryParams(), 'id'), $languageId, $returnUrl))
                ->setActive($languageId === $current);
            if ($language->getFlagIdentifier() !== '') {
                $item->setIcon($this->iconFactory->getIcon($language->getFlagIdentifier(), IconSize::SMALL));
            }
            $selector->addItem($item);
        }
        $view->getDocHeaderComponent()->setLanguageSelector($selector);
    }

    private function registerAssets(bool $withEditor): void
    {
        if ($withEditor) {
            $this->pageRenderer->addCssFile(self::BUNDLE_STYLESHEET);
        }
        foreach (self::STYLESHEETS as $stylesheet) {
            $this->pageRenderer->addCssFile($stylesheet);
        }
        $this->pageRenderer->loadJavaScriptModule($withEditor ? '@webconsulting/docx-editor/page-sync/page-editor.js' : '@webconsulting/docx-editor/page-sync/new-pages.js');
    }

    private function editUrl(int $pageUid, int $languageId, string $returnUrl): string
    {
        $parameters = ['id' => $pageUid, 'language' => $languageId];
        if ($returnUrl !== '') {
            $parameters['returnUrl'] = $returnUrl;
        }

        return (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE_EDIT, $parameters);
    }

    private function downloadUrl(int $pageUid, int $languageId): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE_DOWNLOAD, ['id' => $pageUid, 'language' => $languageId]);
    }

    /**
     * Where Close leads: the returnUrl the caller passed, otherwise the Page module.
     */
    private function returnUrl(ServerRequestInterface $request, int $pageUid): string
    {
        $returnUrl = $request->getQueryParams()['returnUrl'] ?? '';
        $returnUrl = is_string($returnUrl) && $returnUrl !== '' ? GeneralUtility::sanitizeLocalUrl($returnUrl, $request) : '';

        return $returnUrl !== '' ? $returnUrl : (string)$this->uriBuilder->buildUriFromRoute('web_layout', ['id' => $pageUid]);
    }

    private function label(string $key): string
    {
        $label = self::languageService()->sL('docx_editor.pagesync:' . $key);

        return $label !== '' ? $label : $key;
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private static function int(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        return is_numeric($value) ? (int)$value : 0;
    }

    private static function user(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    private static function languageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}

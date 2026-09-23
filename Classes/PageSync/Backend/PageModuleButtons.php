<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Backend;

use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * A "Word" menu in the Page module's DocHeader: edit the page in Word, download it as a Word
 * document, import a Word document as new subpages — each only where the editor may do it.
 */
final readonly class PageModuleButtons
{
    private const string PAGE_MODULE = 'web_layout';

    public function __construct(
        private PageSyncAccess $access,
        private ComponentFactory $componentFactory,
        private IconFactory $iconFactory,
        private UriBuilder $uriBuilder,
    ) {}

    #[AsEventListener('docx-editor/page-module-buttons')]
    public function __invoke(ModifyButtonBarEvent $event): void
    {
        $request = $event->getRequest();
        $module = $request->getAttribute('module');
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$module instanceof ModuleInterface || $module->getIdentifier() !== self::PAGE_MODULE || !$user instanceof BackendUserAuthentication) {
            return;
        }
        $body = $request->getParsedBody();
        $pageUid = $request->getQueryParams()['id'] ?? (is_array($body) ? $body['id'] ?? 0 : 0);
        $pageUid = is_numeric($pageUid) ? (int)$pageUid : 0;
        if ($pageUid <= 0) {
            return;
        }
        $canEdit = $this->access->canEditContent($pageUid, $user);
        $canCreate = $this->access->canCreateSubpages($pageUid, $user);
        if (!$canEdit && !$canCreate) {
            return;
        }

        $returnUrl = (string)$this->uriBuilder->buildUriFromRoute(self::PAGE_MODULE, ['id' => $pageUid]);
        $languageId = self::selectedLanguage($request->getAttribute('moduleData'));
        $menu = $this->componentFactory->createDropDownButton()
            ->setLabel($this->label('ui.wordFile'))
            ->setTitle($this->label('ui.wordFile'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('mimetypes-word', IconSize::SMALL));
        if ($canEdit) {
            $menu->addItem(
                $this->componentFactory->createDropDownItem()
                    ->setLabel($this->label('ui.editInWord'))
                    ->setIcon($this->iconFactory->getIcon('actions-open', IconSize::SMALL))
                    ->setHref((string)$this->uriBuilder->buildUriFromRoute(PageSyncController::ROUTE_EDIT, ['id' => $pageUid, 'language' => $languageId, 'returnUrl' => $returnUrl])),
            );
            $menu->addItem(
                $this->componentFactory->createDropDownItem()
                    ->setLabel($this->label('ui.download'))
                    ->setIcon($this->iconFactory->getIcon('actions-download', IconSize::SMALL))
                    ->setHref((string)$this->uriBuilder->buildUriFromRoute(PageSyncController::ROUTE_DOWNLOAD, ['id' => $pageUid, 'language' => $languageId]))
                    ->setAttributes(['download' => '']),
            );
        }
        if ($canCreate) {
            $menu->addItem(
                $this->componentFactory->createDropDownItem()
                    ->setLabel($this->label('ui.importAsSubpages'))
                    ->setIcon($this->iconFactory->getIcon('actions-page-new', IconSize::SMALL))
                    ->setHref((string)$this->uriBuilder->buildUriFromRoute(PageSyncController::ROUTE_NEW_PAGES, ['id' => $pageUid, 'returnUrl' => $returnUrl])),
            );
        }

        $buttons = $event->getButtons();
        $buttons[ButtonBar::BUTTON_POSITION_LEFT][5][] = $menu;
        $event->setButtons($buttons);
    }

    /**
     * The language the Page module shows, when it shows exactly one; otherwise the default.
     */
    private static function selectedLanguage(mixed $moduleData): int
    {
        if (!$moduleData instanceof ModuleData) {
            return 0;
        }
        $languages = $moduleData->get('languages');
        if (!is_array($languages)) {
            return 0;
        }
        $languages = array_values(array_filter($languages, is_numeric(...)));

        return count($languages) === 1 ? max(0, (int)$languages[0]) : 0;
    }

    private function label(string $key): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        $label = $languageService instanceof LanguageService ? $languageService->sL('docx_editor.pagesync:' . $key) : '';

        return $label !== '' ? $label : $key;
    }
}

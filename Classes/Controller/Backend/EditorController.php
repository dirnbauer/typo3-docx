<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\DocxEditor\Exception\DocxEditorException;
use Webconsulting\DocxEditor\Service\DocxFileService;
use Webconsulting\DocxEditor\Service\EditorRequestResolver;
use Webconsulting\DocxEditor\Service\RevisionService;

/**
 * Renders the full-page editor for one .docx file. Reached through the
 * `docx_editor` backend route (file list "Edit DOCX" action), not a module;
 * the DocHeader follows core's text file editor: Close, Save (with "Save
 * and close" and "Save as…"), Download.
 */
final readonly class EditorController
{
    private const string BUNDLE_SCRIPT = 'EXT:docx_editor/Resources/Public/Vite/docx-editor.js';
    private const string BUNDLE_STYLESHEET = 'EXT:docx_editor/Resources/Public/Vite/docx-editor.css';

    /**
     * The TYPO3 theme on top of the eigenpal styles, each registered on its
     * own (no CSS @import) so TYPO3 appends its cache-busting suffix to all.
     */
    private const array STYLESHEETS = [
        'EXT:docx_editor/Resources/Public/Css/Editor.tokens.css',
        'EXT:docx_editor/Resources/Public/Css/Editor.base.css',
        'EXT:docx_editor/Resources/Public/Css/Editor.toolbar.css',
    ];

    private const array JAVASCRIPT_MODULES = [
        '@typo3/backend/element/spinner-element.js',
        '@typo3/backend/element/status-indicator-element.js',
        '@webconsulting/docx-editor/editor.js',
        '@webconsulting/docx-editor/toolbar.js',
    ];

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private DocxFileService $docxFileService,
        private RevisionService $revisionService,
        private EditorRequestResolver $editorRequestResolver,
        private UriBuilder $uriBuilder,
        private PageRenderer $pageRenderer,
        private ComponentFactory $componentFactory,
        private IconFactory $iconFactory,
    ) {}

    public function editAction(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $file = $this->docxFileService->resolveFile($this->editorRequestResolver->resolveFileIdentifier($request));
            $this->docxFileService->assertCanRead($file);
        } catch (DocxEditorException $exception) {
            return $this->renderError($request, $exception);
        }

        $parentFolder = $file->getParentFolder();
        $returnUrl = $this->returnUrl($request, $parentFolder);
        $canWrite = $this->docxFileService->canWrite($file);

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->translate('editor.title'), $file->getName());
        $view->getDocHeaderComponent()->setResourceBreadcrumb($file);
        $this->addCloseButton($view, $returnUrl);
        if ($canWrite) {
            $this->addSaveButtons($view);
        }
        $this->addDownloadButton($view, $file);
        $this->registerAssets();

        $view->assignMultiple([
            'bundleBuilt' => is_file(GeneralUtility::getFileAbsFileName(self::BUNDLE_SCRIPT)),
            'fileIdentifier' => $file->getCombinedIdentifier(),
            'fileName' => $file->getName(),
            'filePath' => $this->docxFileService->buildFilePathLabel($file),
            'revision' => $this->revisionService->getRevisionState($file->getCombinedIdentifier())['revision'],
            'canWrite' => $canWrite ? '1' : '0',
            'editorLocale' => $this->editorRequestResolver->resolveEditorLocale($request),
            'elementBrowserUrl' => (string)$this->uriBuilder->buildUriFromRoute('wizard_element_browser'),
            'defaultFolderIdentifier' => $parentFolder->getCombinedIdentifier(),
            'editorUrl' => (string)$this->uriBuilder->buildUriFromRoute('docx_editor'),
            'returnUrl' => $returnUrl,
        ]);

        return $view->renderResponse('Backend/Editor/Edit');
    }

    private function renderError(ServerRequestInterface $request, DocxEditorException $exception): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->translate('editor.title'), $this->translate('error.title'));
        $this->addCloseButton($view, $this->returnUrl($request));
        $view->assign('errorMessage', $exception->localizedMessage($this->getLanguageService()));

        return $view->renderResponse('Backend/Editor/Error');
    }

    /**
     * Where Close leads: the returnUrl the caller passed, like core's text
     * file editor, otherwise the folder of the file in the file list.
     */
    private function returnUrl(ServerRequestInterface $request, ?Folder $folder = null): string
    {
        $returnUrl = $request->getQueryParams()['returnUrl'] ?? '';
        $returnUrl = is_string($returnUrl) && $returnUrl !== '' ? GeneralUtility::sanitizeLocalUrl($returnUrl, $request) : '';

        return $returnUrl !== '' ? $returnUrl : (string)$this->uriBuilder->buildUriFromRoute(
            'media_management',
            $folder !== null ? ['id' => $folder->getCombinedIdentifier()] : [],
        );
    }

    private function registerAssets(): void
    {
        $this->pageRenderer->addCssFile(self::BUNDLE_STYLESHEET);
        foreach (self::STYLESHEETS as $stylesheet) {
            $this->pageRenderer->addCssFile($stylesheet);
        }
        foreach (self::JAVASCRIPT_MODULES as $module) {
            $this->pageRenderer->loadJavaScriptModule($module);
        }
    }

    /**
     * Toolbar.js asks before closing an editor with unsaved changes.
     */
    private function addCloseButton(ModuleTemplate $view, string $returnUrl): void
    {
        $view->addButtonToButtonBar(
            $this->componentFactory->createCloseButton($returnUrl)->setDataAttributes(['docx-action' => 'close']),
            ButtonBar::BUTTON_POSITION_LEFT,
            10,
        );
    }

    /**
     * The core save button with "Save and close" and "Save as…" in its
     * dropdown, the way FormEngine offers its save variants. The buttons
     * submit no form; toolbar.js handles them by name.
     */
    private function addSaveButtons(ModuleTemplate $view): void
    {
        $saveAndClose = $this->componentFactory->createInputButton()
            ->setName('_saveandclosedok')
            ->setValue('1')
            ->setTitle($this->getLanguageService()->sL('core.core:rm.saveCloseDoc'))
            ->setIcon($this->iconFactory->getIcon('actions-document-save-close', IconSize::SMALL));
        $saveAs = $this->componentFactory->createInputButton()
            ->setName('_saveasdok')
            ->setValue('1')
            ->setTitle($this->translate('editor.saveAs'))
            ->setIcon($this->iconFactory->getIcon('actions-save-add', IconSize::SMALL));

        $view->addButtonToButtonBar(
            $this->componentFactory->createSplitButton()
                ->addItem($this->componentFactory->createSaveButton(), true)
                ->addItem($saveAndClose)
                ->addItem($saveAs),
            ButtonBar::BUTTON_POSITION_LEFT,
            20,
        );
    }

    private function addDownloadButton(ModuleTemplate $view, File $file): void
    {
        $downloadUrl = (string)$file->getPublicUrl();
        if ($downloadUrl === '') {
            return;
        }
        if (!str_starts_with($downloadUrl, 'http://') && !str_starts_with($downloadUrl, 'https://')) {
            $downloadUrl = '/' . ltrim($downloadUrl, '/');
        }

        // <a target="_blank" download> so the browser downloads the file
        // out-of-band instead of navigating the backend content iframe, also
        // from storages on another origin, where "download" is ignored.
        $view->addButtonToButtonBar(
            $this->componentFactory->createGenericButton()
                ->setTag('a')
                ->setHref($downloadUrl)
                ->setLabel($this->translate('editor.download'))
                ->setTitle($this->translate('editor.download'))
                ->setIcon($this->iconFactory->getIcon('actions-download', IconSize::SMALL))
                ->setShowLabelText(true)
                ->setAttributes(['target' => '_blank', 'rel' => 'noopener', 'download' => $file->getName()]),
            ButtonBar::BUTTON_POSITION_LEFT,
            30,
        );
    }

    private function translate(string $key): string
    {
        $label = $this->getLanguageService()->sL('docx_editor.messages:' . $key);

        return $label !== '' ? $label : $key;
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}

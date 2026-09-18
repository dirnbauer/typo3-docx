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
 * `docx_editor` backend route (file list "Edit DOCX" action), not a module.
 */
final readonly class EditorController
{
    private const BUNDLE_SCRIPT = 'EXT:docx_editor/Resources/Public/Vite/docx-editor.js';
    private const BUNDLE_STYLESHEET = 'EXT:docx_editor/Resources/Public/Vite/docx-editor.css';

    /**
     * Labels the JavaScript side reads from the `data-labels` JSON attribute.
     */
    private const JS_LABELS = [
        'loading' => 'editor.loading',
        'saved' => 'editor.saved',
        'saveFailed' => 'editor.saveFailed',
        'saveAsPrompt' => 'editor.saveAsPrompt',
        'saveAsSuccess' => 'editor.saveAsSuccess',
        'collaborators' => 'editor.collaborators',
    ];

    private const HEADING_LABELS = [
        'group' => 'editor.headings.group',
        'heading1' => 'editor.headings.h1',
        'heading2' => 'editor.headings.h2',
        'heading3' => 'editor.headings.h3',
        'heading4' => 'editor.headings.h4',
        'heading1Title' => 'editor.headings.h1.title',
        'heading2Title' => 'editor.headings.h2.title',
        'heading3Title' => 'editor.headings.h3.title',
        'heading4Title' => 'editor.headings.h4.title',
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
        $fileIdentifier = $this->editorRequestResolver->resolveFileIdentifier($request);
        if ($fileIdentifier === '') {
            return $this->renderError($request, $this->translate('error.missingFile'));
        }

        try {
            $file = $this->docxFileService->resolveFile($fileIdentifier);
            $this->docxFileService->assertCanRead($file);
        } catch (DocxEditorException $exception) {
            return $this->renderError($request, $exception->getMessage());
        }

        $parentFolder = $file->getParentFolder();
        $canWrite = $this->docxFileService->canWrite($file);

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->translate('editor.title'), $file->getName());
        $view->getDocHeaderComponent()->setResourceBreadcrumb($file);
        $this->addBackToMediaButton($view, $parentFolder);
        $this->addDownloadButton($view, $file);
        if ($canWrite) {
            $this->addButton($view, 'editor.save', 'actions-save', '#', 20, ['identifier' => 'docx-editor-save']);
            $this->addButton($view, 'editor.saveAs', 'actions-save-add', '#', 20, ['identifier' => 'docx-editor-save-as']);
        }
        $this->registerAssets();

        $view->assignMultiple([
            'bundleBuilt' => is_file(GeneralUtility::getFileAbsFileName(self::BUNDLE_SCRIPT)),
            'fileIdentifier' => $file->getCombinedIdentifier(),
            'fileName' => $file->getName(),
            'revision' => $this->revisionService->getRevisionState($file->getCombinedIdentifier())['revision'],
            'canWrite' => $canWrite ? '1' : '0',
            'editorLocale' => $this->editorRequestResolver->resolveEditorLocale($request),
            'elementBrowserUrl' => (string)$this->uriBuilder->buildUriFromRoute('wizard_element_browser'),
            'defaultFolderIdentifier' => $parentFolder->getCombinedIdentifier(),
            'editorUrl' => (string)$this->uriBuilder->buildUriFromRoute('docx_editor'),
            'labelsJson' => $this->buildLabelsJson($this->docxFileService->buildFilePathLabel($file)),
        ]);

        return $view->renderResponse('Backend/Editor/Edit');
    }

    private function renderError(ServerRequestInterface $request, string $message): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->translate('editor.title'), $this->translate('error.title'));
        $this->addBackToMediaButton($view);
        $view->assignMultiple([
            'errorMessage' => $message,
            'mediaListUrl' => (string)$this->uriBuilder->buildUriFromRoute('media_management'),
        ]);

        return $view->renderResponse('Backend/Editor/Error');
    }

    private function registerAssets(): void
    {
        // The eigenpal/Tailwind bundle first, then the TYPO3 token overrides so
        // they win in the cascade. Each file is registered on its own (no CSS
        // @import) so TYPO3 can append its cache-busting suffix to every one.
        $this->pageRenderer->addCssFile(self::BUNDLE_STYLESHEET);
        foreach (['Editor.tokens.css', 'Editor.base.css', 'Editor.toolbar.css'] as $stylesheet) {
            $this->pageRenderer->addCssFile('EXT:docx_editor/Resources/Public/Css/' . $stylesheet);
        }
        $this->pageRenderer->loadJavaScriptModule('@webconsulting/docx-editor/editor.js');
        $this->pageRenderer->loadJavaScriptModule('@webconsulting/docx-editor/toolbar.js');
    }

    private function addBackToMediaButton(ModuleTemplate $view, ?Folder $parentFolder = null): void
    {
        $routeParams = $parentFolder !== null ? ['id' => $parentFolder->getCombinedIdentifier()] : [];
        $href = (string)$this->uriBuilder->buildUriFromRoute('media_management', $routeParams);
        $this->addButton($view, 'editor.backToMedia', 'actions-arrow-left-alt', $href, 10);
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
        // out-of-band instead of navigating the backend content iframe.
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

    /**
     * @param array<string, string> $dataAttributes
     */
    private function addButton(
        ModuleTemplate $view,
        string $labelKey,
        string $icon,
        string $href,
        int $group,
        array $dataAttributes = [],
    ): void {
        $view->addButtonToButtonBar(
            $this->componentFactory->createLinkButton()
                ->setHref($href)
                ->setTitle($this->translate($labelKey))
                ->setIcon($this->iconFactory->getIcon($icon, IconSize::SMALL))
                ->setShowLabelText(true)
                ->setDataAttributes($dataAttributes),
            ButtonBar::BUTTON_POSITION_LEFT,
            $group,
        );
    }

    private function buildLabelsJson(string $filePath): string
    {
        $labels = array_map($this->translate(...), self::JS_LABELS);
        $labels['savedDetail'] = sprintf($this->translate('editor.savedDetail'), $filePath);
        $labels['headings'] = array_map($this->translate(...), self::HEADING_LABELS);

        // Fluid escapes the attribute value; JSON_HEX_* flags would turn the
        // quotes into \u0022 and make the attribute unparseable on the client.
        return json_encode($labels, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

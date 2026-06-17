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
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use Webconsulting\DocxEditor\Exception\DocxEditorException;
use Webconsulting\DocxEditor\Service\DocxFileService;
use Webconsulting\DocxEditor\Service\RevisionService;
use Webconsulting\DocxEditor\Service\ViteAssetResolver;

final class EditorController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly DocxFileService $docxFileService,
        private readonly RevisionService $revisionService,
        private readonly ViteAssetResolver $viteAssetResolver,
        private readonly UriBuilder $uriBuilder,
        private readonly PageRenderer $pageRenderer,
        private readonly ComponentFactory $componentFactory,
        private readonly IconFactory $iconFactory,
    ) {}

    public function editAction(ServerRequestInterface $request): ResponseInterface
    {
        $fileIdentifier = $this->resolveFileIdentifier($request);
        if ($fileIdentifier === '') {
            return $this->renderError($request, 'docx_editor.mod:error.missingFile');
        }

        try {
            $file = $this->docxFileService->resolveFile($fileIdentifier);
            $this->docxFileService->assertCanRead($file);
        } catch (DocxEditorException $exception) {
            return $this->renderError($request, $exception->getMessage());
        }

        $revision = $this->revisionService->getRevisionState($file->getCombinedIdentifier());
        $parentFolder = $file->getParentFolder();
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle(
            $this->translate('docx_editor.mod:tabs.label'),
            $file->getName(),
        );

        $this->registerBuiltAssets();
        $canWrite = $this->canWrite($file);
        $this->configureDocHeader($view, $file, $parentFolder, $canWrite);

        $view->assignMultiple([
            'fileIdentifier' => $file->getCombinedIdentifier(),
            'fileName' => $file->getName(),
            'fileHash' => $this->docxFileService->getFileHash($file),
            'revision' => $revision['revision'],
            'contentHash' => $revision['contentHash'],
            'bundleBuilt' => $this->viteAssetResolver->hasBuild(),
            'canWrite' => $canWrite ? '1' : '0',
            'mediaListUrl' => (string)$this->uriBuilder->buildUriFromRoute('media_management'),
            'elementBrowserUrl' => (string)$this->uriBuilder->buildUriFromRoute('wizard_element_browser'),
            'defaultFolderIdentifier' => $parentFolder->getCombinedIdentifier(),
            'editorModuleUrl' => (string)$this->uriBuilder->buildUriFromRoute('docx_editor'),
            'editorLocale' => $this->resolveEditorLocale($request),
            'headingLabelsJson' => $this->buildHeadingLabelsJson(),
            'filePath' => $this->buildFilePathLabel($file),
        ]);

        return $view->renderResponse('Backend/Editor/Edit');
    }

    private function resolveEditorLocale(ServerRequestInterface $request): string
    {
        $backendUser = $request->getAttribute('backend.user');
        if ($backendUser instanceof BackendUserAuthentication) {
            $lang = (string)($backendUser->user['lang'] ?? '');
            if ($lang !== '' && $lang !== 'default' && str_starts_with(strtolower($lang), 'de')) {
                return 'de';
            }
        }

        return 'en';
    }

    private function configureDocHeader(
        ModuleTemplate $view,
        File $file,
        Folder $parentFolder,
        bool $canWrite,
    ): void {
        $view->getDocHeaderComponent()->setResourceBreadcrumb($file);
        $this->addBackToMediaButton($view, $parentFolder);
        $this->addDownloadButton($view, $file);

        if (!$canWrite) {
            return;
        }

        $view->addButtonToButtonBar(
            $this->componentFactory->createLinkButton()
                ->setHref('#')
                ->setTitle($this->translateLabel('editor.save'))
                ->setIcon($this->iconFactory->getIcon('actions-save', IconSize::SMALL))
                ->setShowLabelText(true)
                ->setDataAttributes(['identifier' => 'docx-editor-save']),
            ButtonBar::BUTTON_POSITION_LEFT,
            20,
        );

        $view->addButtonToButtonBar(
            $this->componentFactory->createLinkButton()
                ->setHref('#')
                ->setTitle($this->translateLabel('editor.saveAs'))
                ->setIcon($this->iconFactory->getIcon('actions-save-add', IconSize::SMALL))
                ->setShowLabelText(true)
                ->setDataAttributes(['identifier' => 'docx-editor-save-as']),
            ButtonBar::BUTTON_POSITION_LEFT,
            20,
        );
    }

    private function registerBuiltAssets(): void
    {
        $css = $this->viteAssetResolver->resolveStylesheet();
        if ($css !== null) {
            $this->pageRenderer->addCssFile($css);
        }
        // After the eigenpal/Tailwind bundle so TYPO3 token overrides win in the
        // cascade. Each partial is registered individually (not via @import in a
        // wrapper) so TYPO3 appends its own mtime cache-buster — a CSS change in a
        // partial is otherwise never re-fetched, because @import URLs are not
        // versioned. Order = tokens → base → toolbar.
        foreach (['Editor.tokens.css', 'Editor.base.css', 'Editor.toolbar.css'] as $partial) {
            $this->pageRenderer->addCssFile('EXT:docx_editor/Resources/Public/Css/' . $partial);
        }
        if ($this->viteAssetResolver->hasBuild()) {
            $this->pageRenderer->loadJavaScriptModule('@webconsulting/docx-editor/docx-editor.js');
            $this->pageRenderer->loadJavaScriptModule('@webconsulting/docx-editor/toolbar.js');
        }
    }

    private function canWrite(\TYPO3\CMS\Core\Resource\File $file): bool
    {
        try {
            $this->docxFileService->assertCanWrite($file);
            return true;
        } catch (DocxEditorException) {
            return false;
        }
    }

    private function resolveFileIdentifier(ServerRequestInterface $request): string
    {
        $query = $request->getQueryParams();
        $body = $request->getParsedBody();
        $moduleData = $request->getAttribute('moduleData');
        if ($moduleData !== null) {
            $fromModule = trim((string)$moduleData->get('file', ''));
            if ($fromModule !== '') {
                return $fromModule;
            }
        }
        $fromRequest = $query['file'] ?? $body['file'] ?? $query['target'] ?? $body['target'] ?? '';
        return is_string($fromRequest) ? trim($fromRequest) : '';
    }

    private function renderError(ServerRequestInterface $request, string $message): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle(
            $this->translate('docx_editor.mod:tabs.label'),
            $this->translate('docx_editor.mod:error.title'),
        );
        $this->addBackToMediaButton($view);
        $view->assignMultiple([
            'errorMessage' => $message,
            'mediaListUrl' => (string)$this->uriBuilder->buildUriFromRoute('media_management'),
        ]);
        return $view->renderResponse('Backend/Editor/Error');
    }

    private function addBackToMediaButton(ModuleTemplate $view, ?Folder $parentFolder = null): void
    {
        $routeParams = [];
        if ($parentFolder !== null) {
            $routeParams['id'] = $parentFolder->getCombinedIdentifier();
        }
        $mediaFolderUrl = (string)$this->uriBuilder->buildUriFromRoute('media_management', $routeParams);

        $view->addButtonToButtonBar(
            $this->componentFactory->createLinkButton()
                ->setHref($mediaFolderUrl)
                ->setTitle($this->translateLabel('editor.backToMedia'))
                ->setIcon($this->iconFactory->getIcon('actions-arrow-left-alt', IconSize::SMALL))
                ->setShowLabelText(true),
            ButtonBar::BUTTON_POSITION_LEFT,
            10,
        );
    }

    private function addDownloadButton(ModuleTemplate $view, File $file): void
    {
        $downloadUrl = $file->getPublicUrl();
        if ($downloadUrl === null || $downloadUrl === '') {
            return;
        }
        if (!preg_match('#^https?://#', $downloadUrl)) {
            $downloadUrl = '/' . ltrim($downloadUrl, '/');
        }

        // Render as <a target="_blank" download> so the browser downloads the
        // file out-of-band instead of navigating the backend content iframe.
        $view->addButtonToButtonBar(
            $this->componentFactory->createGenericButton()
                ->setTag('a')
                ->setHref($downloadUrl)
                ->setLabel($this->translateLabel('editor.download'))
                ->setTitle($this->translateLabel('editor.download'))
                ->setIcon($this->iconFactory->getIcon('actions-download', IconSize::SMALL))
                ->setShowLabelText(true)
                ->setAttributes([
                    'target' => '_blank',
                    'rel' => 'noopener',
                    'download' => $file->getName(),
                ]),
            ButtonBar::BUTTON_POSITION_LEFT,
            30,
        );
    }

    private function buildFilePathLabel(File $file): string
    {
        $storageName = $file->getStorage()->getName();
        $identifier = ltrim($file->getIdentifier(), '/');

        return $storageName !== '' ? $storageName . ' / ' . $identifier : $identifier;
    }

    private function buildHeadingLabelsJson(): string
    {
        $labels = [
            'group' => $this->translateLabel('editor.headings.group'),
            'heading1' => $this->translateLabel('editor.headings.h1'),
            'heading2' => $this->translateLabel('editor.headings.h2'),
            'heading3' => $this->translateLabel('editor.headings.h3'),
            'heading4' => $this->translateLabel('editor.headings.h4'),
            'heading1Title' => $this->translateLabel('editor.headings.h1.title'),
            'heading2Title' => $this->translateLabel('editor.headings.h2.title'),
            'heading3Title' => $this->translateLabel('editor.headings.h3.title'),
            'heading4Title' => $this->translateLabel('editor.headings.h4.title'),
        ];

        return json_encode(
            $labels,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );
    }

    private function translate(string $key): string
    {
        $label = $GLOBALS['LANG']->sL($key);
        return $label !== '' ? $label : $key;
    }

    private function translateLabel(string $key): string
    {
        $label = $GLOBALS['LANG']->sL(
            'LLL:EXT:docx_editor/Resources/Private/Language/locallang.xlf:' . $key,
        );
        return $label !== '' ? $label : $key;
    }
}

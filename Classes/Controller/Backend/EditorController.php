<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
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
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle(
            $this->translate('docx_editor.mod:tabs.label'),
            $file->getName(),
        );

        $this->registerBuiltAssets();

        $view->assignMultiple([
            'fileIdentifier' => $file->getCombinedIdentifier(),
            'fileName' => $file->getName(),
            'fileHash' => $this->docxFileService->getFileHash($file),
            'revision' => $revision['revision'],
            'contentHash' => $revision['contentHash'],
            'bundleBuilt' => $this->viteAssetResolver->hasBuild(),
            'canWrite' => $this->canWrite($file) ? '1' : '0',
            'mediaListUrl' => (string)$this->uriBuilder->buildUriFromRoute('media_management'),
        ]);

        return $view->renderResponse('Backend/Editor/Edit');
    }

    private function registerBuiltAssets(): void
    {
        $css = $this->viteAssetResolver->resolveStylesheet();
        if ($css !== null) {
            $this->pageRenderer->addCssFile($css);
        }
        $js = $this->viteAssetResolver->resolveScript();
        if ($js !== null) {
            $this->pageRenderer->addJsFile($js);
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
            $fromModule = (string)$moduleData->get('file', '');
            if ($fromModule !== '') {
                return $fromModule;
            }
        }
        return (string)($query['file'] ?? $body['file'] ?? '');
    }

    private function renderError(ServerRequestInterface $request, string $message): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle(
            $this->translate('docx_editor.mod:tabs.label'),
            $this->translate('docx_editor.mod:error.title'),
        );
        $view->assignMultiple([
            'errorMessage' => $message,
            'mediaListUrl' => (string)$this->uriBuilder->buildUriFromRoute('media_management'),
        ]);
        return $view->renderResponse('Backend/Editor/Error');
    }

    private function translate(string $key): string
    {
        $label = $GLOBALS['LANG']->sL($key);
        return $label !== '' ? $label : $key;
    }
}

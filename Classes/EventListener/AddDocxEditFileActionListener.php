<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\EventListener;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ActionGroup;
use TYPO3\CMS\Backend\Template\Components\Buttons\LinkButton;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Filelist\Event\ProcessFileListActionsEvent;
use Webconsulting\DocxEditor\Exception\DocxEditorException;
use Webconsulting\DocxEditor\Service\DocxFileService;

/**
 * Adds "Edit DOCX" to the primary and secondary actions of readable .docx
 * files in the file list.
 */
#[AsEventListener(identifier: 'docx-editor/add-docx-edit-action')]
final readonly class AddDocxEditFileActionListener
{
    public function __construct(
        private DocxFileService $docxFileService,
        private UriBuilder $uriBuilder,
        private IconFactory $iconFactory,
    ) {}

    public function __invoke(ProcessFileListActionsEvent $event): void
    {
        $resource = $event->getResource();
        if (!$resource instanceof File || !$this->docxFileService->isDocxFile($resource)) {
            return;
        }
        try {
            $this->docxFileService->assertCanRead($resource);
        } catch (DocxEditorException) {
            return;
        }

        $event->setAction($this->createEditButton($resource), 'docxEdit', ActionGroup::primary, after: 'download');
        $event->setAction($this->createEditButton($resource), 'docxEditMenu', ActionGroup::secondary, after: 'download');
    }

    private function createEditButton(File $file): LinkButton
    {
        $href = (string)$this->uriBuilder->buildUriFromRoute('docx_editor', ['file' => $file->getCombinedIdentifier()]);
        $label = $this->getLanguageService()->sL('docx_editor.messages:filelist.action.editDocx');

        return (new LinkButton())
            ->setTitle($label !== '' ? $label : 'Edit DOCX')
            ->setIcon($this->iconFactory->getIcon('mimetypes-word', IconSize::SMALL))
            ->setHref($href)
            ->setClasses('docx-editor-edit-action');
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}

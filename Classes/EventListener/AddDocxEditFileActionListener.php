<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\EventListener;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ActionGroup;
use TYPO3\CMS\Backend\Template\Components\Buttons\LinkButton;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Filelist\Event\ProcessFileListActionsEvent;
use Webconsulting\DocxEditor\Service\DocxFileService;

#[AsEventListener(
    identifier: 'docx-editor/add-docx-edit-action',
    event: ProcessFileListActionsEvent::class,
)]
final class AddDocxEditFileActionListener
{
    public function __construct(
        private readonly DocxFileService $docxFileService,
        private readonly UriBuilder $uriBuilder,
        private readonly IconFactory $iconFactory,
    ) {}

    public function __invoke(ProcessFileListActionsEvent $event): void
    {
        $resource = $event->getResource();
        if (!$resource instanceof File || !$event->isFile()) {
            return;
        }
        if (!$this->docxFileService->isDocxFile($resource)) {
            return;
        }

        try {
            $this->docxFileService->assertCanRead($resource);
        } catch (\Throwable) {
            return;
        }

        $editUrl = (string)$this->uriBuilder->buildUriFromRoute(
            'docx_editor',
            [
                'file' => $resource->getCombinedIdentifier(),
            ],
        );

        $button = new LinkButton();
        $button->setTitle($this->translate('filelist.action.editDocx'));
        $button->setIcon($this->iconFactory->getIcon('docx-editor-action-edit', IconSize::SMALL));
        $button->setHref($editUrl);
        $button->setClasses('docx-editor-edit-action');

        $event->setAction($button, 'docxEdit', ActionGroup::primary, after: 'download');
    }

    private function translate(string $key): string
    {
        $label = $GLOBALS['LANG']->sL('docx_editor:' . $key);
        return $label !== '' ? $label : $key;
    }
}

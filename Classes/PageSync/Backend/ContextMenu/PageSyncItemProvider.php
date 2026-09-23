<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Backend\ContextMenu;

use TYPO3\CMS\Backend\ContextMenu\ItemProviders\AbstractProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use Webconsulting\DocxEditor\PageSync\Backend\PageSyncAccess;
use Webconsulting\DocxEditor\PageSync\Backend\PageSyncController;

/**
 * "Edit in Word" and "Import Word file as subpages" in the page tree's context menu.
 */
final class PageSyncItemProvider extends AbstractProvider
{
    private const string CALLBACK_MODULE = '@webconsulting/docx-editor/context-menu-actions';

    /** @var array<string, array<string, string>> */
    protected $itemsConfiguration = [
        'docxEditorDivider' => [
            'type' => 'divider',
        ],
        'docxEditorEditInWord' => [
            'label' => 'docx_editor.pagesync:ui.editInWord',
            'iconIdentifier' => 'mimetypes-word',
            'callbackAction' => 'openInContent',
        ],
        'docxEditorImportAsSubpages' => [
            'label' => 'docx_editor.pagesync:ui.importAsSubpages',
            'iconIdentifier' => 'actions-page-new',
            'callbackAction' => 'openInContent',
        ],
    ];

    public function __construct(
        private readonly PageSyncAccess $access,
        private readonly UriBuilder $uriBuilder,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function canHandle(): bool
    {
        return $this->table === 'pages' && (int)$this->identifier > 0;
    }

    #[\Override]
    public function getPriority(): int
    {
        return 45;
    }

    #[\Override]
    protected function canRender(string $itemName, string $type): bool
    {
        if (in_array($itemName, $this->disabledItems, true)) {
            return false;
        }

        return match ($itemName) {
            'docxEditorEditInWord' => $this->access->canEditContent((int)$this->identifier, $this->backendUser),
            'docxEditorImportAsSubpages' => $this->access->canCreateSubpages((int)$this->identifier, $this->backendUser),
            'docxEditorDivider' => $this->access->canEditContent((int)$this->identifier, $this->backendUser)
                || $this->access->canCreateSubpages((int)$this->identifier, $this->backendUser),
            default => false,
        };
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function getAdditionalAttributes(string $itemName): array
    {
        $pageUid = (int)$this->identifier;
        $returnUrl = (string)$this->uriBuilder->buildUriFromRoute('web_layout', ['id' => $pageUid]);

        return match ($itemName) {
            'docxEditorEditInWord' => [
                'data-callback-module' => self::CALLBACK_MODULE,
                'data-url' => (string)$this->uriBuilder->buildUriFromRoute(PageSyncController::ROUTE_EDIT, ['id' => $pageUid, 'returnUrl' => $returnUrl]),
            ],
            'docxEditorImportAsSubpages' => [
                'data-callback-module' => self::CALLBACK_MODULE,
                'data-url' => (string)$this->uriBuilder->buildUriFromRoute(PageSyncController::ROUTE_NEW_PAGES, ['id' => $pageUid, 'returnUrl' => $returnUrl]),
            ],
            default => [],
        };
    }
}

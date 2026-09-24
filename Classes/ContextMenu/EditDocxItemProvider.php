<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\ContextMenu;

use TYPO3\CMS\Backend\ContextMenu\ItemProviders\AbstractProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use Webconsulting\DocxEditor\Exception\DocxEditorException;
use Webconsulting\DocxEditor\Service\DocxFileService;

/**
 * "Edit DOCX" in the context menu of a .docx file.
 *
 * The file list's tile view renders no action buttons: a click on a tile edits
 * the metadata and a right click opens this menu. Without this item a .docx
 * file could only be opened in the editor from the list view, whose buttons
 * come from AddDocxEditFileActionListener. The item sits right after the core's
 * "Edit metadata" (EXT:filelist's FileProvider, priority 100, runs first).
 */
final class EditDocxItemProvider extends AbstractProvider
{
    private const string CALLBACK_MODULE = '@webconsulting/docx-editor/context-menu-actions';

    /** @var array<string, array<string, string>> */
    protected $itemsConfiguration = [
        'docxEdit' => [
            'label' => 'docx_editor.messages:filelist.action.editDocx',
            'iconIdentifier' => 'mimetypes-word',
            'callbackAction' => 'openInContent',
        ],
    ];

    private ?File $file = null;

    public function __construct(
        private readonly DocxFileService $docxFileService,
        private readonly ResourceFactory $resourceFactory,
        private readonly UriBuilder $uriBuilder,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function canHandle(): bool
    {
        return $this->table === 'sys_file';
    }

    #[\Override]
    public function getPriority(): int
    {
        // ContextMenu keys providers by priority, so a value another sys_file
        // provider also uses would drop one of them. 100 is EXT:filelist's.
        return 95;
    }

    /**
     * @param array<string, mixed> $items
     * @return array<string, mixed>
     */
    #[\Override]
    public function addItems(array $items): array
    {
        $this->initialize();
        $this->file = $this->resolveEditableDocx();
        $ownItems = $this->prepareItems($this->itemsConfiguration);
        if ($ownItems === []) {
            return $items;
        }

        $keys = array_keys($items);
        $anchor = array_search('editMetadata', $keys, true);
        if ($anchor === false) {
            $anchor = array_search('edit', $keys, true);
        }
        if ($anchor === false) {
            return $ownItems + $items;
        }

        return array_slice($items, 0, $anchor + 1, true)
            + $ownItems
            + array_slice($items, $anchor + 1, null, true);
    }

    #[\Override]
    protected function canRender(string $itemName, string $type): bool
    {
        return $itemName === 'docxEdit'
            && !in_array($itemName, $this->disabledItems, true)
            && $this->file !== null;
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function getAdditionalAttributes(string $itemName): array
    {
        if ($itemName !== 'docxEdit' || $this->file === null) {
            return [];
        }

        return [
            'data-callback-module' => self::CALLBACK_MODULE,
            'data-url' => (string)$this->uriBuilder->buildUriFromRoute('docx_editor', ['file' => $this->file->getCombinedIdentifier()]),
        ];
    }

    /**
     * The file, when it is a .docx the current user may read; null otherwise.
     */
    private function resolveEditableDocx(): ?File
    {
        try {
            $resource = $this->resourceFactory->retrieveFileOrFolderObject($this->identifier);
        } catch (\Throwable) {
            return null;
        }
        if (!$resource instanceof File || !$this->docxFileService->isDocxFile($resource)) {
            return null;
        }
        try {
            $this->docxFileService->assertCanRead($resource);
        } catch (DocxEditorException) {
            return null;
        }

        return $resource;
    }
}

<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Functional\ContextMenu;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Controller\ContextMenuController;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\DocxEditor\Tests\Functional\AbstractBackendRouteTestCase;

/**
 * The file list's tile view offers a file's actions only through the context
 * menu, so "Edit DOCX" has to be there, not only among the list view's buttons.
 */
final class EditDocxItemProviderTest extends AbstractBackendRouteTestCase
{
    #[Test]
    public function aDocxFileCanBeOpenedInTheEditorFromItsContextMenu(): void
    {
        $items = $this->contextMenu(self::DOCX);

        self::assertArrayHasKey('docxEdit', $items);
        self::assertIsArray($items['docxEdit']);
        self::assertSame('Edit DOCX', $items['docxEdit']['label']);
        self::assertSame('openInContent', $items['docxEdit']['callbackAction']);
        self::assertIsArray($items['docxEdit']['additionalAttributes']);
        self::assertSame(
            '@webconsulting/docx-editor/context-menu-actions',
            $items['docxEdit']['additionalAttributes']['data-callback-module'],
        );
        $url = (string)$items['docxEdit']['additionalAttributes']['data-url'];
        self::assertStringContainsString('/docx-editor/', rawurldecode($url));
        self::assertStringContainsString('file=' . self::DOCX, rawurldecode($url));
    }

    #[Test]
    public function theItemFollowsTheCoreEditItemsAndPrecedesTheRest(): void
    {
        $keys = array_keys($this->contextMenu(self::DOCX));
        $position = array_search('docxEdit', $keys, true);
        self::assertIsInt($position);

        $coreEdit = array_values(array_intersect($keys, ['edit', 'editMetadata']));
        if ($coreEdit !== []) {
            self::assertSame(array_search(end($coreEdit), $keys, true) + 1, $position);
        }
        $rename = array_search('rename', $keys, true);
        if ($rename !== false) {
            self::assertLessThan($rename, $position);
        }
    }

    #[Test]
    public function otherFilesDoNotOfferIt(): void
    {
        self::assertArrayNotHasKey('docxEdit', $this->contextMenu(self::TXT));
        self::assertArrayNotHasKey('docxEdit', $this->contextMenu('1:/user_upload/'));
    }

    /**
     * @return array<string, mixed>
     */
    private function contextMenu(string $identifier): array
    {
        $request = $this->ajaxRequest(1, 'contextmenu', ['table' => 'sys_file', 'uid' => $identifier, 'context' => '']);
        // Core's controller is not in the test container's locator; the real container has it.
        $items = self::json(GeneralUtility::makeInstance(ContextMenuController::class)->getContextMenuAction($request));
        /** @var array<string, mixed> $items */
        return $items;
    }
}

<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Functional\Controller\Backend;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Webconsulting\DocxEditor\Controller\Backend\EditorController;
use Webconsulting\DocxEditor\Tests\Functional\AbstractBackendRouteTestCase;

/**
 * Requests the `docx_editor` backend route the way the file list "Edit DOCX"
 * action does and checks the rendered editor page.
 */
final class EditorControllerTest extends AbstractBackendRouteTestCase
{
    #[Test]
    public function editRouteRendersTheEditorForADocxFile(): void
    {
        $response = $this->requestEditor(1, ['file' => self::DOCX]);
        $html = (string)$response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>Edit example.docx</h1>', $html);
        self::assertStringContainsString('<typo3-docx-editor', $html);
        self::assertStringContainsString('file-identifier="1:/user_upload/example.docx"', $html);
        self::assertStringContainsString('file-name="example.docx"', $html);
        self::assertStringContainsString('can-write="1"', $html);
        self::assertStringContainsString('editor-locale="en"', $html);
        self::assertStringContainsString('revision="0"', $html);
        self::assertStringContainsString('@webconsulting/docx-editor/editor.js', $html);
        self::assertStringContainsString('@webconsulting/docx-editor/toolbar.js', $html);
        self::assertStringContainsString('@typo3/backend/element/status-indicator-element.js', $html);
        self::assertStringContainsString('Resources/Public/Vite/docx-editor.css', $html);
        self::assertStringNotContainsString('data-labels=', $html, 'labels come from the docx_editor.messages domain');
    }

    #[Test]
    public function theDocHeaderOffersTheCoreCloseAndSaveButtons(): void
    {
        $html = (string)$this->requestEditor(1, ['file' => self::DOCX])->getBody();

        // Close, like core's text file editor, back to the file's folder.
        self::assertMatchesRegularExpression('#<a [^>]*href="/typo3/module/file/list\?[^"]*id=1:/user_upload/"[^>]*data-docx-action="close"#', $html);
        // The core save button, with the save variants in its dropdown.
        self::assertStringContainsString('name="_savedok"', $html);
        self::assertStringContainsString('data-name="_saveandclosedok"', $html);
        self::assertStringContainsString('data-name="_saveasdok"', $html);
        self::assertStringContainsString('download="example.docx"', $html);
        // Print, handed to the editor by toolbar.js.
        self::assertMatchesRegularExpression('#<button [^>]*data-docx-action="print"[^>]*>#', $html);
        self::assertStringContainsString('Print', $html);
    }

    #[Test]
    public function editRouteHandsThePageContextToTheScripts(): void
    {
        $html = html_entity_decode((string)$this->requestEditor(1, ['file' => self::DOCX])->getBody(), ENT_QUOTES | ENT_HTML5);

        self::assertStringContainsString('data-file-path="fileadmin / user_upload/example.docx"', $html);
        self::assertStringContainsString('data-default-folder-identifier="1:/user_upload/"', $html);
        self::assertMatchesRegularExpression('#data-return-url="/typo3/module/file/list\?[^"]*id=1:/user_upload/"#', $html);
        self::assertStringContainsString('data-docx-presence hidden', $html);
        self::assertStringContainsString('data-docx-conflict hidden', $html);
    }

    #[Test]
    public function editRouteAcceptsTheFileListTargetParameterAndUsesTheUserLanguage(): void
    {
        $html = (string)$this->requestEditor(2, ['target' => self::DOCX])->getBody();

        self::assertStringContainsString('<typo3-docx-editor', $html);
        self::assertStringContainsString('editor-locale="de"', $html);
        self::assertStringContainsString('<h1>example.docx bearbeiten</h1>', $html);
    }

    #[Test]
    public function editRouteRefusesFilesThatAreNotDocx(): void
    {
        $response = $this->requestEditor(1, ['file' => self::TXT]);
        $html = (string)$response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('<typo3-docx-editor', $html);
        self::assertStringContainsString('Only .docx files can be edited.', $html);
    }

    #[Test]
    public function editRouteExplainsAMissingFileParameter(): void
    {
        $html = (string)$this->requestEditor(1, [])->getBody();

        self::assertStringNotContainsString('<typo3-docx-editor', $html);
        self::assertStringContainsString('<h1>Cannot open document</h1>', $html);
        self::assertStringContainsString('No file was selected.', $html);
        self::assertStringContainsString('class="callout callout-danger"', $html);
        self::assertMatchesRegularExpression('/<a [^>]*data-docx-action="close"[^>]*>/', $html, 'the DocHeader offers Close');
    }

    #[Test]
    public function errorsAreShownInTheUserLanguage(): void
    {
        $html = (string)$this->requestEditor(2, ['file' => self::TXT])->getBody();

        self::assertStringContainsString('Nur .docx-Dateien können bearbeitet werden.', html_entity_decode($html, ENT_QUOTES | ENT_HTML5));
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function requestEditor(int $backendUserUid, array $queryParams): ResponseInterface
    {
        return $this->get(EditorController::class)->editAction(
            $this->backendRequest($backendUserUid, 'docx_editor', $queryParams),
        );
    }
}

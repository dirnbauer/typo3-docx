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
        self::assertStringContainsString('<typo3-docx-editor', $html);
        self::assertStringContainsString('file-identifier="1:/user_upload/example.docx"', $html);
        self::assertStringContainsString('file-name="example.docx"', $html);
        self::assertStringContainsString('can-write="1"', $html);
        self::assertStringContainsString('editor-locale="en"', $html);
        self::assertStringContainsString('revision="0"', $html);
        self::assertStringContainsString('data-identifier="docx-editor-save"', $html);
        self::assertStringContainsString('data-identifier="docx-editor-save-as"', $html);
        self::assertStringContainsString('@webconsulting/docx-editor/editor.js', $html);
        self::assertStringContainsString('@webconsulting/docx-editor/toolbar.js', $html);
        self::assertStringContainsString('Resources/Public/Vite/docx-editor.css', $html);
    }

    #[Test]
    public function editRouteEmbedsAllJavaScriptLabelsAsOneJsonAttribute(): void
    {
        $html = (string)$this->requestEditor(1, ['file' => self::DOCX])->getBody();

        self::assertSame(1, preg_match('/ data-labels="([^"]+)"/', $html, $matches));
        $labels = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($labels);
        self::assertSame('Saved', $labels['saved']);
        self::assertSame('Saved to fileadmin / user_upload/example.docx', $labels['savedDetail']);
        self::assertSame('Save failed', $labels['saveFailed']);
        self::assertSame('Loading document…', $labels['loading']);
        self::assertStringContainsString('{count, plural,', $labels['collaborators']);
        self::assertSame('Heading 4', $labels['headings']['heading4Title']);
        self::assertSame('H1', $labels['headings']['heading1']);
    }

    #[Test]
    public function editRouteAcceptsTheFileListTargetParameterAndUsesTheUserLanguage(): void
    {
        $html = (string)$this->requestEditor(2, ['target' => self::DOCX])->getBody();

        self::assertStringContainsString('<typo3-docx-editor', $html);
        self::assertStringContainsString('editor-locale="de"', $html);
        self::assertStringContainsString('Gespeichert unter fileadmin / user_upload/example.docx', html_entity_decode($html, ENT_QUOTES | ENT_HTML5));
        self::assertStringContainsString('Zurück zu Medien', $html);
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
        self::assertStringContainsString('No file was selected.', $html);
        self::assertStringContainsString('Back to Media', $html);
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

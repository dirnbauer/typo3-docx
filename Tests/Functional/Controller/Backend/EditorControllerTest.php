<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Functional\Controller\Backend;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\DocxEditor\Controller\Backend\EditorController;

/**
 * Requests the `docx_editor` backend route the way the file list "Edit DOCX"
 * action does and checks the rendered editor page.
 */
final class EditorControllerTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = ['webconsulting/docx-editor'];

    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/docx_editor/Tests/Functional/Fixtures/Files/example.docx' => 'fileadmin/user_upload/example.docx',
        'typo3conf/ext/docx_editor/Tests/Functional/Fixtures/Files/notes.txt' => 'fileadmin/user_upload/notes.txt',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file_storage.csv');
    }

    #[Test]
    public function editRouteRendersTheEditorForADocxFile(): void
    {
        $response = $this->requestEditor(1, ['file' => '1:/user_upload/example.docx']);
        $html = (string)$response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<typo3-docx-editor', $html);
        self::assertStringContainsString('data-file-identifier="1:/user_upload/example.docx"', $html);
        self::assertStringContainsString('data-file-name="example.docx"', $html);
        self::assertStringContainsString('data-file-path="fileadmin / user_upload/example.docx"', $html);
        self::assertStringContainsString('data-can-write="1"', $html);
        self::assertStringContainsString('data-editor-locale="en"', $html);
        self::assertStringContainsString('data-identifier="docx-editor-save"', $html);
        self::assertStringContainsString('docx-editor.js', $html);
    }

    #[Test]
    public function editRouteAcceptsTheFileListTargetParameterAndUsesTheUserLanguage(): void
    {
        $response = $this->requestEditor(2, ['target' => '1:/user_upload/example.docx']);
        $html = (string)$response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<typo3-docx-editor', $html);
        self::assertStringContainsString('data-editor-locale="de"', $html);
    }

    #[Test]
    public function editRouteRefusesFilesThatAreNotDocx(): void
    {
        $response = $this->requestEditor(1, ['file' => '1:/user_upload/notes.txt']);
        $html = (string)$response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('<typo3-docx-editor', $html);
        self::assertStringContainsString('Only .docx files can be edited.', $html);
    }

    #[Test]
    public function editRouteExplainsAMissingFileParameter(): void
    {
        $response = $this->requestEditor(1, []);
        $html = (string)$response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('<typo3-docx-editor', $html);
        self::assertStringContainsString('No file was selected.', $html);
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function requestEditor(int $backendUserUid, array $queryParams): ResponseInterface
    {
        $backendUser = $this->setUpBackendUser($backendUserUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $request = $this->createBackendRequest($queryParams)->withAttribute('backend.user', $backendUser);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $this->get(EditorController::class)->editAction($request);
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function createBackendRequest(array $queryParams): ServerRequestInterface
    {
        $request = (new ServerRequest(
            'https://example.com/typo3/docx-editor/edit',
            'GET',
            null,
            [],
            [
                'HTTP_HOST' => 'example.com',
                'HTTPS' => 'on',
                'REQUEST_URI' => '/typo3/docx-editor/edit',
                'SCRIPT_NAME' => '/index.php',
                'REMOTE_ADDR' => '127.0.0.1',
            ],
        ))
            ->withQueryParams($queryParams)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $this->get(Router::class)->getRoute('docx_editor'));

        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }
}

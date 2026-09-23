<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Functional;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Routing\Route as SymfonyRoute;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test instance with a fileadmin storage holding example.docx and notes.txt,
 * two admin users (1: English, 2: German) and helpers to build backend
 * requests the way the TYPO3 backend dispatches them.
 */
abstract class AbstractBackendRouteTestCase extends FunctionalTestCase
{
    protected const string DOCX = '1:/user_upload/example.docx';
    protected const string TXT = '1:/user_upload/notes.txt';

    protected array $coreExtensionsToLoad = ['filelist'];

    protected array $testExtensionsToLoad = ['webconsulting/docx-editor'];

    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/docx_editor/Tests/Functional/Fixtures/Files/example.docx' => 'fileadmin/user_upload/example.docx',
        'typo3conf/ext/docx_editor/Tests/Functional/Fixtures/Files/notes.txt' => 'fileadmin/user_upload/notes.txt',
    ];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/sys_file_storage.csv');
    }

    /**
     * Logs the backend user in and returns a request for the named backend
     * route; a non-null $json becomes an application/json POST body.
     *
     * @param array<string, string> $queryParams
     * @param array<string, mixed>|null $json
     */
    protected function backendRequest(int $backendUserUid, string $route, array $queryParams = [], ?array $json = null): ServerRequestInterface
    {
        $backendUser = $this->setUpBackendUser($backendUserUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $routeObject = $this->get(Router::class)->getRoute($route);
        self::assertTrue($routeObject instanceof Route || $routeObject instanceof SymfonyRoute);
        $uri = 'https://example.com/typo3' . $routeObject->getPath();
        $request = (new ServerRequest($uri, $json === null ? 'GET' : 'POST', null, [], [
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
            'REQUEST_URI' => '/typo3' . $routeObject->getPath(),
            'SCRIPT_NAME' => '/index.php',
            'REMOTE_ADDR' => '127.0.0.1',
        ]))
            ->withQueryParams($queryParams)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $routeObject)
            ->withAttribute('backend.user', $backendUser);

        if ($json !== null) {
            $stream = new Stream('php://temp', 'rw');
            $stream->write(json_encode($json, JSON_THROW_ON_ERROR));
            $stream->rewind();
            $request = $request->withHeader('Content-Type', 'application/json')->withBody($stream);
        }

        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }

    /**
     * AJAX routes are registered under the `ajax_` namespace.
     *
     * @param array<string, string> $queryParams
     * @param array<string, mixed>|null $json
     */
    protected function ajaxRequest(int $backendUserUid, string $route, array $queryParams = [], ?array $json = null): ServerRequestInterface
    {
        return $this->backendRequest($backendUserUid, 'ajax_' . $route, $queryParams, $json);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}

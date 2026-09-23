<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Functional\PageSync;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Routing\Route as SymfonyRoute;
use TYPO3\CMS\Backend\ContextMenu\ContextMenu;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Buttons\DropDownButton;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\DocxEditor\PageSync\Backend\PageModuleButtons;
use Webconsulting\DocxEditor\PageSync\Backend\PageSyncApiController;
use Webconsulting\DocxEditor\PageSync\Backend\PageSyncController;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Service\PageSyncService;
use Webconsulting\DocxEditor\Tests\Fixtures\PageSync\DocumentEditor;

/**
 * The backend side of the round trip, requested the way the backend dispatches it: the AJAX
 * routes, the three screens, the Page module's Word menu and the page tree's context menu.
 */
final class BackendTest extends AbstractPageSyncTestCase
{
    #[Test]
    public function loadPreviewAndApplyGoThroughTheAjaxRoutes(): void
    {
        $api = $this->get(PageSyncApiController::class);

        $loaded = self::json($api->loadAction($this->request(1, 'ajax_docx_editor_page_load', ['page' => '1', 'language' => '0'])));
        self::assertTrue($loaded['ok']);
        self::assertIsString($loaded['data']);
        self::assertSame('our-services-en-gb.docx', $loaded['fileName']);

        $edited = new DocumentEditor($this->read((string)base64_decode($loaded['data'], true)))
            ->replace('typo3:tt_content:2:bodytext', [new Paragraph([new Text('We host websites too.')])])
            ->document();
        $preview = self::json($api->previewAction($this->request(1, 'ajax_docx_editor_page_preview', [], [
            'mode' => 'update',
            'page' => 1,
            'language' => 0,
            'data' => base64_encode($this->binary($edited)),
        ])));
        self::assertTrue($preview['ok']);
        self::assertIsString($preview['id']);
        self::assertIsArray($preview['plans']);
        $plan = $preview['plans'][0];
        self::assertIsArray($plan);
        self::assertTrue($plan['hasWrites']);
        self::assertSame(1, $plan['counts']['update'] ?? null);
        $changed = array_values(array_filter($plan['entries'], static fn(array $entry): bool => $entry['action'] === 'update'));
        self::assertSame(2, $changed[0]['uid']);
        self::assertSame('Changed', $changed[0]['actionLabel'], 'Labels are resolved for the review');
        self::assertSame('changed in Word', $changed[0]['fields'][0]['statusLabel'] ?? null);
        self::assertStringNotContainsString('We host websites too.', (string)$this->row('tt_content', 2)['bodytext'], 'The preview writes nothing');

        $applied = self::json($api->applyAction($this->request(1, 'ajax_docx_editor_page_apply', [], [
            'id' => $preview['id'],
            'decisions' => [],
        ])));
        self::assertTrue($applied['ok']);
        self::assertTrue($applied['succeeded']);
        self::assertSame([2], $applied['results'][0]['updated']);
        self::assertSame('Our services', $applied['results'][0]['pageTitle']);
        self::assertStringContainsString('We host websites too.', (string)$this->row('tt_content', 2)['bodytext']);
    }

    #[Test]
    public function refusalsAnswerWithTheStatusAndATranslatedMessage(): void
    {
        $api = $this->get(PageSyncApiController::class);

        // The reader may look at the page but not change it.
        $response = $api->previewAction($this->request(3, 'ajax_docx_editor_page_preview', [], [
            'mode' => 'update',
            'page' => 1,
            'language' => 0,
            'data' => base64_encode($this->get(PageSyncService::class)->export(1, 0, $this->backendUser(1))->binary),
        ]));
        self::assertSame(403, $response->getStatusCode());
        $body = self::json($response);
        self::assertFalse($body['ok']);
        self::assertSame('error.noPageAccess', $body['code']);
        self::assertSame('You may not edit page 1.', $body['error']);

        $response = $api->applyAction($this->request(2, 'ajax_docx_editor_page_apply', [], ['id' => str_repeat('a', 32), 'decisions' => []]));
        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Die Vorschau ist abgelaufen', (string)self::json($response)['error'], 'In the editor\'s language');

        $response = $api->previewAction($this->request(1, 'ajax_docx_editor_page_preview', [], ['mode' => 'update', 'page' => 1, 'data' => base64_encode('not a zip')]));
        self::assertSame(415, $response->getStatusCode());
    }

    #[Test]
    public function theEditScreenMountsThePageEditor(): void
    {
        $response = $this->get(PageSyncController::class)->editAction($this->request(1, 'docx_editor_page', ['id' => '1', 'language' => '1']));
        $html = html_entity_decode((string)$response->getBody(), ENT_QUOTES | ENT_HTML5);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Edit “Unser Angebot” in Word', $html);
        self::assertStringContainsString('id="docx-page-sync-app"', $html);
        self::assertStringContainsString('data-page="1"', $html);
        self::assertStringContainsString('data-language="1"', $html);
        self::assertStringContainsString('@webconsulting/docx-editor/page-sync/page-editor.js', $html);
        self::assertStringContainsString('data-page-sync-action="save"', $html);
        self::assertStringContainsString('data-page-sync-action="upload"', $html);
        self::assertStringContainsString('data-page-sync-action="print"', $html);
        self::assertMatchesRegularExpression('#href="/typo3/docx-editor/page/download\?[^"]*id=1[^"]*"#', $html);
        self::assertStringContainsString('Deutsch', $html, 'The language selector offers the site languages');
    }

    #[Test]
    public function theEditScreenRefusesPagesTheEditorMayNotChange(): void
    {
        $html = (string)$this->get(PageSyncController::class)->editAction($this->request(3, 'docx_editor_page', ['id' => '1']))->getBody();

        self::assertStringNotContainsString('docx-page-sync-app', $html);
        self::assertStringContainsString('You may not edit page 1.', html_entity_decode($html, ENT_QUOTES | ENT_HTML5));
    }

    #[Test]
    public function theNewPagesScreenOffersTheSplitModes(): void
    {
        $html = html_entity_decode((string)$this->get(PageSyncController::class)->newPagesAction($this->request(1, 'docx_editor_page_new', ['id' => '1']))->getBody(), ENT_QUOTES | ENT_HTML5);

        self::assertStringContainsString('Import a Word document as subpages of “Our services”', $html);
        self::assertStringContainsString('value="h1"', $html);
        self::assertStringContainsString('value="pagebreak"', $html);
        self::assertStringContainsString('@webconsulting/docx-editor/page-sync/new-pages.js', $html);
    }

    #[Test]
    public function downloadAnswersWithTheWordDocument(): void
    {
        $response = $this->get(PageSyncController::class)->downloadAction($this->request(1, 'docx_editor_page_download', ['id' => '1']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $response->getHeaderLine('Content-Type'));
        self::assertSame('attachment; filename="our-services-en-gb.docx"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame(1, $this->read((string)$response->getBody())->manifest?->pageUid);
    }

    #[Test]
    public function thePageModuleGetsAWordMenuWhereItIsUseful(): void
    {
        self::assertCount(3, $this->wordMenu(1)?->getItems() ?? [], 'Edit, download, import as subpages');
        self::assertNull($this->wordMenu(3), 'The reader may neither edit nor create pages');
    }

    #[Test]
    public function thePageTreeContextMenuOffersEditInWord(): void
    {
        $this->request(1, 'web_layout');
        $items = GeneralUtility::makeInstance(ContextMenu::class)->getItems('pages', '1', 'tree');

        self::assertArrayHasKey('docxEditorEditInWord', $items);
        self::assertArrayHasKey('docxEditorImportAsSubpages', $items);
        self::assertStringContainsString('/typo3/docx-editor/page?', html_entity_decode((string)($items['docxEditorEditInWord']['additionalAttributes']['data-url'] ?? ''), ENT_QUOTES));
    }

    private function wordMenu(int $userUid): ?DropDownButton
    {
        $request = $this->request($userUid, 'web_layout', ['id' => '1'])
            ->withAttribute('module', $this->get(ModuleProvider::class)->getModule('web_layout'));
        $event = new ModifyButtonBarEvent([], $this->get(ButtonBar::class), $request);
        $this->get(PageModuleButtons::class)($event);

        $button = $event->getButtons()[ButtonBar::BUTTON_POSITION_LEFT][5][0] ?? null;

        return $button instanceof DropDownButton ? $button : null;
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed>|null $json
     */
    private function request(int $userUid, string $routeIdentifier, array $query = [], ?array $json = null): ServerRequestInterface
    {
        $user = $this->backendUser($userUid);
        $route = $this->get(Router::class)->getRoute($routeIdentifier);
        self::assertTrue($route instanceof Route || $route instanceof SymfonyRoute);
        $path = '/typo3' . $route->getPath();
        $request = new ServerRequest('https://example.com' . $path, $json === null ? 'GET' : 'POST', null, [], [
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
            'REQUEST_URI' => $path,
            'SCRIPT_NAME' => '/index.php',
            'REMOTE_ADDR' => '127.0.0.1',
        ])
            ->withQueryParams($query)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $route instanceof Route ? $route : Route::fromSymfonyRoute($route, $routeIdentifier));
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
     * @return array<string, mixed>
     */
    private static function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}

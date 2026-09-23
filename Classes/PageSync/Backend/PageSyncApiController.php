<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use Webconsulting\DocxEditor\PageSync\Apply\ApplyResult;
use Webconsulting\DocxEditor\PageSync\Configuration\PageSyncSettings;
use Webconsulting\DocxEditor\PageSync\Exception\PageSyncException;
use Webconsulting\DocxEditor\PageSync\Plan\PageSplit;
use Webconsulting\DocxEditor\PageSync\Plan\PlanDecisions;
use Webconsulting\DocxEditor\PageSync\Plan\PlanPresenter;
use Webconsulting\DocxEditor\PageSync\Plan\SyncPlan;
use Webconsulting\DocxEditor\PageSync\Record\RecordRepository;
use Webconsulting\DocxEditor\PageSync\Service\PageSyncService;
use Webconsulting\DocxEditor\PageSync\Service\PreviewResult;

/**
 * The AJAX side of the page round trip: the document of a page, the preview of an import and
 * applying a reviewed preview. Answers `{ok: true, …}`, or `{ok: false, error}` with the HTTP
 * status of the failure and the message in the editor's language.
 */
#[AsController]
final readonly class PageSyncApiController
{
    public function __construct(
        private PageSyncService $pageSync,
        private PlanPresenter $presenter,
        private PageSyncSettings $settings,
        private LanguageServiceFactory $languageServiceFactory,
        private UriBuilder $uriBuilder,
        private RecordRepository $records,
    ) {}

    /**
     * GET ?page=&language= — the page as a Word document.
     */
    public function loadAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond($request, function (BackendUserAuthentication $user) use ($request): array {
            $query = $request->getQueryParams();
            $result = $this->pageSync->export(self::int($query, 'page'), self::int($query, 'language'), $user);

            return [
                'data' => base64_encode($result->binary),
                'fileName' => $result->fileName,
                'revision' => 0,
                'elements' => $result->elementCount,
                'skipped' => $result->skipped,
            ];
        });
    }

    /**
     * POST {mode: "update", page, language, data} or {mode: "newPage", parent, split, data} —
     * what importing the document would do. Nothing is written.
     */
    public function previewAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond($request, function (BackendUserAuthentication $user, LanguageService $labels) use ($request): array {
            $body = self::body($request);
            $binary = $this->document($body);
            $useJev = ($body['useJev'] ?? true) !== false;
            $preview = ($body['mode'] ?? '') === PageSyncService::MODE_NEW_PAGE
                ? $this->pageSync->previewNewPages($binary, self::int($body, 'parent'), PageSplit::tryFrom(self::string($body, 'split')) ?? PageSplit::None, $user, $useJev)
                : $this->pageSync->preview($binary, self::int($body, 'page'), self::int($body, 'language'), $user, $useJev);

            return $this->presentPreview($preview, $labels);
        });
    }

    /**
     * POST {id, decisions} — applies a reviewed preview.
     */
    public function applyAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond($request, function (BackendUserAuthentication $user) use ($request): array {
            $body = self::body($request);
            $decisions = PlanDecisions::fromArray(is_array($body['decisions'] ?? null) ? $body['decisions'] : []);
            $results = $this->pageSync->apply(self::string($body, 'id'), $decisions, $user);

            return [
                'results' => array_map(fn(ApplyResult $result): array => $result->toArray() + [
                    'pageTitle' => (string)($this->records->record('pages', $result->pageUid, (int)$user->workspace)['title'] ?? ''),
                    'pageUrl' => (string)$this->uriBuilder->buildUriFromRoute('web_layout', ['id' => $result->pageUid]),
                ], $results),
                'succeeded' => array_all($results, static fn(ApplyResult $result): bool => $result->succeeded()),
            ];
        });
    }

    /**
     * POST {id} — forgets a preview the editor cancelled.
     */
    public function discardAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond($request, function () use ($request): array {
            $this->pageSync->discard(self::string(self::body($request), 'id'));

            return [];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPreview(PreviewResult $preview, LanguageService $labels): array
    {
        return [
            'id' => $preview->id,
            'plans' => array_map(fn(SyncPlan $plan): array => $this->presenter->present($plan, $labels), $preview->plans),
        ];
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function document(array $body): string
    {
        $encoded = self::string($body, 'data');
        $limit = $this->settings->maxUploadMegabytes() * 1024 * 1024;
        if ($encoded === '') {
            throw new PageSyncException('error.notADocx', 400);
        }
        // Base64 is a third larger than the document; refuse before decoding.
        if (strlen($encoded) > intdiv($limit * 4, 3) + 4) {
            throw new PageSyncException('error.fileTooLarge', 413, [$this->settings->maxUploadMegabytes()]);
        }
        $binary = base64_decode($encoded, true);
        if ($binary === false || $binary === '') {
            throw new PageSyncException('error.notADocx', 400);
        }

        return $binary;
    }

    /**
     * @param \Closure(BackendUserAuthentication, LanguageService): array<string, mixed> $action
     */
    private function respond(ServerRequestInterface $request, \Closure $action): ResponseInterface
    {
        // The backend user lives in $GLOBALS['BE_USER']; TYPO3 sets no
        // "backend.user" request attribute, so reading one answered every real
        // request with "No backend user".
        $user = $GLOBALS['BE_USER'] ?? null;
        $labels = $user instanceof BackendUserAuthentication
            ? $this->languageServiceFactory->createFromUserPreferences($user)
            : $this->languageServiceFactory->create('en');
        if (!$user instanceof BackendUserAuthentication) {
            return new JsonResponse(['ok' => false, 'error' => 'No backend user.'], 401);
        }
        try {
            return new JsonResponse(['ok' => true] + $action($user, $labels));
        } catch (PageSyncException $exception) {
            return new JsonResponse([
                'ok' => false,
                'error' => $exception->localizedMessage($labels),
                'code' => $exception->labelKey,
            ], $exception->getStatusCode());
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (is_array($body) && $body !== []) {
            return $body;
        }
        try {
            $decoded = json_decode((string)$request->getBody(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PageSyncException('error.invalidRequest', 400);
        }

        return is_array($decoded) ? $decoded : throw new PageSyncException('error.invalidRequest', 400);
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private static function int(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private static function string(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }
}

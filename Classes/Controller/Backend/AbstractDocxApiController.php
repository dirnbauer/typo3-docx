<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use Webconsulting\DocxEditor\Exception\DocxEditorException;

/**
 * JSON envelope shared by the AJAX controllers: `{ok: true, ...payload}` on
 * success, `{ok: false, error}` with the exception's HTTP status on failure;
 * the error is translated into the backend user's language.
 */
abstract readonly class AbstractDocxApiController
{
    /**
     * @param callable(): array<string, mixed> $action
     */
    protected function respond(callable $action): ResponseInterface
    {
        try {
            return new JsonResponse(['ok' => true] + $action());
        } catch (DocxEditorException $exception) {
            $languageService = $GLOBALS['LANG'] ?? null;

            return new JsonResponse(
                [
                    'ok' => false,
                    'error' => $exception->localizedMessage($languageService instanceof LanguageService ? $languageService : null),
                ],
                $exception->getStatusCode(),
            );
        }
    }

    /**
     * TYPO3 does not populate the parsed body for application/json requests.
     *
     * @return array<string, mixed>
     */
    protected function parseRequestPayload(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (is_array($body)) {
            return $body;
        }

        try {
            $decoded = json_decode((string)$request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DocxEditorException('error.invalidRequest', 400);
        }
        if (!is_array($decoded)) {
            throw new DocxEditorException('error.invalidRequest', 400);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $values
     */
    protected function stringValue(array $values, string $key): string
    {
        $value = $values[$key] ?? '';

        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * @param array<string, mixed> $values
     */
    protected function intValue(array $values, string $key, int $default = 0): int
    {
        $value = $values[$key] ?? null;

        return is_numeric($value) ? (int)$value : $default;
    }

    protected function fileIdentifierFromQuery(ServerRequestInterface $request): string
    {
        return $this->stringValue($request->getQueryParams(), 'file');
    }
}

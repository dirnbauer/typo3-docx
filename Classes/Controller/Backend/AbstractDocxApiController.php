<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use Webconsulting\DocxEditor\Exception\DocxEditorException;

abstract class AbstractDocxApiController
{
    /**
     * @param array<string, mixed> $payload
     */
    protected function jsonSuccess(array $payload, int $status = 200): ResponseInterface
    {
        return new JsonResponse(['ok' => true] + $payload, $status);
    }

    protected function jsonError(DocxEditorException $exception): ResponseInterface
    {
        $status = $exception->getCode();
        if ($status < 400 || $status > 599) {
            $status = 500;
        }
        return new JsonResponse(
            [
                'ok' => false,
                'error' => $exception->getMessage(),
            ],
            $status,
        );
    }

    protected function runJson(callable $callback): ResponseInterface
    {
        try {
            return $callback();
        } catch (DocxEditorException $exception) {
            return $this->jsonError($exception);
        }
    }

    /**
     * TYPO3 does not populate parsed body for application/json POST requests.
     *
     * @return array<string, mixed>
     */
    protected function parseRequestPayload(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (is_array($body)) {
            return $body;
        }

        $raw = (string)$request->getBody();
        if ($raw === '') {
            throw new DocxEditorException('Invalid request body.', 400);
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DocxEditorException('Invalid request body.', 400);
        }

        if (!is_array($decoded)) {
            throw new DocxEditorException('Invalid request body.', 400);
        }

        return $decoded;
    }
}

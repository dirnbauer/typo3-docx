<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
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
}

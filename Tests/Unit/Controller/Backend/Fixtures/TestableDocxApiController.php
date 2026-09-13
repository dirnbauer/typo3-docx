<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Unit\Controller\Backend\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Webconsulting\DocxEditor\Controller\Backend\AbstractDocxApiController;
use Webconsulting\DocxEditor\Exception\DocxEditorException;

/**
 * Exposes the protected JSON helpers of the abstract API controller.
 */
final readonly class TestableDocxApiController extends AbstractDocxApiController
{
    /**
     * @param array<string, mixed> $payload
     */
    public function success(array $payload): ResponseInterface
    {
        return $this->jsonSuccess($payload);
    }

    public function error(DocxEditorException $exception): ResponseInterface
    {
        return $this->jsonError($exception);
    }

    public function run(callable $callback): ResponseInterface
    {
        return $this->runJson($callback);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(ServerRequestInterface $request): array
    {
        return $this->parseRequestPayload($request);
    }
}

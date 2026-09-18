<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Unit\Controller\Backend\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Webconsulting\DocxEditor\Controller\Backend\AbstractDocxApiController;

/**
 * Exposes the protected helpers of the abstract API controller.
 */
final readonly class TestableDocxApiController extends AbstractDocxApiController
{
    /**
     * @param callable(): array<string, mixed> $action
     */
    public function run(callable $action): ResponseInterface
    {
        return $this->respond($action);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(ServerRequestInterface $request): array
    {
        return $this->parseRequestPayload($request);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function string(array $values, string $key): string
    {
        return $this->stringValue($values, $key);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function int(array $values, string $key, int $default = 0): int
    {
        return $this->intValue($values, $key, $default);
    }

    public function fileFromQuery(ServerRequestInterface $request): string
    {
        return $this->fileIdentifierFromQuery($request);
    }
}

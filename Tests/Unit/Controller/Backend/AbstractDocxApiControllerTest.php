<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Unit\Controller\Backend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\DocxEditor\Exception\DocxEditorException;
use Webconsulting\DocxEditor\Tests\Unit\Controller\Backend\Fixtures\TestableDocxApiController;

final class AbstractDocxApiControllerTest extends UnitTestCase
{
    private TestableDocxApiController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new TestableDocxApiController();
    }

    #[Test]
    public function respondWrapsThePayloadInTheOkEnvelope(): void
    {
        $response = $this->controller->run(static fn(): array => ['revision' => 3]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true, 'revision' => 3], self::decode($response));
    }

    #[Test]
    #[DataProvider('errorStatusProvider')]
    public function respondMapsDocxEditorExceptionsToErrorResponses(int $code, int $expectedStatus): void
    {
        $response = $this->controller->run(static function () use ($code): array {
            throw new DocxEditorException('Boom', $code);
        });

        self::assertSame($expectedStatus, $response->getStatusCode());
        self::assertSame(['ok' => false, 'error' => 'Boom'], self::decode($response));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function errorStatusProvider(): iterable
    {
        yield 'conflict' => [409, 409];
        yield 'forbidden' => [403, 403];
        yield 'unsupported media type' => [415, 415];
        yield 'no http status' => [0, 500];
        yield 'timestamp code' => [1757600000, 500];
    }

    #[Test]
    public function parseRequestPayloadPrefersTheParsedBody(): void
    {
        $request = (new ServerRequest('https://example.com/typo3/ajax', 'POST'))
            ->withParsedBody(['file' => '1:/a.docx']);

        self::assertSame(['file' => '1:/a.docx'], $this->controller->payload($request));
    }

    #[Test]
    public function parseRequestPayloadDecodesJsonBodies(): void
    {
        $request = self::jsonRequest('{"file":"1:/a.docx","revision":2}');

        self::assertSame(['file' => '1:/a.docx', 'revision' => 2], $this->controller->payload($request));
    }

    #[Test]
    #[DataProvider('invalidBodyProvider')]
    public function parseRequestPayloadRejectsUnusableBodies(string $body): void
    {
        $this->expectException(DocxEditorException::class);
        $this->expectExceptionCode(400);
        $this->controller->payload(self::jsonRequest($body));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBodyProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'broken json' => ['{"file":'];
        yield 'scalar json' => ['"just a string"'];
    }

    #[Test]
    public function scalarHelpersNormalizeLooseJsonInput(): void
    {
        $values = ['file' => ' 1:/a.docx ', 'revision' => '7', 'nested' => ['x'], 'flag' => true];

        self::assertSame('1:/a.docx', $this->controller->string($values, 'file'));
        self::assertSame('', $this->controller->string($values, 'nested'));
        self::assertSame('', $this->controller->string($values, 'missing'));
        self::assertSame(7, $this->controller->int($values, 'revision'));
        self::assertSame(-1, $this->controller->int($values, 'missing', -1));
        self::assertSame(0, $this->controller->int($values, 'flag'));
    }

    #[Test]
    public function fileIdentifierFromQueryReadsTheFileParameter(): void
    {
        $request = (new ServerRequest('https://example.com/typo3/ajax'))->withQueryParams(['file' => ' 1:/a.docx ']);

        self::assertSame('1:/a.docx', $this->controller->fileFromQuery($request));
        self::assertSame('', $this->controller->fileFromQuery(new ServerRequest('https://example.com/typo3/ajax')));
    }

    private static function jsonRequest(string $body): ServerRequestInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($body);
        $stream->rewind();

        return (new ServerRequest('https://example.com/typo3/ajax', 'POST'))
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}

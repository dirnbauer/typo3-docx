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
    #[Test]
    public function jsonSuccessAddsTheOkFlag(): void
    {
        $response = $this->createController()->success(['revision' => 3]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true, 'revision' => 3], self::decode($response));
    }

    #[Test]
    #[DataProvider('errorStatusProvider')]
    public function jsonErrorUsesHttpStatusCodesFromTheExceptionAndFallsBackTo500(int $code, int $expectedStatus): void
    {
        $response = $this->createController()->error(new DocxEditorException('Boom', $code));

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
        yield 'no code' => [0, 500];
        yield 'not an http status' => [1757600000, 500];
    }

    #[Test]
    public function runJsonConvertsDocxEditorExceptionsToErrorResponses(): void
    {
        $response = $this->createController()->run(static function (): ResponseInterface {
            throw new DocxEditorException('Only .docx files can be edited.', 415);
        });

        self::assertSame(415, $response->getStatusCode());
        self::assertSame('Only .docx files can be edited.', self::decode($response)['error']);
    }

    #[Test]
    public function parseRequestPayloadPrefersTheParsedBody(): void
    {
        $request = (new ServerRequest('https://example.com/typo3/ajax', 'POST'))
            ->withParsedBody(['file' => '1:/a.docx']);

        self::assertSame(['file' => '1:/a.docx'], $this->createController()->payload($request));
    }

    #[Test]
    public function parseRequestPayloadDecodesJsonBodies(): void
    {
        $request = self::jsonRequest('{"file":"1:/a.docx","revision":2}');

        self::assertSame(['file' => '1:/a.docx', 'revision' => 2], $this->createController()->payload($request));
    }

    #[Test]
    #[DataProvider('invalidBodyProvider')]
    public function parseRequestPayloadRejectsUnusableBodies(string $body): void
    {
        $this->expectException(DocxEditorException::class);
        $this->expectExceptionCode(400);
        $this->createController()->payload(self::jsonRequest($body));
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

    private function createController(): TestableDocxApiController
    {
        return new TestableDocxApiController();
    }
}

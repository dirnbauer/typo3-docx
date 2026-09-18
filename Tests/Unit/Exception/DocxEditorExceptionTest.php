<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\DocxEditor\Exception\DocxEditorException;

final class DocxEditorExceptionTest extends UnitTestCase
{
    #[Test]
    #[DataProvider('statusProvider')]
    public function getStatusCodeOnlyPassesThroughHttpErrorCodes(int $code, int $expected): void
    {
        self::assertSame($expected, (new DocxEditorException('x', $code))->getStatusCode());
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function statusProvider(): iterable
    {
        yield 'bad request' => [400, 400];
        yield 'server error' => [599, 599];
        yield 'success is not an error' => [200, 500];
        yield 'zero' => [0, 500];
        yield 'out of range' => [600, 500];
    }
}

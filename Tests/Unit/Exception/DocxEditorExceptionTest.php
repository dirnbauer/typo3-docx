<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\DocxEditor\Exception\DocxEditorException;

final class DocxEditorExceptionTest extends UnitTestCase
{
    #[Test]
    #[DataProvider('statusProvider')]
    public function getStatusCodeOnlyPassesThroughHttpErrorCodes(int $code, int $expected): void
    {
        self::assertSame($expected, (new DocxEditorException('error.x', $code))->getStatusCode());
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

    #[Test]
    public function theMessageIsTheLabelKeyOfTheMessagesDomain(): void
    {
        $exception = new DocxEditorException('error.fileNotFound', 404);

        self::assertSame('error.fileNotFound', $exception->getMessage());
        self::assertSame('error.fileNotFound', $exception->labelKey);
    }

    #[Test]
    public function localizedMessageTranslatesAndFillsInTheArguments(): void
    {
        $languageService = self::createStub(LanguageService::class);
        $languageService->method('sL')->willReturnMap([
            ['docx_editor.messages:error.storageRejected', 'The storage did not accept the file: %s'],
        ]);

        $exception = new DocxEditorException('error.storageRejected', 400, ['disk full']);

        self::assertSame('The storage did not accept the file: disk full', $exception->localizedMessage($languageService));
    }

    #[Test]
    public function localizedMessageFallsBackToTheKey(): void
    {
        $languageService = self::createStub(LanguageService::class);
        $languageService->method('sL')->willReturn('');

        self::assertSame('error.unknown', (new DocxEditorException('error.unknown', 400))->localizedMessage($languageService));
        self::assertSame('error.unknown', (new DocxEditorException('error.unknown', 400))->localizedMessage(null));
    }
}

<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\DocxEditor\Service\EditorRequestResolver;

final class EditorRequestResolverTest extends UnitTestCase
{
    #[Test]
    public function resolveFileIdentifierPrefersModuleData(): void
    {
        $request = (new ServerRequest('https://example.com/typo3/docx-editor/edit'))
            ->withQueryParams(['file' => '1:/from-query.docx'])
            ->withAttribute('moduleData', new ModuleData('docx_editor', ['file' => ' 1:/from-module.docx ']));

        self::assertSame('1:/from-module.docx', (new EditorRequestResolver())->resolveFileIdentifier($request));
    }

    #[Test]
    public function resolveFileIdentifierReadsTheFileQueryParameter(): void
    {
        $request = (new ServerRequest('https://example.com/typo3/docx-editor/edit'))
            ->withQueryParams(['file' => ' 1:/user_upload/report.docx ']);

        self::assertSame('1:/user_upload/report.docx', (new EditorRequestResolver())->resolveFileIdentifier($request));
    }

    #[Test]
    public function resolveFileIdentifierAcceptsTheFileListTargetAlias(): void
    {
        $request = (new ServerRequest('https://example.com/typo3/docx-editor/edit'))
            ->withQueryParams(['target' => '1:/user_upload/report.docx']);

        self::assertSame('1:/user_upload/report.docx', (new EditorRequestResolver())->resolveFileIdentifier($request));
    }

    #[Test]
    public function resolveFileIdentifierFallsBackToTheParsedBody(): void
    {
        $request = (new ServerRequest('https://example.com/typo3/docx-editor/edit', 'POST'))
            ->withParsedBody(['file' => '1:/user_upload/posted.docx']);

        self::assertSame('1:/user_upload/posted.docx', (new EditorRequestResolver())->resolveFileIdentifier($request));
    }

    #[Test]
    public function resolveFileIdentifierIgnoresNonStringAndObjectInput(): void
    {
        $resolver = new EditorRequestResolver();

        $arrayRequest = (new ServerRequest('https://example.com/typo3/docx-editor/edit'))
            ->withQueryParams(['file' => ['1:/a.docx']]);
        $objectBodyRequest = (new ServerRequest('https://example.com/typo3/docx-editor/edit', 'POST'))
            ->withParsedBody(new \stdClass());

        self::assertSame('', $resolver->resolveFileIdentifier($arrayRequest));
        self::assertSame('', $resolver->resolveFileIdentifier($objectBodyRequest));
        self::assertSame('', $resolver->resolveFileIdentifier(new ServerRequest('https://example.com/typo3/docx-editor/edit')));
    }

    #[Test]
    #[DataProvider('localeProvider')]
    public function resolveEditorLocaleMapsBackendUserLanguage(?string $lang, string $expected): void
    {
        $request = new ServerRequest('https://example.com/typo3/docx-editor/edit');
        unset($GLOBALS['BE_USER']);
        if ($lang !== null) {
            $backendUser = self::createStub(BackendUserAuthentication::class);
            $backendUser->user = ['uid' => 1, 'lang' => $lang];
            $GLOBALS['BE_USER'] = $backendUser;
        }

        try {
            self::assertSame($expected, (new EditorRequestResolver())->resolveEditorLocale($request));
        } finally {
            unset($GLOBALS['BE_USER']);
        }
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function localeProvider(): iterable
    {
        yield 'no backend user' => [null, 'en'];
        yield 'default (english)' => ['default', 'en'];
        yield 'empty' => ['', 'en'];
        yield 'german' => ['de', 'de'];
        yield 'austrian german' => ['de_AT', 'de'];
        yield 'uppercase' => ['DE', 'de'];
        yield 'french falls back' => ['fr', 'en'];
        yield 'danish is not german' => ['da', 'en'];
    }
}

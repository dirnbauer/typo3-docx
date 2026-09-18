<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFileAccessPermissionsException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\DocxEditor\Exception\DocxEditorException;
use Webconsulting\DocxEditor\Service\DocxFileService;

final class DocxFileServiceTest extends UnitTestCase
{
    #[Test]
    #[DataProvider('extensionProvider')]
    public function isDocxFileChecksTheExtensionCaseInsensitively(string $extension, bool $expected): void
    {
        $file = self::createStub(File::class);
        $file->method('getExtension')->willReturn($extension);

        $service = new DocxFileService(self::createStub(ResourceFactory::class));

        self::assertSame($expected, $service->isDocxFile($file));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function extensionProvider(): iterable
    {
        yield 'lowercase docx' => ['docx', true];
        yield 'uppercase DOCX' => ['DOCX', true];
        yield 'legacy doc' => ['doc', false];
        yield 'pdf' => ['pdf', false];
        yield 'empty' => ['', false];
    }

    #[Test]
    public function resolveFileRejectsAnEmptyIdentifier(): void
    {
        $service = new DocxFileService(self::createStub(ResourceFactory::class));

        $this->expectException(DocxEditorException::class);
        $this->expectExceptionCode(400);
        $service->resolveFile('   ');
    }

    #[Test]
    public function resolveFileRejectsFolders(): void
    {
        $resourceFactory = self::createStub(ResourceFactory::class);
        $resourceFactory->method('retrieveFileOrFolderObject')->willReturn(self::createStub(Folder::class));
        $service = new DocxFileService($resourceFactory);

        $this->expectException(DocxEditorException::class);
        $this->expectExceptionCode(404);
        $service->resolveFile('1:/user_upload/');
    }

    #[Test]
    public function resolveFileRejectsOtherFileTypes(): void
    {
        $file = self::createStub(File::class);
        $file->method('getExtension')->willReturn('pdf');
        $resourceFactory = self::createStub(ResourceFactory::class);
        $resourceFactory->method('retrieveFileOrFolderObject')->willReturn($file);
        $service = new DocxFileService($resourceFactory);

        $this->expectException(DocxEditorException::class);
        $this->expectExceptionCode(415);
        $service->resolveFile('1:/user_upload/brochure.pdf');
    }

    #[Test]
    public function resolveFileReturnsTheDocxFile(): void
    {
        $file = self::createStub(File::class);
        $file->method('getExtension')->willReturn('docx');
        $resourceFactory = self::createStub(ResourceFactory::class);
        $resourceFactory->method('retrieveFileOrFolderObject')->willReturn($file);
        $service = new DocxFileService($resourceFactory);

        self::assertSame($file, $service->resolveFile(' 1:/user_upload/report.docx '));
    }

    #[Test]
    #[DataProvider('fileNameProvider')]
    public function normalizeDocxFileNameStripsDirectoriesAndEnforcesTheExtension(string $input, string $expected): void
    {
        $service = new DocxFileService(self::createStub(ResourceFactory::class));

        self::assertSame($expected, $service->normalizeDocxFileName($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fileNameProvider(): iterable
    {
        yield 'adds extension' => ['Report', 'Report.docx'];
        yield 'keeps existing extension' => ['Report.docx', 'Report.docx'];
        yield 'keeps uppercase extension' => ['Report.DOCX', 'Report.DOCX'];
        yield 'strips unix directories' => ['../../etc/Report.docx', 'Report.docx'];
        yield 'strips windows directories' => ['C:\\Users\\me\\Report', 'Report.docx'];
        yield 'trims whitespace' => ['  Report  ', 'Report.docx'];
    }

    #[Test]
    #[DataProvider('invalidFileNameProvider')]
    public function normalizeDocxFileNameRejectsUnusableNames(string $input): void
    {
        $service = new DocxFileService(self::createStub(ResourceFactory::class));

        $this->expectException(DocxEditorException::class);
        $this->expectExceptionCode(400);
        $service->normalizeDocxFileName($input);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidFileNameProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'dot' => ['.'];
        yield 'dot dot' => ['..'];
    }

    #[Test]
    public function buildFilePathLabelCombinesStorageNameAndIdentifier(): void
    {
        $storage = self::createStub(ResourceStorage::class);
        $storage->method('getName')->willReturn('fileadmin');
        $file = self::createStub(File::class);
        $file->method('getStorage')->willReturn($storage);
        $file->method('getIdentifier')->willReturn('/user_upload/report.docx');

        $service = new DocxFileService(self::createStub(ResourceFactory::class));

        self::assertSame('fileadmin / user_upload/report.docx', $service->buildFilePathLabel($file));
    }

    #[Test]
    public function buildFilePathLabelOmitsAnEmptyStorageName(): void
    {
        $storage = self::createStub(ResourceStorage::class);
        $storage->method('getName')->willReturn(' ');
        $file = self::createStub(File::class);
        $file->method('getStorage')->willReturn($storage);
        $file->method('getIdentifier')->willReturn('/report.docx');

        $service = new DocxFileService(self::createStub(ResourceFactory::class));

        self::assertSame('report.docx', $service->buildFilePathLabel($file));
    }

    #[Test]
    public function assertCanReadPassesForAdminsWithStorageReadAccess(): void
    {
        $storage = self::createStub(ResourceStorage::class);
        $storage->method('getUid')->willReturn(1);
        $storage->method('checkFileActionPermission')->willReturn(true);
        $file = self::createStub(File::class);
        $file->method('getStorage')->willReturn($storage);
        $backendUser = self::createStub(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(true);
        $GLOBALS['BE_USER'] = $backendUser;

        $service = new DocxFileService(self::createStub(ResourceFactory::class));
        $service->assertCanRead($file);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function assertCanReadMapsMissingStorageAccessTo403(): void
    {
        $storage = self::createStub(ResourceStorage::class);
        $storage->method('getUid')->willReturn(2);
        $file = self::createStub(File::class);
        $file->method('getStorage')->willReturn($storage);
        $backendUser = self::createStub(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('check')->willReturn(false);
        $GLOBALS['BE_USER'] = $backendUser;

        $service = new DocxFileService(self::createStub(ResourceFactory::class));

        $this->expectException(DocxEditorException::class);
        $this->expectExceptionCode(403);
        $service->assertCanRead($file);
    }

    #[Test]
    public function canWriteReturnsFalseInsteadOfThrowing(): void
    {
        $storage = self::createStub(ResourceStorage::class);
        $storage->method('getUid')->willReturn(2);
        $file = self::createStub(File::class);
        $file->method('getStorage')->willReturn($storage);
        $backendUser = self::createStub(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('check')->willReturn(false);
        $GLOBALS['BE_USER'] = $backendUser;

        self::assertFalse((new DocxFileService(self::createStub(ResourceFactory::class)))->canWrite($file));
    }

    #[Test]
    public function assertCanWriteWrapsFalPermissionExceptions(): void
    {
        $storage = self::createStub(ResourceStorage::class);
        $storage->method('getUid')->willReturn(1);
        $storage->method('checkFileActionPermission')
            ->willThrowException(new InsufficientFileAccessPermissionsException('nope', 1757600000));
        $file = self::createStub(File::class);
        $file->method('getStorage')->willReturn($storage);
        $backendUser = self::createStub(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(true);
        $GLOBALS['BE_USER'] = $backendUser;

        $service = new DocxFileService(self::createStub(ResourceFactory::class));

        $this->expectException(DocxEditorException::class);
        $this->expectExceptionCode(403);
        $service->assertCanWrite($file);
    }
}

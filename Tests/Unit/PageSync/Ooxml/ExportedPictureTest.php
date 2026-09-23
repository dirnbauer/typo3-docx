<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Unit\PageSync\Ooxml;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\DocxEditor\PageSync\Document\DocxDocument;
use Webconsulting\DocxEditor\PageSync\Document\Figure;
use Webconsulting\DocxEditor\PageSync\Document\Image;
use Webconsulting\DocxEditor\PageSync\Document\ImageData;
use Webconsulting\DocxEditor\PageSync\Manifest\ManifestField;
use Webconsulting\DocxEditor\PageSync\Manifest\ManifestPicture;
use Webconsulting\DocxEditor\PageSync\Manifest\ManifestRecord;
use Webconsulting\DocxEditor\PageSync\Manifest\ManifestXml;
use Webconsulting\DocxEditor\PageSync\Manifest\RoundTripManifest;
use Webconsulting\DocxEditor\PageSync\Ooxml\DocumentReader;
use Webconsulting\DocxEditor\PageSync\Ooxml\DocumentWriter;
use Webconsulting\DocxEditor\PageSync\Ooxml\DocxArchive;
use Webconsulting\DocxEditor\PageSync\Ooxml\WriteRequest;
use Webconsulting\DocxEditor\Tests\Fixtures\PageSync\DocxFixtureBuilder;

/**
 * An exported picture is a scaled copy; the reader recognises it — through the picture's name
 * and the signed manifest, never through the copy's bytes alone — as the file reference and file
 * it stands for.
 */
final class ExportedPictureTest extends UnitTestCase
{
    private const string FILE_SHA1 = 'c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00';

    protected bool $resetSingletonInstances = true;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-encryption-key-for-the-page-sync-manifest';
    }

    #[Test]
    public function anExportedPictureIsRecognisedAsItsFileReference(): void
    {
        $binary = $this->write(self::exported(self::copy()));

        self::assertStringContainsString('name="typo3:sys_file_reference:31"', DocxArchive::fromBinary($binary)->read('word/document.xml'));
        $image = $this->readImage($binary);
        self::assertSame([5, 31, self::FILE_SHA1], [$image->fileUid, $image->referenceUid, $image->fileSha1]);
        self::assertSame(self::FILE_SHA1, $image->identity(), 'The file it stands for, not the copy');
    }

    #[Test]
    public function aPictureWithOtherBytesUnderTheSameNameIsAnotherPicture(): void
    {
        $replaced = DocxFixtureBuilder::photo(16, 8, 2);
        $image = $this->readImage($this->write(self::exported($replaced), self::manifest(sha1(self::copy()))));

        self::assertSame([0, 0, ''], [$image->fileUid, $image->referenceUid, $image->fileSha1]);
        self::assertSame(sha1($replaced), $image->identity());
    }

    #[Test]
    public function aRenamedPictureIsRecognisedByItsBytes(): void
    {
        $image = $this->readImage($this->write(new Image(new ImageData(self::copy(), 'image/png', 'office.png'), 'Our office'), self::manifest(sha1(self::copy()))));

        self::assertSame([5, 31], [$image->fileUid, $image->referenceUid]);
    }

    #[Test]
    public function aManifestEditedByHandRecognisesNothing(): void
    {
        $archive = DocxArchive::fromBinary($this->write(self::exported(self::copy())));
        $parts = [];
        foreach ($archive->partNames() as $name) {
            $parts[$name] = $archive->read($name);
        }
        $parts['customXml/item1.xml'] = str_replace('file="5"', 'file="6"', $parts['customXml/item1.xml']);

        $document = $this->reader()->read(DocxFixtureBuilder::zip($parts));
        self::assertFalse($document->manifest?->trusted);
        self::assertSame(0, self::firstImage($document)->fileUid);
    }

    #[Test]
    public function picturesAndStoredLinksTravelInTheManifest(): void
    {
        $manifest = $this->reader()->read($this->write(self::exported(self::copy())))->manifest;

        self::assertNotNull($manifest);
        self::assertTrue($manifest->trusted);
        self::assertEquals([new ManifestPicture(31, 5, self::FILE_SHA1, sha1(self::copy()))], $manifest->pictures);
        self::assertSame('t3://page?uid=12 _blank', $manifest->record('tt_content', 11)?->field('link')?->value);
    }

    /**
     * The signature is computed over toCanonicalArray(): a manifest without pictures and stored
     * values must give exactly what 2.2 signed, or every document exported before would lose its
     * trust.
     */
    #[Test]
    public function manifestsOfEarlierExportsStillVerify(): void
    {
        $manifest = new RoundTripManifest(7, 'main', 0, 0, new \DateTimeImmutable('2026-09-23T12:00:00+00:00'), [
            'tt_content:11' => new ManifestRecord('tt_content', 11, ['header' => new ManifestField('header', 'abc', 2, 1)], 'text', 0, 1, reference: 1),
        ]);

        self::assertSame([
            'version' => 1,
            'page' => 7,
            'site' => 'main',
            'language' => 0,
            'workspace' => 0,
            'exported' => '2026-09-23T12:00:00+00:00',
            'exportedBy' => 0,
            'records' => [['tt_content', 11, 'text', 0, 1, '', '', 0, false, false, '', 1, [['header', 'abc', 2, 1]]]],
        ], $manifest->toCanonicalArray());
    }

    private function write(Image $image, ?RoundTripManifest $manifest = null): string
    {
        return new DocumentWriter(new ManifestXml(new HashService()))->write(new WriteRequest(
            [new Figure($image)],
            $manifest ?? self::manifest(sha1(self::copy())),
        ));
    }

    private function readImage(string $binary): Image
    {
        return self::firstImage($this->reader()->read($binary));
    }

    private function reader(): DocumentReader
    {
        return new DocumentReader(new ManifestXml(new HashService()));
    }

    private static function firstImage(DocxDocument $document): Image
    {
        $figure = $document->blocks[0] ?? null;
        self::assertInstanceOf(Figure::class, $figure);

        return $figure->image;
    }

    /**
     * The picture as the export writes it: the copy's bytes, and the reference and file it stands for.
     */
    private static function exported(string $bytes): Image
    {
        return new Image(new ImageData($bytes, 'image/png', 'office.png'), 'Our office', '', 0, 0, 5, 31, self::FILE_SHA1);
    }

    private static function copy(): string
    {
        return DocxFixtureBuilder::photo(16, 8);
    }

    private static function manifest(string $embedded): RoundTripManifest
    {
        return new RoundTripManifest(
            pageUid: 7,
            siteIdentifier: 'main',
            language: 0,
            workspace: 0,
            exportedAt: new \DateTimeImmutable('2026-09-23T12:00:00+00:00'),
            records: [
                'tt_content:11' => new ManifestRecord('tt_content', 11, [
                    'image' => new ManifestField('image', 'abc', 0, 1),
                    'link' => new ManifestField('link', '', 0, 2, 't3://page?uid=12 _blank'),
                ], 'textpic', 0, 1, reference: 1),
            ],
            pictures: [new ManifestPicture(31, 5, self::FILE_SHA1, $embedded)],
        );
    }
}

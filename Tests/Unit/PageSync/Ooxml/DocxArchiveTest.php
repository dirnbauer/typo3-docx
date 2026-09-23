<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Unit\PageSync\Ooxml;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\DocxEditor\PageSync\Exception\PageSyncException;
use Webconsulting\DocxEditor\PageSync\Ooxml\ArchiveLimits;
use Webconsulting\DocxEditor\PageSync\Ooxml\DocxArchive;
use Webconsulting\DocxEditor\PageSync\Ooxml\SafeXml;
use Webconsulting\DocxEditor\Tests\Fixtures\PageSync\DocxFixtureBuilder;

/**
 * An uploaded .docx is untrusted input.
 */
final class DocxArchiveTest extends UnitTestCase
{
    #[Test]
    public function somethingThatIsNotAZipIsRefused(): void
    {
        $this->expectExceptionObject(new PageSyncException('error.notADocx', 415));
        DocxArchive::fromBinary('%PDF-1.7 not a word document');
    }

    #[Test]
    public function aFileAboveTheSizeLimitIsRefusedBeforeItIsUnpacked(): void
    {
        $this->expectExceptionCode(413);
        DocxArchive::fromBinary(DocxFixtureBuilder::body('<w:p/>')->build(), new ArchiveLimits(maxArchiveBytes: 100));
    }

    #[Test]
    public function tooManyPartsAreRefused(): void
    {
        $parts = [];
        for ($i = 0; $i < 12; $i++) {
            $parts['word/part' . $i . '.xml'] = '<x/>';
        }

        $this->expectExceptionCode(422);
        DocxArchive::fromBinary(DocxFixtureBuilder::zip($parts), new ArchiveLimits(maxEntries: 10));
    }

    #[Test]
    public function aZipBombIsRefused(): void
    {
        // 30 MB of zeros compress to a few kilobytes.
        $binary = DocxFixtureBuilder::zip(['word/document.xml' => str_repeat("\0", 30 * 1024 * 1024)]);

        try {
            DocxArchive::fromBinary($binary);
            self::fail('The bomb was unpacked');
        } catch (PageSyncException $exception) {
            self::assertSame('error.zipBomb', $exception->labelKey);
        }
    }

    #[Test]
    public function aPartLargerThanAllowedIsRefused(): void
    {
        $binary = DocxFixtureBuilder::zip(['word/document.xml' => random_bytes(2048)]);

        try {
            DocxArchive::fromBinary($binary, new ArchiveLimits(maxEntryBytes: 1024));
            self::fail('The part was accepted');
        } catch (PageSyncException $exception) {
            self::assertSame('error.partTooLarge', $exception->labelKey);
        }
    }

    #[Test]
    public function partNamesThatLeaveThePackageAreRefused(): void
    {
        $binary = DocxFixtureBuilder::zip(['word/document.xml' => '<x/>', '../../etc/passwd' => 'x']);

        try {
            DocxArchive::fromBinary($binary);
            self::fail('The traversal name was accepted');
        } catch (PageSyncException $exception) {
            self::assertSame('error.unsafePartName', $exception->labelKey);
        }
    }

    #[Test]
    public function documentTypeDeclarationsAreRefused(): void
    {
        $this->expectExceptionObject(new PageSyncException('error.unsafeXml', 422, ['word/document.xml']));
        SafeXml::load('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "aaaa"><!ENTITY b "&a;&a;&a;">]><x>&b;</x>', 'word/document.xml');
    }

    #[Test]
    public function partNamesAreCaseInsensitiveAndRelationshipsResolve(): void
    {
        $archive = DocxArchive::fromBinary(DocxFixtureBuilder::body('<w:p/>')->build());

        self::assertTrue($archive->has('WORD/Document.XML'));
        self::assertSame('word/document.xml', $archive->mainDocumentPart());
        self::assertSame('customXml/item1.xml', DocxArchive::resolvePartName('word', '../customXml/item1.xml'));
        self::assertSame('word/media/a.png', DocxArchive::resolvePartName('word', 'media/./a.png'));
        self::assertSame('docProps/core.xml', DocxArchive::resolvePartName('word', '/docProps/core.xml'));
    }
}

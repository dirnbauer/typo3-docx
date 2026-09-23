<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Ooxml;

/**
 * How much of an uploaded package the reader is willing to unpack. The defaults are generous
 * for real documents and far below what a zip bomb needs.
 */
final readonly class ArchiveLimits
{
    public function __construct(
        /** Size of the .docx file itself. */
        public int $maxArchiveBytes = 50 * 1024 * 1024,
        public int $maxEntries = 2000,
        /** Uncompressed size of one part (a picture, document.xml). */
        public int $maxEntryBytes = 40 * 1024 * 1024,
        /** Uncompressed size of every part together. */
        public int $maxTotalBytes = 200 * 1024 * 1024,
        /** Compression ratio of one part above which it counts as a bomb (XML compresses ~10:1). */
        public int $maxRatio = 200,
    ) {}

    public static function fromMegabytes(int $maxArchiveMegabytes): self
    {
        $maxArchiveMegabytes = max(1, $maxArchiveMegabytes);

        return new self(
            maxArchiveBytes: $maxArchiveMegabytes * 1024 * 1024,
            maxEntryBytes: max(8, $maxArchiveMegabytes) * 1024 * 1024,
            maxTotalBytes: max(40, 4 * $maxArchiveMegabytes) * 1024 * 1024,
        );
    }
}

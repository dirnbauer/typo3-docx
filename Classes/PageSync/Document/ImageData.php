<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

/**
 * The bytes of a picture, as embedded in the Word package or read from FAL.
 *
 * The bytes may come from a loader that runs the first time they are needed: a picture exported
 * from TYPO3 is scaled down only when a document is written, never when an import merely
 * compares which pictures a field holds. Such a copy may have another format than the file, so
 * its MIME type can be told from the bytes once they are there.
 */
final class ImageData
{
    public string $bytes {
        get => $this->loaded ??= ($this->loader)();
    }

    public string $mimeType {
        get => $this->resolvedMimeType ??= is_string($this->mimeTypeSource) ? $this->mimeTypeSource : ($this->mimeTypeSource)($this->bytes);
    }

    private ?string $loaded = null;

    private ?string $sha1 = null;

    private ?string $resolvedMimeType = null;

    /** @var \Closure(): string */
    private readonly \Closure $loader;

    /**
     * @param string|\Closure(): string $bytes The bytes, or what produces them
     * @param string|\Closure(string): string $mimeTypeSource The MIME type, or what tells it from the bytes
     */
    public function __construct(
        string|\Closure $bytes,
        private readonly string|\Closure $mimeTypeSource,
        public readonly string $fileName,
    ) {
        if (is_string($bytes)) {
            $this->loaded = $bytes;
            $this->loader = static fn(): string => '';
        } else {
            $this->loader = $bytes;
        }
    }

    public function sha1(): string
    {
        return $this->sha1 ??= sha1($this->bytes);
    }

    public function size(): int
    {
        return strlen($this->bytes);
    }

    /**
     * The file extension that matches the MIME type, which is what the Word package and FAL
     * decide by — never the name an author typed.
     */
    public function extension(): string
    {
        return match ($this->mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tiff',
            default => strtolower(pathinfo($this->fileName, PATHINFO_EXTENSION)),
        };
    }
}

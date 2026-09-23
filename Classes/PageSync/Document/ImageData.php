<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

/**
 * The bytes of a picture, as embedded in the Word package or read from FAL.
 */
final class ImageData
{
    private ?string $sha1 = null;

    public function __construct(
        public readonly string $bytes,
        public readonly string $mimeType,
        public readonly string $fileName,
    ) {}

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

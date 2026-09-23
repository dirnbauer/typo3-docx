<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

/**
 * A picture with its accessibility text. Sizes are in EMU (914400 per inch) as Word stores
 * them; zero means "unknown, let the writer decide".
 *
 * A picture exported from TYPO3 — or recognised on import as one, through the manifest — knows
 * the file reference and the file it stands for. Its bytes are then a smaller copy of that file,
 * so what the picture *is* (see identity()) is the file's hash, not the hash of the bytes.
 */
final readonly class Image
{
    public function __construct(
        public ImageData $data,
        public string $alternative = '',
        public string $title = '',
        public int $widthEmu = 0,
        public int $heightEmu = 0,
        /** The sys_file uid the picture stands for. */
        public int $fileUid = 0,
        /** The sys_file_reference uid the picture was exported from. */
        public int $referenceUid = 0,
        /** The sha1 of that file in TYPO3; empty for a picture that came from Word. */
        public string $fileSha1 = '',
    ) {}

    /**
     * What decides whether two pictures are the same: the TYPO3 file a picture stands for, or,
     * for a picture from Word, its bytes.
     */
    public function identity(): string
    {
        return $this->fileSha1 !== '' ? $this->fileSha1 : $this->data->sha1();
    }
}

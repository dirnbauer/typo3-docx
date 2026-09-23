<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

/**
 * A picture with its accessibility text. Sizes are in EMU (914400 per inch) as Word stores
 * them; zero means "unknown, let the writer decide".
 */
final readonly class Image
{
    public function __construct(
        public ImageData $data,
        public string $alternative = '',
        public string $title = '',
        public int $widthEmu = 0,
        public int $heightEmu = 0,
        /** The sys_file uid the picture came from, when it was exported from TYPO3. */
        public int $fileUid = 0,
    ) {}
}

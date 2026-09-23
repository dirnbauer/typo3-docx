<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Export;

use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use Webconsulting\DocxEditor\PageSync\Configuration\PageSyncSettings;
use Webconsulting\DocxEditor\PageSync\Ooxml\DocumentWriter;

/**
 * The bytes an exported picture is embedded with: a copy made by TYPO3's image processing, as
 * large as Word shows the picture at the configured resolution (pageSync.pictureResolution) and
 * no longer than pageSync.pictureMaxEdge on its long edge, in the file's own format.
 *
 * A page with a dozen camera pictures would otherwise export to more than the upload limit and
 * could not come back. The copy is only what the document shows: an unchanged picture comes
 * back as the same file reference (the manifest records which copy stands for which file), so
 * the smaller copy never replaces the file in TYPO3.
 *
 * Pictures that are small enough already, GIFs (they may be animated) and pictures TYPO3 cannot
 * process are embedded as they are, as is a copy that would not be smaller than the file.
 */
final readonly class PictureDerivatives
{
    /** Formats TYPO3 scales into the same format, with the extension it is given. */
    private const array SCALABLE = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private PageSyncSettings $settings,
    ) {}

    /**
     * The bytes to embed for a picture file.
     */
    public function bytes(File $file): string
    {
        $extension = self::SCALABLE[$file->getMimeType()] ?? null;
        [$width, $height] = self::dimensions($file);
        $target = $extension === null ? null : self::targetSize($width, $height, $this->settings->pictureResolution(), $this->settings->pictureMaxEdge());
        if ($target === null) {
            return $file->getContents();
        }

        try {
            $processed = $file->process(ProcessedFile::CONTEXT_IMAGECROPSCALEMASK, [
                'maxWidth' => $target[0],
                'maxHeight' => $target[1],
                'fileExtension' => $extension,
            ]);
            if ($processed->usesOriginalFile() || !$processed->exists()) {
                return $file->getContents();
            }
            $bytes = $processed->getContents();
        } catch (\Throwable) {
            // No image processor, or it failed: the file as it is.
            return $file->getContents();
        }
        $info = $bytes === '' ? false : @getimagesizefromstring($bytes);
        if (!is_array($info) || $info['mime'] !== $file->getMimeType() || strlen($bytes) >= $file->getSize()) {
            return $file->getContents();
        }

        return $bytes;
    }

    /**
     * The pixel size to embed a picture of the given size at, or null when it is small enough.
     *
     * Word shows a picture without a set size at 96 pixels per inch, at most as wide as the
     * text; the copy has $resolution pixels per inch at that size, and no edge longer than
     * $maxEdge. A limit of 0 is no limit. Never larger than the picture.
     *
     * @return array{0: positive-int, 1: positive-int}|null
     */
    public static function targetSize(int $width, int $height, int $resolution, int $maxEdge): ?array
    {
        if ($width <= 0 || $height <= 0) {
            return null;
        }
        $scale = 1.0;
        if ($resolution > 0) {
            $shownInches = min($width * DocumentWriter::EMU_PER_PIXEL, DocumentWriter::MAX_IMAGE_WIDTH_EMU) / DocumentWriter::EMU_PER_INCH;
            $scale = min($scale, ceil($shownInches * $resolution) / $width);
        }
        if ($maxEdge > 0) {
            $scale = min($scale, $maxEdge / max($width, $height));
        }
        if ($scale >= 1.0) {
            return null;
        }

        return [max(1, (int)round($width * $scale)), max(1, (int)round($height * $scale))];
    }

    /**
     * The picture's pixel size from its metadata, or measured when the metadata has none.
     *
     * @return array{0: int, 1: int}
     */
    public static function dimensions(File $file): array
    {
        $width = $file->getProperty('width');
        $height = $file->getProperty('height');
        if (is_numeric($width) && is_numeric($height) && (int)$width > 0 && (int)$height > 0) {
            return [(int)$width, (int)$height];
        }
        try {
            $info = @getimagesize($file->getForLocalProcessing(false));
        } catch (\Throwable) {
            return [0, 0];
        }

        return is_array($info) ? [$info[0], $info[1]] : [0, 0];
    }
}

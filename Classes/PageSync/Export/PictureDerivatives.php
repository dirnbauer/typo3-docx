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
 * Word saves a WebP picture back as PNG, which would make every exported WebP come back as a
 * picture the editor put in. A WebP is therefore always embedded as a copy in a format Word
 * keeps byte for byte: JPEG, or PNG when it has transparency.
 *
 * Pictures that are small enough already, GIFs and animated WebPs (a copy would stand still) and
 * pictures TYPO3 cannot process are embedded as they are, as is a copy in the file's own format
 * that would not be smaller than the file.
 */
final readonly class PictureDerivatives
{
    /** Formats TYPO3 scales into the same format, with the extension it is given. */
    private const array SCALABLE = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    private const array MIME_TYPES = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    public function __construct(
        private PageSyncSettings $settings,
    ) {}

    /**
     * The bytes to embed for a picture file.
     */
    public function bytes(File $file): string
    {
        [$width, $height] = self::dimensions($file);
        $target = self::targetSize($width, $height, $this->settings->pictureResolution(), $this->settings->pictureMaxEdge());

        if ($file->getMimeType() === 'image/webp') {
            $contents = $file->getContents();
            $extension = self::webpFormat($contents);
            if ($extension === null || $width <= 0 || $height <= 0) {
                return $contents;
            }

            return $this->copy($file, $target ?? [$width, $height], $extension) ?? $contents;
        }

        $extension = self::SCALABLE[$file->getMimeType()] ?? null;
        $copy = $extension === null || $target === null ? null : $this->copy($file, $target, $extension);

        return $copy !== null && strlen($copy) < $file->getSize() ? $copy : $file->getContents();
    }

    /**
     * The MIME type of the bytes bytes() gave for a file: the copy's format, or the file's own.
     */
    public static function mimeType(string $bytes, string $fileMimeType): string
    {
        $info = $bytes === '' ? false : @getimagesizefromstring($bytes);

        return is_array($info) && in_array($info['mime'], self::MIME_TYPES, true) ? $info['mime'] : $fileMimeType;
    }

    /**
     * The format a WebP is embedded as: "png" when it has transparency, "jpg" when not, null for
     * an animated WebP (a copy would be its first frame) and for bytes that are no WebP.
     */
    public static function webpFormat(string $bytes): ?string
    {
        if (strlen($bytes) < 30 || !str_starts_with($bytes, 'RIFF') || substr($bytes, 8, 4) !== 'WEBP') {
            return null;
        }

        return match (substr($bytes, 12, 4)) {
            // Extended format: the flags byte holds alpha (0x10) and animation (0x02).
            'VP8X' => (ord($bytes[20]) & 0x02) !== 0 ? null : ((ord($bytes[20]) & 0x10) !== 0 ? 'png' : 'jpg'),
            // Lossless: bit 28 of the header after the signature byte is "alpha is used".
            'VP8L' => (ord($bytes[24]) & 0x10) !== 0 ? 'png' : 'jpg',
            'VP8 ' => 'jpg',
            default => null,
        };
    }

    /**
     * A copy made by TYPO3's image processing in the given format, or null when there is none.
     *
     * @param array{0: int, 1: int} $size
     */
    private function copy(File $file, array $size, string $extension): ?string
    {
        try {
            $processed = $file->process(ProcessedFile::CONTEXT_IMAGECROPSCALEMASK, [
                'maxWidth' => $size[0],
                'maxHeight' => $size[1],
                'fileExtension' => $extension,
            ]);
            if ($processed->usesOriginalFile() || !$processed->exists()) {
                return null;
            }
            $bytes = $processed->getContents();
        } catch (\Throwable) {
            // No image processor, or it failed.
            return null;
        }
        $info = $bytes === '' ? false : @getimagesizefromstring($bytes);

        return is_array($info) && $info['mime'] === self::MIME_TYPES[$extension] ? $bytes : null;
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

<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Record;

use TYPO3\CMS\Core\Resource\FileReference;
use Webconsulting\DocxEditor\PageSync\Document\Figure;
use Webconsulting\DocxEditor\PageSync\Document\Image;
use Webconsulting\DocxEditor\PageSync\Document\ImageData;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Export\PictureDerivatives;
use Webconsulting\DocxEditor\PageSync\Ooxml\DocumentWriter;
use Webconsulting\DocxEditor\PageSync\Ooxml\Reader\ReadContext;

/**
 * The pictures of a file field as figures: the file reference and file each one stands for, the
 * reference's alt text, title and caption, and — read only when a document is written — the
 * bytes to embed (see PictureDerivatives). Files that are not web pictures are left out.
 */
final readonly class FigureLoader
{
    public function __construct(
        private RecordRepository $records,
        private PictureDerivatives $derivatives,
    ) {}

    /**
     * @param array<string, mixed> $record
     *
     * @return list<Figure>
     */
    public function figures(string $table, array $record, string $field, int $workspaceId): array
    {
        $figures = [];
        foreach ($this->records->fileReferences($table, $record, $field, $workspaceId) as $reference) {
            $figure = $this->figure($reference);
            if ($figure !== null) {
                $figures[] = $figure;
            }
        }

        return $figures;
    }

    /**
     * Whether every reference of the field is a web picture whose file is there — otherwise the
     * field is read-only in Word, because writing it back would drop the other files (or the
     * reference to a file that went missing from the storage).
     *
     * @param array<string, mixed> $record
     */
    public function onlyPictures(string $table, array $record, string $field, int $workspaceId): bool
    {
        foreach ($this->records->fileReferences($table, $record, $field, $workspaceId) as $reference) {
            if (!self::isPicture($reference)) {
                return false;
            }
        }

        return true;
    }

    public function figure(FileReference $reference): ?Figure
    {
        if (!self::isPicture($reference)) {
            return null;
        }
        $file = $reference->getOriginalFile();
        $alternative = $reference->getProperty('alternative');
        $title = $reference->getProperty('title');
        $caption = $reference->getProperty('description');
        $caption = is_scalar($caption) ? trim((string)$caption) : '';
        // Shown at the file's own size, whatever size the embedded copy has.
        [$width, $height] = PictureDerivatives::dimensions($file);

        return new Figure(
            new Image(
                new ImageData(fn(): string => $this->derivatives->bytes($file), $file->getMimeType(), $file->getName()),
                is_scalar($alternative) ? (string)$alternative : '',
                is_scalar($title) ? (string)$title : '',
                $width * DocumentWriter::EMU_PER_PIXEL,
                $height * DocumentWriter::EMU_PER_PIXEL,
                $file->getUid(),
                $reference->getUid(),
                $file->getSha1(),
            ),
            $caption === '' ? [] : [new Text($caption)],
        );
    }

    private static function isPicture(FileReference $reference): bool
    {
        try {
            $file = $reference->getOriginalFile();

            return in_array($file->getMimeType(), ReadContext::IMAGE_MIME_TYPES, true) && $file->getSize() > 0 && $file->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}

<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Record;

use TYPO3\CMS\Core\Resource\FileReference;
use Webconsulting\DocxEditor\PageSync\Document\Figure;
use Webconsulting\DocxEditor\PageSync\Document\Image;
use Webconsulting\DocxEditor\PageSync\Document\ImageData;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Ooxml\Reader\ReadContext;

/**
 * The pictures of a file field as figures: the file's bytes, the reference's alt text, title and
 * caption. Files that are not web pictures are left out.
 */
final readonly class FigureLoader
{
    public function __construct(
        private RecordRepository $records,
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
            $figure = self::figure($reference);
            if ($figure !== null) {
                $figures[] = $figure;
            }
        }

        return $figures;
    }

    /**
     * Whether every reference of the field is a web picture — otherwise the field is read-only
     * in Word, because writing it back would drop the other files.
     *
     * @param array<string, mixed> $record
     */
    public function onlyPictures(string $table, array $record, string $field, int $workspaceId): bool
    {
        foreach ($this->records->fileReferences($table, $record, $field, $workspaceId) as $reference) {
            if (!in_array($reference->getOriginalFile()->getMimeType(), ReadContext::IMAGE_MIME_TYPES, true)) {
                return false;
            }
        }

        return true;
    }

    public static function figure(FileReference $reference): ?Figure
    {
        $file = $reference->getOriginalFile();
        if (!in_array($file->getMimeType(), ReadContext::IMAGE_MIME_TYPES, true)) {
            return null;
        }
        try {
            $bytes = $file->getContents();
        } catch (\Throwable) {
            return null;
        }
        $alternative = $reference->getProperty('alternative');
        $title = $reference->getProperty('title');
        $caption = $reference->getProperty('description');
        $caption = is_scalar($caption) ? trim((string)$caption) : '';

        return new Figure(
            new Image(
                new ImageData($bytes, $file->getMimeType(), $file->getName()),
                is_scalar($alternative) ? (string)$alternative : '',
                is_scalar($title) ? (string)$title : '',
                0,
                0,
                $file->getUid(),
            ),
            $caption === '' ? [] : [new Text($caption)],
        );
    }
}

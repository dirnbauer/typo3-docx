<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Ooxml\Reader;

use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Document\Image;
use Webconsulting\DocxEditor\PageSync\Document\ImageData;
use Webconsulting\DocxEditor\PageSync\Ooxml\DocxArchive;
use Webconsulting\DocxEditor\PageSync\Ooxml\NumberingCatalog;
use Webconsulting\DocxEditor\PageSync\Ooxml\ReadWarning;
use Webconsulting\DocxEditor\PageSync\Ooxml\Relationship;
use Webconsulting\DocxEditor\PageSync\Ooxml\StyleCatalog;

/**
 * What the reader needs while it walks the main document part: the package, the part's
 * relationships, styles and numbering, and a place to collect warnings and the content of
 * text boxes (which is appended after the paragraph that anchors it).
 *
 * @internal
 */
final class ReadContext
{
    /** Picture formats a website can show. EMF/WMF clip art and TIFF scans are reported instead. */
    public const array IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /** @var list<ReadWarning> */
    public array $warnings = [];

    /** @var array<string, string> bookmark id => name */
    public array $bookmarkNames = [];

    /** @var list<string> round-trip bookmarks that start inside the paragraph being read */
    public array $paragraphBookmarkStarts = [];

    /** @var list<string> round-trip bookmarks that end inside the paragraph being read */
    public array $paragraphBookmarkEnds = [];

    /** @var list<Block> */
    private array $floating = [];

    /** @var array<string, ImageData|false> */
    private array $images = [];

    /**
     * @param array<string, Relationship> $relationships
     */
    public function __construct(
        public readonly DocxArchive $archive,
        public readonly array $relationships,
        public readonly StyleCatalog $styles,
        public readonly NumberingCatalog $numbering,
    ) {}

    public function warn(string $labelKey, string|int ...$arguments): void
    {
        $warning = new ReadWarning($labelKey, array_values($arguments));
        foreach ($this->warnings as $existing) {
            if ($existing == $warning) {
                return;
            }
        }
        $this->warnings[] = $warning;
    }

    public function hyperlinkTarget(string $relationshipId): string
    {
        $relationship = $this->relationships[$relationshipId] ?? null;

        return $relationship === null ? '' : $relationship->target;
    }

    public function image(string $relationshipId, string $alternative, string $title, int $widthEmu, int $heightEmu): ?Image
    {
        $relationship = $this->relationships[$relationshipId] ?? null;
        if ($relationship === null) {
            return null;
        }
        if ($relationship->external) {
            $this->warn('warning.linkedImage', $relationship->target);

            return null;
        }

        $data = $this->images[$relationship->target] ??= $this->loadImage($relationship->target);
        if ($data === false) {
            return null;
        }

        return new Image($data, trim($alternative), trim($title), max(0, $widthEmu), max(0, $heightEmu));
    }

    /**
     * @param list<Block> $blocks
     */
    public function addFloating(array $blocks): void
    {
        foreach ($blocks as $block) {
            $this->floating[] = $block;
        }
    }

    /**
     * @return list<Block>
     */
    public function takeFloating(): array
    {
        $floating = $this->floating;
        $this->floating = [];

        return $floating;
    }

    private function loadImage(string $partName): ImageData|false
    {
        if (!$this->archive->has($partName)) {
            $this->warn('warning.missingImage', basename($partName));

            return false;
        }
        $bytes = $this->archive->read($partName);
        $info = $bytes === '' ? false : @getimagesizefromstring($bytes);
        $mimeType = is_array($info) ? $info['mime'] : '';
        if (!in_array($mimeType, self::IMAGE_MIME_TYPES, true)) {
            $this->warn('warning.unsupportedImage', basename($partName));

            return false;
        }

        return new ImageData($bytes, $mimeType, basename($partName));
    }
}

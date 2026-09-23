<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Ooxml\Writer;

use Webconsulting\DocxEditor\PageSync\Document\ImageData;
use Webconsulting\DocxEditor\PageSync\Document\ListBlock;
use Webconsulting\DocxEditor\PageSync\Ooxml\Ns;

/**
 * Everything the writer allocates while it builds document.xml: relationships, media parts,
 * list definitions and the ids of content controls, bookmarks and drawings.
 *
 * @internal
 */
final class WriterState
{
    /** @var list<array{id: string, type: string, target: string, external: bool}> */
    public array $relationships = [];

    /** @var array<string, array{part: string, relationshipId: string, data: ImageData}> sha1 => media part */
    public array $media = [];

    /** @var array<string, array{id: int, levels: array<int, bool>}> level pattern => abstract numbering */
    public array $abstractNumberings = [];

    /** @var list<array{numId: int, abstractId: int, ordered: bool}> */
    public array $numberings = [];

    /** @var array<string, int> bookmark name => id */
    public array $bookmarkIds = [];

    /** @var array<string, string> href => relationship id */
    private array $hyperlinks = [];

    private int $relationshipCounter = 0;
    private int $controlId = 1000;
    private int $drawingId = 0;
    private int $bookmarkCounter = 0;

    public function relationship(string $type, string $target, bool $external = false): string
    {
        $id = 'rId' . (++$this->relationshipCounter);
        $this->relationships[] = ['id' => $id, 'type' => $type, 'target' => $target, 'external' => $external];

        return $id;
    }

    public function hyperlink(string $href): string
    {
        return $this->hyperlinks[$href] ??= $this->relationship(Ns::REL_HYPERLINK, $href, true);
    }

    public function image(ImageData $data): string
    {
        $key = $data->sha1();
        if (!isset($this->media[$key])) {
            $part = 'media/image' . (count($this->media) + 1) . '.' . $data->extension();
            $this->media[$key] = [
                'part' => $part,
                'relationshipId' => $this->relationship(Ns::REL_IMAGE, $part),
                'data' => $data,
            ];
        }

        return $this->media[$key]['relationshipId'];
    }

    /**
     * A numbering instance for one list, so every list starts counting at one.
     */
    public function numbering(ListBlock $list): int
    {
        $levels = [];
        foreach ($list->items as $item) {
            $levels[$item->level] ??= $item->ordered;
        }
        $defaultOrdered = $levels[0] ?? $list->isOrdered();
        $pattern = '';
        for ($level = 0; $level <= 8; $level++) {
            $levels[$level] ??= $defaultOrdered;
            $pattern .= $levels[$level] ? 'o' : 'b';
        }
        ksort($levels);
        $abstract = $this->abstractNumberings[$pattern] ??= ['id' => count($this->abstractNumberings), 'levels' => $levels];

        $numId = count($this->numberings) + 1;
        $this->numberings[] = ['numId' => $numId, 'abstractId' => $abstract['id'], 'ordered' => $levels[0]];

        return $numId;
    }

    public function controlId(): int
    {
        return ++$this->controlId;
    }

    public function drawingId(): int
    {
        return ++$this->drawingId;
    }

    public function bookmarkId(string $name): int
    {
        return $this->bookmarkIds[$name] ??= ++$this->bookmarkCounter;
    }
}

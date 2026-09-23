<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Fixtures\PageSync;

use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Document\ContentControl;
use Webconsulting\DocxEditor\PageSync\Document\DocxDocument;

/**
 * Edits a read Word document the way an author would in Word — change what a field holds,
 * delete or move an element, write new content between elements — so tests can hand the result
 * back to the import.
 */
final class DocumentEditor
{
    /** @var list<Block> */
    private array $blocks;

    public function __construct(
        private readonly DocxDocument $document,
    ) {
        $this->blocks = $document->blocks;
    }

    /**
     * @param list<Block> $content
     */
    public function replace(string $tag, array $content): self
    {
        $this->blocks = self::map($this->blocks, static fn(ContentControl $control): array => $control->tag === $tag
            ? [new ContentControl($control->tag, $control->alias, $content, $control->lock, false, $control->bookmarks)]
            : [$control]);

        return $this;
    }

    public function remove(string $tag): self
    {
        $this->blocks = self::map($this->blocks, static fn(ContentControl $control): array => $control->tag === $tag ? [] : [$control]);

        return $this;
    }

    /**
     * Unwraps a control the way Word's "Remove Content Control" does: the content stays.
     */
    public function unwrap(string $tag): self
    {
        $this->blocks = self::map($this->blocks, static fn(ContentControl $control): array => $control->tag === $tag ? $control->blocks : [$control]);

        return $this;
    }

    /**
     * @param list<Block> $content
     */
    public function insertAfter(string $tag, array $content): self
    {
        $this->blocks = self::map($this->blocks, static fn(ContentControl $control): array => $control->tag === $tag ? [$control, ...$content] : [$control]);

        return $this;
    }

    /**
     * @param list<Block> $content
     */
    public function insertBefore(string $tag, array $content): self
    {
        $this->blocks = self::map($this->blocks, static fn(ContentControl $control): array => $control->tag === $tag ? [...$content, $control] : [$control]);

        return $this;
    }

    /**
     * @param list<Block> $content Appended inside the control, after what it holds
     */
    public function append(string $tag, array $content): self
    {
        $this->blocks = self::map($this->blocks, static fn(ContentControl $control): array => $control->tag === $tag
            ? [new ContentControl($control->tag, $control->alias, [...$control->blocks, ...$content], $control->lock, false, $control->bookmarks)]
            : [$control]);

        return $this;
    }

    public function move(string $tag, string $beforeTag): self
    {
        $moved = self::find($this->blocks, $tag);
        if ($moved === null) {
            throw new \RuntimeException('No control ' . $tag, 1758620000);
        }

        return $this->remove($tag)->insertBefore($beforeTag, [$moved]);
    }

    /**
     * @param list<Block> $blocks
     */
    public function appendToDocument(array $blocks): self
    {
        array_push($this->blocks, ...$blocks);

        return $this;
    }

    public function document(): DocxDocument
    {
        return new DocxDocument($this->blocks, $this->document->manifest, $this->document->customProperties, $this->document->title);
    }

    /**
     * @param list<Block> $blocks
     */
    public static function find(array $blocks, string $tag): ?ContentControl
    {
        foreach ($blocks as $block) {
            if (!$block instanceof ContentControl) {
                continue;
            }
            if ($block->tag === $tag) {
                return $block;
            }
            $found = self::find($block->blocks, $tag);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param list<Block> $blocks
     * @param \Closure(ContentControl): list<Block> $callback
     *
     * @return list<Block>
     */
    private static function map(array $blocks, \Closure $callback): array
    {
        $result = [];
        foreach ($blocks as $block) {
            if (!$block instanceof ContentControl) {
                $result[] = $block;
                continue;
            }
            $inner = new ContentControl($block->tag, $block->alias, self::map($block->blocks, $callback), $block->lock, $block->showingPlaceholder, $block->bookmarks);
            array_push($result, ...$callback($inner));
        }

        return $result;
    }
}

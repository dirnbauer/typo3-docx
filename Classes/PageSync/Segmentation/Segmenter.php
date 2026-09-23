<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Segmentation;

use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Document\Bookmark;
use Webconsulting\DocxEditor\PageSync\Document\CodeBlock;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\HorizontalRule;
use Webconsulting\DocxEditor\PageSync\Document\PageBreak;
use Webconsulting\DocxEditor\PageSync\Document\Quote;
use Webconsulting\DocxEditor\PageSync\Document\Table;

/**
 * Splits content without round-trip controls into the parts that become content elements.
 *
 * - Page breaks and horizontal rules are hard boundaries.
 * - The highest heading level present opens sections; each section is one part, and deeper
 *   headings inside it either form items (a FAQ, a feature list) or stay subheadings of its text.
 * - A table, quote or code block amid other content becomes a part of its own; a heading right
 *   before it stays with it.
 * - Content between the start and end of a round-trip bookmark stays together as one part and
 *   carries the element it came from.
 */
final readonly class Segmenter
{
    public function __construct(
        private PartAnalyzer $analyzer,
    ) {}

    /**
     * @param list<Block> $blocks
     * @param string $prefix Prefix of the part ids; the caller keeps it unique within one plan
     *
     * @return list<Part>
     */
    public function segment(array $blocks, string $prefix = 'p'): array
    {
        $parts = [];
        $number = 0;
        foreach ($this->runs($blocks) as $run) {
            if ($run['record'] !== null) {
                $shape = $this->analyzer->analyze($run['blocks']);
                if (!$shape->isEmpty()) {
                    $parts[] = new Part($prefix . (++$number), $run['blocks'], $shape, $run['record']);
                }
                continue;
            }
            foreach ($this->sections($run['blocks']) as $section) {
                foreach ($this->standalone($section) as $group) {
                    $shape = $this->analyzer->analyze($group);
                    if (!$shape->isEmpty()) {
                        $parts[] = new Part($prefix . (++$number), $group, $shape);
                    }
                }
            }
        }

        return $parts;
    }

    /**
     * Stretches between hard boundaries; a round-trip bookmark range is a stretch of its own.
     *
     * @param list<Block> $blocks
     *
     * @return list<array{blocks: list<Block>, record: array{0: string, 1: int}|null}>
     */
    private function runs(array $blocks): array
    {
        $runs = [];
        $current = [];
        $record = null;
        $recordName = '';
        foreach ($blocks as $block) {
            if ($block instanceof Bookmark && $block->record() !== null) {
                if ($block->start && $record === null) {
                    $runs[] = ['blocks' => $current, 'record' => null];
                    $current = [];
                    $record = $block->record();
                    $recordName = $block->name;
                } elseif (!$block->start && $block->name === $recordName) {
                    $runs[] = ['blocks' => $current, 'record' => $record];
                    $current = [];
                    $record = null;
                    $recordName = '';
                }
                continue;
            }
            if ($record === null && ($block instanceof PageBreak || $block instanceof HorizontalRule)) {
                $runs[] = ['blocks' => $current, 'record' => null];
                $current = [];
                continue;
            }
            if ($block instanceof Bookmark) {
                continue;
            }
            $current[] = $block;
        }
        $runs[] = ['blocks' => $current, 'record' => $record];

        return array_values(array_filter($runs, static fn(array $run): bool => $run['blocks'] !== []));
    }

    /**
     * @param list<Block> $blocks
     *
     * @return list<list<Block>>
     */
    private function sections(array $blocks): array
    {
        $levels = [];
        foreach ($blocks as $block) {
            if ($block instanceof Heading) {
                $levels[] = $block->level;
            }
        }
        if ($levels === []) {
            return [$blocks];
        }
        $sectionLevel = min($levels);

        $sections = [];
        $current = [];
        foreach ($blocks as $block) {
            if ($block instanceof Heading && $block->level === $sectionLevel && $current !== []) {
                $sections[] = $current;
                $current = [];
            }
            $current[] = $block;
        }
        if ($current !== []) {
            $sections[] = $current;
        }

        return $sections;
    }

    /**
     * Lifts tables, quotes and code blocks out of mixed content.
     *
     * @param list<Block> $section
     *
     * @return list<list<Block>>
     */
    private function standalone(array $section): array
    {
        $heading = ($section[0] ?? null) instanceof Heading ? $section[0] : null;
        $content = $heading === null ? $section : array_slice($section, 1);
        $standalone = array_filter($content, static fn(Block $block): bool => self::isStandalone($block));
        // Alone (with its heading), the block is simply the part's subject.
        if ($standalone === [] || count($content) === 1) {
            return [$section];
        }

        $groups = [];
        $current = $heading === null ? [] : [$heading];
        foreach ($content as $block) {
            if (!self::isStandalone($block)) {
                $current[] = $block;
                continue;
            }
            if ($current !== [] && !($current === [$heading] && $heading !== null)) {
                $groups[] = $current;
                $current = [];
            }
            $current[] = $block;
            $groups[] = $current;
            $current = [];
        }
        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    private static function isStandalone(Block $block): bool
    {
        return $block instanceof Table || $block instanceof Quote || $block instanceof CodeBlock;
    }
}

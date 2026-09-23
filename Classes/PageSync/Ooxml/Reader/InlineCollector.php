<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Ooxml\Reader;

use Webconsulting\DocxEditor\PageSync\Document\Inline;
use Webconsulting\DocxEditor\PageSync\Document\LineBreak;
use Webconsulting\DocxEditor\PageSync\Document\Link;
use Webconsulting\DocxEditor\PageSync\Document\Marks;
use Webconsulting\DocxEditor\PageSync\Document\Text;

/**
 * Collects the inlines of a paragraph while runs are read, including complex fields: the
 * instruction of a HYPERLINK field becomes the target of a link around the field's result,
 * and every other field (PAGE, DATE, REF …) contributes its current result as plain text.
 *
 * Adjacent runs with equal marks are merged — Word splits text into runs for revision ids and
 * spell checking, which says nothing about the content.
 *
 * @internal
 */
final class InlineCollector
{
    /** @var list<array{inlines: list<Inline>, instruction: string, inResult: bool}> */
    private array $frames = [['inlines' => [], 'instruction' => '', 'inResult' => true]];

    public function text(string $text, Marks $marks): void
    {
        if ($text === '' || !$this->inResult()) {
            return;
        }
        $this->add(new Text($text, $marks));
    }

    public function lineBreak(): void
    {
        if ($this->inResult()) {
            $this->add(new LineBreak());
        }
    }

    public function pageBreak(bool $rule = false): void
    {
        if ($this->inResult()) {
            $this->add(new BreakMarker($rule));
        }
    }

    public function add(Inline $inline): void
    {
        if (!$this->inResult()) {
            return;
        }
        $top = count($this->frames) - 1;
        $this->frames[$top]['inlines'][] = $inline;
    }

    /**
     * @param list<Inline> $inlines
     */
    public function addAll(array $inlines): void
    {
        foreach ($inlines as $inline) {
            $this->add($inline);
        }
    }

    public function instruction(string $text): void
    {
        $top = count($this->frames) - 1;
        if ($top > 0 && !$this->frames[$top]['inResult']) {
            $this->frames[$top]['instruction'] .= $text;
        }
    }

    public function beginField(): void
    {
        $this->frames[] = ['inlines' => [], 'instruction' => '', 'inResult' => false];
    }

    public function separateField(): void
    {
        $top = count($this->frames) - 1;
        if ($top > 0) {
            $this->frames[$top]['inResult'] = true;
        }
    }

    public function endField(): void
    {
        if (count($this->frames) < 2) {
            return;
        }
        $frame = array_pop($this->frames);
        $this->emitField($frame['instruction'], $frame['inlines']);
    }

    /**
     * A simple field (w:fldSimple) whose instruction and result are already known.
     *
     * @param list<Inline> $result
     */
    public function simpleField(string $instruction, array $result): void
    {
        $this->emitField($instruction, $result);
    }

    /**
     * @return list<Inline>
     */
    public function inlines(): array
    {
        // A field left open at the end of the paragraph still shows its result.
        while (count($this->frames) > 1) {
            $this->endField();
        }

        return self::mergeTexts($this->frames[0]['inlines']);
    }

    /**
     * @param list<Inline> $inlines
     *
     * @return list<Inline>
     */
    public static function mergeTexts(array $inlines): array
    {
        $merged = [];
        foreach ($inlines as $inline) {
            $last = $merged === [] ? null : $merged[count($merged) - 1];
            if ($inline instanceof Text && $last instanceof Text && $last->marks->equals($inline->marks)) {
                $merged[count($merged) - 1] = new Text($last->text . $inline->text, $last->marks);
                continue;
            }
            if ($inline instanceof Link) {
                $inline = new Link($inline->href, self::mergeTexts($inline->children), $inline->title);
            }
            $merged[] = $inline;
        }

        return $merged;
    }

    /**
     * @return array{href: string, title: string}|null
     */
    public static function parseHyperlinkInstruction(string $instruction): ?array
    {
        $instruction = trim($instruction);
        if (preg_match('/^HYPERLINK\b(.*)$/is', $instruction, $matches) !== 1) {
            return null;
        }
        $arguments = $matches[1];
        $anchor = '';
        $title = '';
        if (preg_match('/\\\\l\s+"([^"]*)"/i', $arguments, $anchorMatch) === 1) {
            $anchor = $anchorMatch[1];
            $arguments = str_replace($anchorMatch[0], '', $arguments);
        }
        if (preg_match('/\\\\o\s+"([^"]*)"/i', $arguments, $titleMatch) === 1) {
            $title = $titleMatch[1];
            $arguments = str_replace($titleMatch[0], '', $arguments);
        }
        $arguments = (string)preg_replace('/\\\\[a-z]\s*("[^"]*")?/i', '', $arguments);
        $target = '';
        if (preg_match('/"([^"]*)"/', $arguments, $targetMatch) === 1) {
            $target = $targetMatch[1];
        } else {
            $target = trim($arguments);
        }
        $href = $target . ($anchor !== '' ? '#' . $anchor : '');

        return $href === '' ? null : ['href' => $href, 'title' => $title];
    }

    private function inResult(): bool
    {
        return $this->frames[count($this->frames) - 1]['inResult'];
    }

    /**
     * @param list<Inline> $result
     */
    private function emitField(string $instruction, array $result): void
    {
        $link = self::parseHyperlinkInstruction($instruction);
        if ($link !== null && $result !== []) {
            $children = array_values(array_filter(
                $result,
                static fn(Inline $inline): bool => $inline instanceof Text || $inline instanceof LineBreak,
            ));
            $this->add(new Link($link['href'], $children, $link['title']));

            return;
        }
        $this->addAll($result);
    }
}

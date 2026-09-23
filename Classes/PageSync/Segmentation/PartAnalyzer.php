<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Segmentation;

use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Document\Bookmark;
use Webconsulting\DocxEditor\PageSync\Document\CodeBlock;
use Webconsulting\DocxEditor\PageSync\Document\ContentControl;
use Webconsulting\DocxEditor\PageSync\Document\Figure;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\HorizontalRule;
use Webconsulting\DocxEditor\PageSync\Document\Inline;
use Webconsulting\DocxEditor\PageSync\Document\InlineImage;
use Webconsulting\DocxEditor\PageSync\Document\Link;
use Webconsulting\DocxEditor\PageSync\Document\PageBreak;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\ParagraphRole;
use Webconsulting\DocxEditor\PageSync\Document\PlainText;
use Webconsulting\DocxEditor\PageSync\Document\Quote;
use Webconsulting\DocxEditor\PageSync\Document\Table;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Ooxml\Reader\BlockAssembler;

/**
 * Works out what one part consists of: its heading and subtitle, running text, pictures, a
 * table, quote or code block standing on its own, repeated items, and a closing call to action.
 */
final class PartAnalyzer
{
    private const int MAX_QUESTION_LENGTH = 200;
    private const int MAX_BOLD_TITLE_LENGTH = 120;
    private const int MAX_CALL_TO_ACTION_LINKS = 3;
    private const float MIN_LEAD_IN_SHARE = 0.3;
    private const int MAX_ANSWER_BLOCKS = 4;
    private const int MAX_INTRO_BLOCKS = 2;

    /**
     * @param list<Block> $blocks
     */
    public function analyze(array $blocks): PartShape
    {
        $blocks = self::flatten($blocks);

        $heading = null;
        $first = $blocks[0] ?? null;
        if ($first instanceof Heading) {
            $heading = $first;
            array_shift($blocks);
        }
        $subtitle = null;
        $first = $blocks[0] ?? null;
        if ($first instanceof Paragraph && $first->role === ParagraphRole::Subtitle) {
            $subtitle = $first;
            array_shift($blocks);
        }

        // Pictures leave the text flow: figures and pictures inside paragraphs alike.
        $images = [];
        $content = [];
        foreach ($blocks as $block) {
            if ($block instanceof Figure) {
                $images[] = $block;
                continue;
            }
            if ($block instanceof Paragraph) {
                $inline = array_values(array_filter($block->inlines, static fn(Inline $inline): bool => $inline instanceof InlineImage));
                foreach ($inline as $picture) {
                    if ($picture instanceof InlineImage) {
                        $images[] = new Figure($picture->image);
                    }
                }
                if ($inline !== []) {
                    $remaining = BlockAssembler::trimInlines(array_values(array_filter($block->inlines, static fn(Inline $inline): bool => !$inline instanceof InlineImage)));
                    if (trim(PlainText::ofInlines($remaining)) === '') {
                        continue;
                    }
                    $block = new Paragraph($remaining, $block->role);
                }
            }
            $content[] = $block;
        }

        $links = self::takeCallToAction($content);

        // A table, quote or code block that is the whole content is the part's subject.
        $table = null;
        $quote = null;
        $code = null;
        if (count($content) === 1) {
            $only = $content[0];
            if ($only instanceof Table) {
                $table = $only;
                $content = [];
            } elseif ($only instanceof Quote) {
                $quote = $only;
                $content = [];
            } elseif ($only instanceof CodeBlock) {
                $code = $only;
                $content = [];
            }
        }

        $items = [];
        $detected = $this->detectItems($content, $heading === null ? 0 : $heading->level);
        if ($detected !== null) {
            [$content, $items] = $detected;
        }

        return new PartShape(
            heading: $heading,
            subtitle: $subtitle,
            body: $content,
            images: $images,
            table: $table,
            quote: $quote,
            code: $code,
            items: $items,
            links: $links,
        );
    }

    /**
     * Repeated units: subheadings each followed by content, or questions (and bold lead-ins)
     * each followed by their answer.
     *
     * @param list<Block> $content
     *
     * @return array{0: list<Block>, 1: list<PartItem>}|null The remaining intro and the items
     */
    public function detectItems(array $content, int $sectionLevel): ?array
    {
        $byHeadings = $this->itemsByHeadings($content, $sectionLevel);
        if ($byHeadings !== null) {
            return $byHeadings;
        }

        return $this->itemsByLeadIns($content);
    }

    /**
     * @param list<Block> $content
     *
     * @return array{0: list<Block>, 1: list<PartItem>}|null
     */
    private function itemsByHeadings(array $content, int $sectionLevel): ?array
    {
        $levels = [];
        foreach ($content as $block) {
            if ($block instanceof Heading && $block->level > $sectionLevel) {
                $levels[] = $block->level;
            }
        }
        if ($levels === []) {
            return null;
        }
        $itemLevel = min($levels);
        $positions = [];
        foreach ($content as $index => $block) {
            if ($block instanceof Heading && $block->level === $itemLevel) {
                $positions[] = $index;
            }
        }
        if (count($positions) < 2) {
            return null;
        }

        $intro = array_slice($content, 0, $positions[0]);
        $items = [];
        $withContent = 0;
        foreach ($positions as $i => $position) {
            $end = $positions[$i + 1] ?? count($content);
            $heading = $content[$position];
            if (!$heading instanceof Heading) {
                continue;
            }
            $item = $this->item($heading->inlines, array_slice($content, $position + 1, $end - $position - 1));
            if ($item->body !== [] || $item->image !== null) {
                $withContent++;
            }
            $items[] = $item;
        }
        // Headings over nothing are an outline, not items.
        if ($withContent * 2 < count($items)) {
            return null;
        }

        return [$intro, $items];
    }

    /**
     * @param list<Block> $content
     *
     * @return array{0: list<Block>, 1: list<PartItem>}|null
     */
    private function itemsByLeadIns(array $content): ?array
    {
        $titleIndexes = [];
        foreach ($content as $index => $block) {
            if ($block instanceof Paragraph && self::isLeadIn($block)) {
                $titleIndexes[] = $index;
            }
        }
        // A question-and-answer list is dense with lead-ins; two questions in a long text are prose.
        if (count($titleIndexes) < 2 || count($titleIndexes) < self::MIN_LEAD_IN_SHARE * count($content)
            || $titleIndexes[0] > self::MAX_INTRO_BLOCKS
        ) {
            return null;
        }
        $items = [];
        foreach ($titleIndexes as $i => $position) {
            $end = $titleIndexes[$i + 1] ?? count($content);
            $body = array_slice($content, $position + 1, $end - $position - 1);
            if ($body === [] || count($body) > self::MAX_ANSWER_BLOCKS) {
                return null;
            }
            $title = $content[$position];
            if (!$title instanceof Paragraph) {
                return null;
            }
            $items[] = $this->item(self::withoutMarks($title->inlines), $body);
        }

        return [array_slice($content, 0, $titleIndexes[0]), $items];
    }

    /**
     * @param list<Inline> $title
     * @param list<Block> $blocks
     */
    private function item(array $title, array $blocks): PartItem
    {
        $image = null;
        $body = [];
        foreach ($blocks as $block) {
            if ($block instanceof Figure && $image === null) {
                $image = $block;
                continue;
            }
            $body[] = $block;
        }
        $links = self::takeCallToAction($body, 1);

        return new PartItem($title, $body, $image, $links[0] ?? null);
    }

    /**
     * A paragraph that introduces an item: a question, or a short line set entirely in bold.
     */
    private static function isLeadIn(Paragraph $paragraph): bool
    {
        $text = trim(PlainText::ofInlines($paragraph->inlines));
        if ($text === '') {
            return false;
        }
        if (str_ends_with($text, '?') && mb_strlen($text) <= self::MAX_QUESTION_LENGTH) {
            return true;
        }
        if (mb_strlen($text) > self::MAX_BOLD_TITLE_LENGTH) {
            return false;
        }
        foreach ($paragraph->inlines as $inline) {
            if ($inline instanceof Text && trim($inline->text) !== '' && !$inline->marks->bold) {
                return false;
            }
            if (!$inline instanceof Text) {
                return false;
            }
        }

        return true;
    }

    /**
     * Paragraphs at the end that consist of nothing but links: the part's call to action.
     *
     * @param list<Block> $blocks
     *
     * @return list<Link>
     */
    private static function takeCallToAction(array &$blocks, int $max = self::MAX_CALL_TO_ACTION_LINKS): array
    {
        $links = [];
        while ($blocks !== []) {
            $last = $blocks[count($blocks) - 1];
            if (!$last instanceof Paragraph) {
                break;
            }
            $paragraphLinks = [];
            foreach ($last->inlines as $inline) {
                if ($inline instanceof Link) {
                    $paragraphLinks[] = $inline;
                    continue;
                }
                if ($inline instanceof Text && trim($inline->text, " \t\u{00A0}|·•–-") === '') {
                    continue;
                }
                $paragraphLinks = [];
                break;
            }
            if ($paragraphLinks === [] || count($links) + count($paragraphLinks) > $max) {
                break;
            }
            array_pop($blocks);
            $links = [...$paragraphLinks, ...$links];
        }

        return $links;
    }

    /**
     * @param list<Inline> $inlines
     *
     * @return list<Inline>
     */
    private static function withoutMarks(array $inlines): array
    {
        return array_map(
            static fn(Inline $inline): Inline => $inline instanceof Text ? new Text($inline->text) : $inline,
            $inlines,
        );
    }

    /**
     * Unwraps content controls and drops markers that carry no content.
     *
     * @param list<Block> $blocks
     *
     * @return list<Block>
     */
    public static function flatten(array $blocks): array
    {
        $flat = [];
        foreach ($blocks as $block) {
            if ($block instanceof ContentControl) {
                array_push($flat, ...self::flatten($block->blocks));
                continue;
            }
            if ($block instanceof Bookmark || $block instanceof PageBreak || $block instanceof HorizontalRule) {
                continue;
            }
            $flat[] = $block;
        }

        return $flat;
    }
}

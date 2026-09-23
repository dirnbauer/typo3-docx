<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching;

use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Document\Figure;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\Link;
use Webconsulting\DocxEditor\PageSync\Document\ListBlock;
use Webconsulting\DocxEditor\PageSync\Document\ListItem;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\ParagraphRole;
use Webconsulting\DocxEditor\PageSync\Document\PlainText;
use Webconsulting\DocxEditor\PageSync\Document\Text;
use Webconsulting\DocxEditor\PageSync\Matching\Value\BlocksValue;
use Webconsulting\DocxEditor\PageSync\Matching\Value\CollectionValue;
use Webconsulting\DocxEditor\PageSync\Matching\Value\ImagesValue;
use Webconsulting\DocxEditor\PageSync\Matching\Value\LinkValue;
use Webconsulting\DocxEditor\PageSync\Matching\Value\TableValue;
use Webconsulting\DocxEditor\PageSync\Matching\Value\TextValue;
use Webconsulting\DocxEditor\PageSync\Schema\ElementShape;
use Webconsulting\DocxEditor\PageSync\Schema\FieldInfo;
use Webconsulting\DocxEditor\PageSync\Schema\FieldKind;
use Webconsulting\DocxEditor\PageSync\Schema\FieldRole;
use Webconsulting\DocxEditor\PageSync\Segmentation\PartItem;
use Webconsulting\DocxEditor\PageSync\Segmentation\PartShape;

/**
 * Fills a part into a record type by field role and measures how well it fits.
 *
 * Every piece of the part is a unit with a weight (a long text weighs more than a heading, a
 * table more than a link). A unit placed in a field made for it earns full credit; placed in a
 * more general field (a table into rich text, a subtitle into the body) it earns part of it;
 * with no field to take it, nothing. Coverage is the weighted share of credit. Required fields
 * left empty and fields that stay unused count against the type, so the tightest fit wins.
 */
final class FieldPlacer
{
    public const float REQUIRED_PENALTY = 0.25;
    /** An unused field says the type was made for more than the part has. */
    public const float UNUSED_PENALTY = 0.02;
    /** Pictures the type expects and the part does not have. */
    public const float UNUSED_IMAGE_PENALTY = 0.03;
    /** A type built around a collection that stays empty is a poor fit. */
    public const float UNUSED_COLLECTION_PENALTY = 0.1;
    public const float MAX_UNUSED_PENALTY = 0.2;

    private const int MAX_IMAGES_WEIGHED = 4;
    private const int MAX_ITEMS_WEIGHED = 8;

    public function place(PartShape $part, ElementShape $shape): Placement
    {
        $state = new PlacementBuilder($shape);

        if ($part->heading !== null) {
            $this->placeHeading($part, $state);
        }
        if ($part->subtitle !== null) {
            $this->placeSubtitle($part->subtitle, $state);
        }
        if ($part->items === [] && $part->bodyIsOnlyList() && $this->placeListAsItems($part, $state)) {
            // A plain list became the items of a collection.
        } elseif ($part->body !== []) {
            $this->placeBody($part, $state);
        }
        if ($part->table !== null) {
            $this->placeTable($part, $state);
        }
        if ($part->quote !== null) {
            $this->placeQuote($part, $state);
        }
        if ($part->code !== null) {
            $this->placeCode($part, $state);
        }
        if ($part->items !== []) {
            $this->placeItems($part->items, $state);
        }
        if ($part->images !== []) {
            $this->placeImages($part->images, $part->items === [], $state);
        }
        if ($part->links !== []) {
            $this->placeLinks($part->links, $state);
        }

        return $state->finish();
    }

    private function placeHeading(PartShape $part, PlacementBuilder $state): void
    {
        $heading = $part->heading;
        if ($heading === null) {
            return;
        }
        $text = $part->headingText();
        $field = $state->take(FieldRole::Heading);
        if ($field !== null) {
            $state->assign($field, new TextValue($text), 'heading', 1.0, 1.0);
            if ($field->table === 'tt_content' && $field->name === 'header' && $heading->level !== 2 && $state->shape->type !== '') {
                $state->setting('header_layout', min(5, $heading->level));
            }

            return;
        }
        // No heading field: the heading opens the rich text instead.
        if ($state->hasRichBody()) {
            $state->prependRich($heading, 'heading', 1.0, 0.6);

            return;
        }
        $state->lose('heading', 1.0);
    }

    private function placeSubtitle(Paragraph $subtitle, PlacementBuilder $state): void
    {
        $field = $state->take(FieldRole::Subheading);
        if ($field !== null) {
            $state->assign($field, new TextValue(PlainText::ofBlock($subtitle)), 'subtitle', 0.5, 1.0);

            return;
        }
        if ($state->hasRichBody()) {
            $state->appendRich(new Paragraph($subtitle->inlines, ParagraphRole::Body), 'subtitle', 0.5, 0.8);

            return;
        }
        $state->lose('subtitle', 0.5);
    }

    private function placeBody(PartShape $part, PlacementBuilder $state): void
    {
        $weight = 1.0 + min(2.0, $part->bodyWordCount() / 150);

        if ($part->bodyIsOnlyList() && $part->body[0] instanceof ListBlock) {
            $bullets = $state->take(FieldRole::BulletData);
            if ($bullets !== null) {
                $state->assign($bullets, new BlocksValue($part->body), 'list', $weight, 1.0);
                $state->setting('bullets_type', $part->body[0]->isOrdered() ? 1 : 0);

                return;
            }
        }
        if ($state->hasRichBody()) {
            foreach ($part->body as $block) {
                $state->appendRich($block, 'text', 0.0, 1.0);
            }
            $state->creditRich('text', $weight, 1.0);

            return;
        }
        $plain = $state->peek(FieldRole::Body);
        if ($plain !== null && $plain->kind !== FieldKind::RichText) {
            $state->take(FieldRole::Body);
            $credit = $part->bodyIsRich() ? 0.7 : 1.0;
            if ($plain->kind === FieldKind::Input && count($part->body) > 1) {
                $credit = 0.5;
            }
            $state->assign($plain, new BlocksValue($part->body), 'text', $weight, $credit);

            return;
        }
        $state->lose('text', $weight);
    }

    private function placeTable(PartShape $part, PlacementBuilder $state): void
    {
        $table = $part->table;
        if ($table === null) {
            return;
        }
        $field = $state->take(FieldRole::TableData);
        if ($field !== null) {
            $state->assign($field, new TableValue($table), 'table', 2.0, 1.0);
            $state->setting('table_header_position', $table->hasHeaderRow() ? 1 : 0);
            $state->setting('cols', 0);
            $caption = $state->shape->field('table_caption');
            if ($table->caption !== '' && $caption !== null) {
                $state->assign($caption, new TextValue($table->caption), 'caption', 0.0, 1.0);
            }

            return;
        }
        if ($state->hasRichBody()) {
            $state->appendRich($table, 'table', 2.0, 0.85);

            return;
        }
        $state->lose('table', 2.0);
    }

    private function placeQuote(PartShape $part, PlacementBuilder $state): void
    {
        $quote = $part->quote;
        if ($quote === null) {
            return;
        }
        $field = $state->take(FieldRole::Quote);
        if ($field !== null) {
            $state->assign($field, new BlocksValue($quote->paragraphs), 'quote', 1.5, 1.0);
            if ($quote->citation !== []) {
                $citation = trim(PlainText::ofInlines($quote->citation));
                $attribution = $state->take(FieldRole::Attribution);
                if ($attribution !== null) {
                    // "Name, Role" splits over the attribution and position fields where both exist.
                    $position = $state->peek(FieldRole::Position);
                    if ($position !== null && str_contains($citation, ',')) {
                        [$name, $role] = array_map('trim', explode(',', $citation, 2));
                        $state->take(FieldRole::Position);
                        $state->assign($attribution, new TextValue($name), 'attribution', 0.3, 1.0);
                        $state->assign($position, new TextValue($role), 'attribution', 0.0, 1.0);
                    } else {
                        $state->assign($attribution, new TextValue($citation), 'attribution', 0.3, 1.0);
                    }
                } else {
                    $state->lose('attribution', 0.3);
                }
            }

            return;
        }
        if ($state->hasRichBody()) {
            $state->appendRich($quote, 'quote', 1.8, 0.8);

            return;
        }
        $plain = $state->take(FieldRole::Body);
        if ($plain !== null) {
            $state->assign($plain, new BlocksValue([$quote]), 'quote', 1.8, 0.6);

            return;
        }
        $state->lose('quote', 1.8);
    }

    private function placeCode(PartShape $part, PlacementBuilder $state): void
    {
        $code = $part->code;
        if ($code === null) {
            return;
        }
        $field = $state->take(FieldRole::Code);
        if ($field !== null) {
            $state->assign($field, new TextValue($code->code), 'code', 1.5, 1.0);

            return;
        }
        if ($state->hasRichBody()) {
            $state->appendRich($code, 'code', 1.5, 0.8);

            return;
        }
        $plain = $state->take(FieldRole::Body);
        if ($plain !== null && $plain->kind === FieldKind::Text) {
            $state->assign($plain, new TextValue($code->code), 'code', 1.5, 0.7);

            return;
        }
        $state->lose('code', 1.5);
    }

    /**
     * @param list<PartItem> $items
     */
    private function placeItems(array $items, PlacementBuilder $state): void
    {
        $weight = 0.8 * min(self::MAX_ITEMS_WEIGHED, count($items));

        $best = null;
        foreach ($state->shape->collections() as $collection) {
            if ($state->isUsed($collection)) {
                continue;
            }
            $child = $state->shape->child($collection);
            if ($child === null) {
                continue;
            }
            $mappings = [];
            $total = 0.0;
            $placed = 0;
            foreach ($items as $index => $item) {
                if ($collection->maxItems > 0 && $index >= $collection->maxItems) {
                    break;
                }
                $placement = $this->placeItem($item, $child);
                $mappings[] = $placement->mapping;
                $total += $placement->score();
                $placed++;
            }
            if ($placed === 0) {
                continue;
            }
            $credit = ($total / $placed) * ($placed / count($items));
            if ($collection->minItems > count($items)) {
                $credit *= 0.8;
            }
            if ($best === null || $credit > $best['credit']) {
                $best = ['field' => $collection, 'credit' => $credit, 'mappings' => $mappings, 'child' => $child];
            }
        }

        if ($best !== null && $best['credit'] >= 0.3) {
            $state->markUsed($best['field']);
            $state->assign($best['field'], new CollectionValue($best['child']->table, $best['mappings']), 'items', $weight, $best['credit']);

            return;
        }
        if ($state->hasRichBody()) {
            foreach ($items as $item) {
                $state->appendRich(new Heading(3, $item->title), 'items', 0.0, 1.0);
                foreach ($item->body as $block) {
                    $state->appendRich($block, 'items', 0.0, 1.0);
                }
            }
            $state->creditRich('items', $weight, 0.7);

            return;
        }
        $state->lose('items', $weight);
    }

    /**
     * One item into the child record type of a collection.
     */
    private function placeItem(PartItem $item, ElementShape $child): Placement
    {
        $state = new PlacementBuilder($child);
        $titleWeight = $item->titleText() === '' ? 0.0 : 1.0;
        $valueField = $item->isFigure() ? $state->peek(FieldRole::Value) : null;
        if ($valueField !== null) {
            $state->take(FieldRole::Value);
            $state->assign($valueField, new TextValue($item->titleText()), 'value', $titleWeight, 1.0);
            $label = $state->peek(FieldRole::Label) ?? $state->peek(FieldRole::Heading);
            if ($label !== null && $item->body !== [] && mb_strlen($item->bodyText()) <= 120) {
                $state->markUsed($label);
                $state->assign($label, new TextValue(PlainText::normalizeWhitespace($item->bodyText())), 'label', 1.0, 1.0);

                return $state->finish();
            }
        } elseif ($titleWeight > 0.0) {
            $field = $state->take(FieldRole::Heading) ?? $state->take(FieldRole::Label);
            if ($field !== null) {
                // A figure ("98 %") as the title of an item is a statistic in the wrong type.
                $state->assign($field, new TextValue($item->titleText()), 'title', $titleWeight, $item->isFigure() ? 0.7 : 1.0);
            } elseif ($state->hasRichBody()) {
                $state->prependRich(new Heading(3, $item->title), 'title', $titleWeight, 0.6);
            } else {
                $state->lose('title', $titleWeight);
            }
        }

        if ($item->body !== []) {
            $words = PlainText::wordCount($item->bodyText());
            $weight = 1.0 + min(1.0, $words / 150);
            if ($state->hasRichBody()) {
                foreach ($item->body as $block) {
                    $state->appendRich($block, 'text', 0.0, 1.0);
                }
                $state->creditRich('text', $weight, 1.0);
            } else {
                $plain = $state->take(FieldRole::Body) ?? $state->take(FieldRole::Label);
                if ($plain !== null) {
                    $state->assign($plain, new BlocksValue($item->body), 'text', $weight, $plain->kind === FieldKind::Input ? 0.6 : 0.9);
                } else {
                    $state->lose('text', $weight);
                }
            }
        }
        if ($item->image !== null) {
            $this->placeImages([$item->image], false, $state);
        }
        if ($item->link !== null) {
            $this->placeLinks([$item->link], $state);
        }

        return $state->finish();
    }

    /**
     * @param list<Figure> $figures
     */
    private function placeImages(array $figures, bool $mayUseCollection, PlacementBuilder $state): void
    {
        $remaining = $figures;
        $weightPerImage = 0.8;
        foreach ($state->shape->imageFields() as $field) {
            if ($remaining === [] || $state->isUsed($field)) {
                continue;
            }
            $take = $field->maxItems > 0 ? array_slice($remaining, 0, $field->maxItems) : $remaining;
            $remaining = array_slice($remaining, count($take));
            $state->markUsed($field);
            $state->assign($field, new ImagesValue($take), 'images', $weightPerImage * min(self::MAX_IMAGES_WEIGHED, count($take)), 1.0);
        }
        if ($remaining === []) {
            return;
        }
        // A gallery: one collection item per picture.
        if ($mayUseCollection) {
            foreach ($state->shape->collections() as $collection) {
                $child = $state->shape->child($collection);
                if ($child === null || $state->isUsed($collection) || $child->imageFields() === []) {
                    continue;
                }
                $items = [];
                $fit = 0.0;
                foreach ($remaining as $figure) {
                    $placement = $this->placeItem(new PartItem($figure->caption, [], $figure), $child);
                    $items[] = $placement->mapping;
                    $fit += $placement->score();
                }
                $state->markUsed($collection);
                $state->assign($collection, new CollectionValue($child->table, $items), 'images', $weightPerImage * min(self::MAX_IMAGES_WEIGHED, count($remaining)), 0.9 * $fit / count($remaining));

                return;
            }
        }
        $state->lose(count($remaining) . ' images', $weightPerImage * min(self::MAX_IMAGES_WEIGHED, count($remaining)));
    }

    /**
     * @param list<Link> $links
     */
    private function placeLinks(array $links, PlacementBuilder $state): void
    {
        foreach ($links as $link) {
            $target = $state->take(FieldRole::Link);
            if ($target !== null) {
                $state->assign($target, new LinkValue($link->href), 'link', 0.3, 1.0);
                $label = $state->take(FieldRole::LinkLabel);
                if ($label !== null) {
                    $state->assign($label, new TextValue(PlainText::ofInlines($link->children)), 'link', 0.0, 1.0);
                }
                continue;
            }
            if ($state->hasRichBody()) {
                $state->appendRich(new Paragraph([$link]), 'link', 0.3, 0.9);
                continue;
            }
            $state->lose('link', 0.3);
        }
    }

    /**
     * A bulleted list with no bullet-list type available becomes the items of a collection
     * whose records take a single line (feature checklists, highlights).
     */
    private function placeListAsItems(PartShape $part, PlacementBuilder $state): bool
    {
        if ($state->peek(FieldRole::BulletData) !== null || !($part->body[0] ?? null) instanceof ListBlock) {
            return false;
        }
        $list = $part->body[0];
        foreach ($state->shape->collections() as $collection) {
            $child = $state->shape->child($collection);
            if ($child === null || $state->isUsed($collection)) {
                continue;
            }
            $titleField = $child->firstWithRole(FieldRole::Heading) ?? $child->firstWithRole(FieldRole::Label);
            if ($titleField === null) {
                continue;
            }
            // Only a list of short lines, all on one level, reads as items.
            $short = array_all($list->items, static fn(ListItem $item): bool => $item->level === 0 && mb_strlen(PlainText::ofInlines($item->inlines)) <= 160);
            if (!$short) {
                return false;
            }
            $items = array_map(
                fn(ListItem $item): FieldMapping => $this->placeItem(new PartItem([new Text(trim(PlainText::ofInlines($item->inlines)))]), $child)->mapping,
                $list->items,
            );
            $state->markUsed($collection);
            $state->assign($collection, new CollectionValue($child->table, $items), 'list', 1.0 + min(2.0, $part->bodyWordCount() / 150), 0.9);

            return true;
        }

        return false;
    }

    /**
     * @param list<Block> $blocks
     */
    public static function containsRichStructure(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (!$block instanceof Paragraph) {
                return true;
            }
        }

        return false;
    }

    public static function describeField(FieldInfo $field): string
    {
        return $field->name;
    }
}

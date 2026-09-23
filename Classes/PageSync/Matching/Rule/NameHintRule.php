<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching\Rule;

use Webconsulting\DocxEditor\PageSync\Matching\ContentTypeCandidate;
use Webconsulting\DocxEditor\PageSync\Matching\FieldPlacer;
use Webconsulting\DocxEditor\PageSync\Matching\MappingRuleInterface;
use Webconsulting\DocxEditor\PageSync\Matching\Proposal;
use Webconsulting\DocxEditor\PageSync\Schema\ElementShape;
use Webconsulting\DocxEditor\PageSync\Schema\FieldRoleClassifier;
use Webconsulting\DocxEditor\PageSync\Segmentation\PartItem;
use Webconsulting\DocxEditor\PageSync\Segmentation\PartShape;

/**
 * Reads what a type is for from its name: items that are questions suit an "accordion" or a
 * "faq", items titled with figures suit "stats", a quote suits a "testimonial", pictures on
 * their own suit a "gallery", a top heading with a subtitle and a button suits a "hero".
 *
 * Structure decides first; the hint only tips the balance between types that fit equally well.
 * Semantic calls beyond names are Jev's job.
 */
final readonly class NameHintRule implements MappingRuleInterface
{
    public const string IDENTIFIER = 'name';

    private const float HINT = 0.03;

    public function __construct(
        private FieldPlacer $placer,
    ) {}

    #[\Override]
    public function propose(PartShape $part, ContentTypeCandidate $candidate): ?Proposal
    {
        $words = self::words($candidate->shape);
        $hinted = match (true) {
            $part->items !== [] && self::most($part->items, static fn(PartItem $item): bool => $item->isQuestion())
                => array_intersect($words, ['faq', 'faqs', 'accordion', 'question', 'questions', 'answer', 'qa']) !== [],
            $part->items !== [] && self::most($part->items, static fn(PartItem $item): bool => $item->isFigure())
                => array_intersect($words, ['stat', 'stats', 'statistic', 'statistics', 'kpi', 'kpis', 'number', 'numbers', 'counter', 'figures', 'metrics']) !== [],
            $part->quote !== null => array_intersect($words, ['quote', 'quotes', 'testimonial', 'testimonials', 'citation']) !== [],
            $part->images !== [] && $part->body === [] && $part->items === []
                => array_intersect($words, ['gallery', 'slider', 'carousel', 'images', 'photos']) !== [],
            $part->heading !== null && $part->heading->level === 1 && $part->subtitle !== null && $part->links !== []
                => array_intersect($words, ['hero', 'stage', 'banner', 'jumbotron']) !== [],
            default => false,
        };
        if (!$hinted || !$candidate->shape->hasContentFields()) {
            return null;
        }
        $placement = $this->placer->place($part, $candidate->shape);
        if ($placement->mapping->isEmpty()) {
            return null;
        }

        return new Proposal($candidate->cType, $placement->score(), $placement, self::IDENTIFIER, self::HINT);
    }

    /**
     * @return list<string>
     */
    private static function words(ElementShape $shape): array
    {
        $words = [...FieldRoleClassifier::words($shape->type), ...FieldRoleClassifier::words($shape->label)];
        foreach ($shape->children as $child) {
            array_push($words, ...FieldRoleClassifier::words($child->table));
            foreach ($child->fields as $field) {
                $words[] = strtolower($field->name);
            }
        }

        return array_values(array_unique($words));
    }

    /**
     * @param list<PartItem> $items
     * @param callable(PartItem): bool $test
     */
    private static function most(array $items, callable $test): bool
    {
        return count(array_filter($items, $test)) * 2 > count($items);
    }
}

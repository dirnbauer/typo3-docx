<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Fixtures\PageSync;

use Webconsulting\DocxEditor\PageSync\Matching\ContentTypeCandidate;
use Webconsulting\DocxEditor\PageSync\Schema\ElementShape;
use Webconsulting\DocxEditor\PageSync\Schema\FieldInfo;
use Webconsulting\DocxEditor\PageSync\Schema\FieldKind;
use Webconsulting\DocxEditor\PageSync\Schema\FieldRoleClassifier;

/**
 * Element shapes as TYPO3's Schema API reports them in the lab (TYPO3 14.3, desiderio Content
 * Blocks), dumped read-only from the lab on 2026-09-23 and reduced to the content fields.
 * Roles come from the real classifier, so these fixtures test it on real field names too.
 */
final class LabShapes
{
    /**
     * Field name => kind, or [kind, required, maxItems, childTable, childFields] for files and collections.
     */
    private const array TYPES = [
        'header' => ['Header', ['header' => 'input']],
        'text' => ['Regular Text Element', ['header' => 'input', 'bodytext' => 'rte', 'tx_themecamino_link' => 'link', 'tx_themecamino_link_label' => 'input']],
        'textmedia' => ['Text & Media', ['header' => 'input', 'bodytext' => 'rte', 'tx_themecamino_link' => 'link', 'tx_themecamino_link_label' => 'input', 'assets' => ['file', false, 0]]],
        'textpic' => ['Text & Images', ['header' => 'input', 'bodytext' => 'rte', 'tx_themecamino_link' => 'link', 'tx_themecamino_link_label' => 'input', 'image' => ['file', false, 0]]],
        'image' => ['Images Only', ['header' => 'input', 'image' => ['file', false, 0]]],
        'table' => ['Table', ['header' => 'input', 'bodytext' => 'text', 'table_caption' => 'input']],
        'bullets' => ['Bullet List', ['header' => 'input', 'bodytext' => 'text']],
        'uploads' => ['File Links', ['header' => 'input', 'media' => ['file', false, 0]]],
        'html' => ['Plain HTML', ['header' => 'input', 'bodytext' => 'text']],
        'desiderio_accordion' => ['Accordion', ['header' => 'input', 'desiderio_accordion_items' => ['inline', false, 0, 'accordion_items', ['title' => ['text', true], 'content' => 'rte']]]],
        'desiderio_pricingfaq' => ['Pricing FAQ', ['header' => 'input', 'eyebrow' => 'text', 'subheadline' => 'text', 'desiderio_pricingfaq_question_items' => ['inline', false, 0, 'desiderio_qa_item', ['question' => ['text', true], 'answer' => ['rte', true]]]]],
        'desiderio_quote' => ['Quote', ['header' => 'input', 'quote_text' => ['text', true], 'author' => 'text', 'role' => 'text']],
        'desiderio_stats' => ['Stats', ['header' => 'input', 'eyebrow' => 'text', 'subheadline' => 'text', 'desiderio_stats_stats' => ['inline', false, 0, 'stats_stats', ['value' => ['text', true], 'label' => ['text', true], 'description' => 'rte']]]],
        'desiderio_featuregrid3' => ['Feature Grid', ['header' => 'input', 'subheadline' => 'text', 'desiderio_featuregrid3_items' => ['inline', false, 0, 'desiderio_icon_card_link', ['title' => ['text', true], 'description' => 'rte', 'link' => 'link']]]],
        'desiderio_gallery' => ['Gallery', ['header' => 'input', 'subheadline' => 'text', 'desiderio_gallery_items' => ['inline', false, 0, 'gallery_items', ['image' => ['file', false, 1], 'title' => 'text', 'description' => 'text', 'link' => 'link']]]],
        'desiderio_herominimal' => ['Hero Minimal', ['header' => 'input', 'subheadline' => 'text', 'button_text' => 'text', 'button_link' => 'link']],
        'desiderio_card' => ['Card', ['header' => 'input', 'card_image' => ['file', false, 1], 'description' => 'rte', 'badge_text' => 'text', 'link' => 'link', 'button_text' => 'text', 'button_link' => 'link']],
        'desiderio_howtosteps' => ['How-to Steps', ['header' => 'input', 'description' => 'rte', 'desiderio_howtosteps_items' => ['inline', false, 0, 'how_to_steps_items', ['title' => ['text', true], 'content' => 'rte', 'image' => ['file', false, 1]]]]],
    ];

    /**
     * @return array<string, ContentTypeCandidate>
     */
    public static function candidates(string ...$types): array
    {
        $types = $types === [] ? array_keys(self::TYPES) : $types;
        $candidates = [];
        foreach ($types as $type) {
            $candidates[$type] = new ContentTypeCandidate($type, self::shape($type));
        }

        return $candidates;
    }

    public static function shape(string $type): ElementShape
    {
        [$label, $fields] = self::TYPES[$type];

        return self::build('tt_content', $type, $label, $fields);
    }

    /**
     * @param array<string, mixed> $definition
     */
    private static function build(string $table, string $type, string $label, array $definition): ElementShape
    {
        $classifier = new FieldRoleClassifier();
        $fields = [];
        $children = [];
        foreach ($definition as $name => $spec) {
            $spec = is_array($spec) ? $spec : [$spec];
            $kind = match ($spec[0]) {
                'input' => FieldKind::Input,
                'text' => FieldKind::Text,
                'rte' => FieldKind::RichText,
                'file' => FieldKind::File,
                'link' => FieldKind::Link,
                'inline' => FieldKind::Collection,
                default => FieldKind::Other,
            };
            $childTable = is_string($spec[3] ?? null) ? $spec[3] : '';
            $allowed = $kind === FieldKind::File ? ($name === 'media' ? [] : ['common-image-types']) : [];
            $fields[] = new FieldInfo(
                table: $table,
                name: (string)$name,
                label: ucfirst(str_replace('_', ' ', (string)$name)),
                kind: $kind,
                role: $classifier->classify($table, $type, (string)$name, ucfirst(str_replace('_', ' ', (string)$name)), $kind),
                required: (bool)($spec[1] ?? false),
                maxItems: (int)($spec[2] ?? 0),
                allowedFileExtensions: $allowed,
                childTable: $childTable,
            );
            if ($kind === FieldKind::Collection && is_array($spec[4] ?? null)) {
                $children[(string)$name] = self::build($childTable, '', $childTable, $spec[4]);
            }
        }

        return new ElementShape($table, $type, $label, '', '', '', $classifier->resolveInContext($fields), $children);
    }
}

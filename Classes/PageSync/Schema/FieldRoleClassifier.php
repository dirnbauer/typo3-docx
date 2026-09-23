<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Schema;

/**
 * Reads a field's role from its name and (English) label.
 *
 * Names are split into words ("desiderio_quote_text" → desiderio, quote, text; "buttonLabel" →
 * button, label), and the first matching rule wins. The rules are written for the vocabulary
 * content elements actually use — core fields, Content Blocks, the lab's desiderio and astryx
 * elements — and fall back on the storage kind: rich text is body text, a single line is a label.
 */
final class FieldRoleClassifier
{
    /**
     * Word sets checked in order. A field matches a rule when one of its words is in the set.
     */
    private const array RULES = [
        [FieldRole::LinkLabel, ['button_text', 'button_label', 'link_text', 'link_label', 'cta_text', 'cta_label', 'linktext', 'buttontext', 'buttonlabel', 'linklabel']],
        [FieldRole::Subheading, ['subheader', 'subheadline', 'subheading', 'subtitle', 'eyebrow', 'tagline', 'kicker', 'overline', 'lead', 'teaser_title', 'pretitle', 'overtitle']],
        [FieldRole::Heading, ['header', 'headline', 'heading', 'title', 'question', 'name', 'term', 'topic']],
        [FieldRole::Quote, ['quote_text', 'quote_content', 'testimonial_text', 'quote', 'quotation', 'testimonial', 'statement', 'citation', 'blockquote']],
        [FieldRole::Attribution, ['author', 'person', 'attribution', 'speaker', 'cite', 'source', 'quote_source', 'signature']],
        [FieldRole::Position, ['role', 'position', 'job', 'jobtitle', 'job_title', 'company', 'organisation', 'organization', 'occupation', 'department']],
        [FieldRole::Value, ['value', 'number', 'stat', 'statistic', 'figure', 'amount', 'price', 'metric', 'count', 'percentage', 'kpi']],
        [FieldRole::Code, ['code', 'snippet', 'source_code', 'sourcecode']],
        [FieldRole::Body, ['bodytext', 'body', 'text', 'content', 'description', 'answer', 'copy', 'teaser', 'summary', 'intro', 'introduction', 'details', 'excerpt', 'message', 'note', 'notes', 'paragraph', 'abstract', 'bio', 'biography', 'info', 'caption']],
        [FieldRole::Label, ['label', 'badge', 'badge_text', 'tag', 'category', 'unit', 'suffix', 'prefix']],
    ];

    private const array IMAGE_WORDS = ['image', 'images', 'img', 'picture', 'pictures', 'photo', 'photos', 'logo', 'logos', 'avatar', 'portrait', 'thumbnail', 'background', 'illustration', 'icon', 'assets', 'media', 'gallery', 'visual', 'cover'];

    public function classify(string $table, string $type, string $name, string $label, FieldKind $kind): FieldRole
    {
        if ($table === 'tt_content') {
            // Core fields whose meaning depends on the type.
            if ($name === 'bodytext' && $type === 'table') {
                return FieldRole::TableData;
            }
            if ($name === 'bodytext' && $type === 'bullets') {
                return FieldRole::BulletData;
            }
            if ($name === 'table_caption') {
                return FieldRole::Label;
            }
            if ($name === 'media' && $type === 'uploads') {
                return FieldRole::Media;
            }
        }

        return match ($kind) {
            FieldKind::Collection => FieldRole::Collection,
            FieldKind::Link => FieldRole::Link,
            FieldKind::File => $this->classifyFile($name, $label),
            FieldKind::Other => FieldRole::None,
            default => $this->classifyText($name, $label, $kind),
        };
    }

    /**
     * Roles that depend on the neighbours: "name" is the person being quoted when the type has a
     * quote field, and a second heading-like field is a subheading.
     *
     * @param list<FieldInfo> $fields
     *
     * @return list<FieldInfo>
     */
    public function resolveInContext(array $fields): array
    {
        $hasQuote = array_any($fields, static fn(FieldInfo $field): bool => $field->role === FieldRole::Quote);
        $headingSeen = false;
        $result = [];
        foreach ($fields as $field) {
            $role = $field->role;
            if ($hasQuote && $role === FieldRole::Heading && in_array($field->name, ['name', 'author_name', 'person_name'], true)) {
                $role = FieldRole::Attribution;
            } elseif ($role === FieldRole::Heading) {
                if ($headingSeen) {
                    $role = $field->kind === FieldKind::RichText ? FieldRole::Body : FieldRole::Subheading;
                }
                $headingSeen = true;
            }
            $result[] = $role === $field->role ? $field : new FieldInfo(
                table: $field->table,
                name: $field->name,
                label: $field->label,
                kind: $field->kind,
                role: $role,
                required: $field->required,
                maxItems: $field->maxItems,
                minItems: $field->minItems,
                allowedFileExtensions: $field->allowedFileExtensions,
                childTable: $field->childTable,
                excludedFromTranslation: $field->excludedFromTranslation,
                accessControlled: $field->accessControlled,
            );
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public static function words(string $name): array
    {
        $name = (string)preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
        $words = preg_split('/[^a-z0-9]+/', strtolower($name), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($words) ? $words : [];
    }

    private function classifyText(string $name, string $label, FieldKind $kind): FieldRole
    {
        $nameWords = self::words($name);
        $joined = implode('_', $nameWords);
        // The field name is the stronger signal; the label decides only when the name says nothing.
        foreach ([$nameWords, self::words($label)] as $words) {
            $role = $this->firstRule($words, $joined);
            if ($role !== null) {
                if ($role === FieldRole::Heading && $kind === FieldKind::RichText) {
                    return FieldRole::Body;
                }

                return $role;
            }
            $joined = '';
        }

        return match ($kind) {
            FieldKind::RichText, FieldKind::Text => FieldRole::Body,
            default => FieldRole::Label,
        };
    }

    /**
     * @param list<string> $words
     */
    private function firstRule(array $words, string $joined): ?FieldRole
    {
        if ($words === []) {
            return null;
        }
        // Compound names first ("button_text" is a link label, not body text).
        foreach (self::RULES as [$role, $vocabulary]) {
            foreach ($vocabulary as $entry) {
                if (str_contains($entry, '_') && $joined !== '' && str_contains('_' . $joined . '_', '_' . $entry . '_')) {
                    return $role;
                }
            }
        }
        // Then the last word, which carries the meaning in "card_title" or "desiderio_quote_text" …
        $last = $words[count($words) - 1];
        foreach (self::RULES as [$role, $vocabulary]) {
            if (in_array($last, $vocabulary, true)) {
                return $role;
            }
        }
        // … and finally any word.
        foreach (self::RULES as [$role, $vocabulary]) {
            if (array_intersect($words, $vocabulary) !== []) {
                return $role;
            }
        }

        return null;
    }

    private function classifyFile(string $name, string $label): FieldRole
    {
        $words = [...self::words($name), ...self::words($label)];
        if (array_intersect($words, ['video', 'videos', 'audio', 'download', 'downloads', 'document', 'documents', 'pdf', 'attachment', 'attachments', 'file', 'files']) !== []
            && array_intersect($words, self::IMAGE_WORDS) === []
        ) {
            return FieldRole::Media;
        }

        return FieldRole::Image;
    }
}

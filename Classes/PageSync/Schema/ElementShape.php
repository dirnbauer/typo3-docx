<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Schema;

/**
 * The editable shape of a record type: a content element type (CType) or the child table of a
 * collection. Built from TYPO3's Schema API, so it covers core types and every Content Blocks
 * element alike.
 */
final readonly class ElementShape
{
    /**
     * @param list<FieldInfo> $fields Content fields in the order the backend form shows them
     * @param array<string, ElementShape> $children Child shapes of collection fields, keyed by field name
     */
    public function __construct(
        public string $table,
        public string $type,
        /** The type's label, usually an LLL reference. */
        public string $label,
        public string $description = '',
        public string $group = '',
        public string $icon = '',
        public array $fields = [],
        public array $children = [],
    ) {}

    /**
     * The same shape with only the fields the filter keeps — how fields a backend user may not
     * edit are taken out of consideration.
     *
     * @param callable(FieldInfo): bool $keep
     */
    public function filtered(callable $keep): self
    {
        $fields = array_values(array_filter($this->fields, $keep));
        $children = [];
        foreach ($this->children as $name => $child) {
            if (array_any($fields, static fn(FieldInfo $field): bool => $field->name === $name)) {
                $children[$name] = $child->filtered($keep);
            }
        }

        return new self($this->table, $this->type, $this->label, $this->description, $this->group, $this->icon, $fields, $children);
    }

    public function field(string $name): ?FieldInfo
    {
        return array_find($this->fields, static fn(FieldInfo $field): bool => $field->name === $name);
    }

    /**
     * @return list<FieldInfo>
     */
    public function fieldsWithRole(FieldRole $role): array
    {
        return array_values(array_filter($this->fields, static fn(FieldInfo $field): bool => $field->role === $role));
    }

    public function firstWithRole(FieldRole $role): ?FieldInfo
    {
        return array_find($this->fields, static fn(FieldInfo $field): bool => $field->role === $role);
    }

    /**
     * Fields that take running text, rich text first.
     *
     * @return list<FieldInfo>
     */
    public function bodyFields(): array
    {
        $body = $this->fieldsWithRole(FieldRole::Body);
        usort($body, static fn(FieldInfo $a, FieldInfo $b): int => ($b->kind === FieldKind::RichText) <=> ($a->kind === FieldKind::RichText));

        return $body;
    }

    /**
     * @return list<FieldInfo>
     */
    public function imageFields(): array
    {
        return array_values(array_filter($this->fields, static fn(FieldInfo $field): bool => $field->role === FieldRole::Image && $field->acceptsImages()));
    }

    /**
     * @return list<FieldInfo>
     */
    public function collections(): array
    {
        $children = $this->children;

        return array_values(array_filter(
            $this->fields,
            static fn(FieldInfo $field): bool => $field->kind === FieldKind::Collection && isset($children[$field->name]),
        ));
    }

    public function child(FieldInfo $collection): ?ElementShape
    {
        return $this->children[$collection->name] ?? null;
    }

    /**
     * @return list<FieldInfo>
     */
    public function requiredFields(): array
    {
        return array_values(array_filter($this->fields, static fn(FieldInfo $field): bool => $field->required && $field->role->isContent()));
    }

    /**
     * Fields that hold content Word can edit.
     *
     * @return list<FieldInfo>
     */
    public function contentFields(): array
    {
        return array_values(array_filter($this->fields, static fn(FieldInfo $field): bool => $field->role->isContent()));
    }

    public function hasContentFields(): bool
    {
        return $this->contentFields() !== [];
    }

    /**
     * The field list in the words the matcher and Jev reason with, e.g.
     * "header (heading), bodytext (rich text), items: [title (heading), content (rich text)]".
     */
    public function summary(): string
    {
        $parts = [];
        foreach ($this->contentFields() as $field) {
            $kind = match ($field->kind) {
                FieldKind::RichText => 'rich text',
                FieldKind::Text => 'text',
                FieldKind::Input => 'line',
                FieldKind::File => $field->acceptsImages() ? ($field->isSingle() ? 'image' : 'images') : 'files',
                FieldKind::Collection => 'items',
                default => $field->kind->value,
            };
            $entry = $field->name . ' (' . $field->role->value . ', ' . $kind . ($field->required ? ', required' : '') . ')';
            $child = $this->children[$field->name] ?? null;
            if ($child !== null) {
                $entry = $field->name . ': [' . $child->summary() . ']';
            }
            $parts[] = $entry;
        }

        return implode(', ', $parts);
    }
}

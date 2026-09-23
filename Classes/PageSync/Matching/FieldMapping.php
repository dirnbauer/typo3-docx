<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching;

/**
 * How one part fills the fields of one record type: an assignment per field, plus plain values
 * for settings the type needs (the delimiter of a table, the kind of a bullet list).
 */
final readonly class FieldMapping
{
    /**
     * @param list<FieldAssignment> $assignments
     * @param array<string, int|string> $settings Field name => value, e.g. bullets_type => 1
     */
    public function __construct(
        public array $assignments = [],
        public array $settings = [],
    ) {}

    public function assignment(string $fieldName): ?FieldAssignment
    {
        return array_find($this->assignments, static fn(FieldAssignment $assignment): bool => $assignment->field->name === $fieldName);
    }

    public function isEmpty(): bool
    {
        return $this->assignments === [];
    }
}

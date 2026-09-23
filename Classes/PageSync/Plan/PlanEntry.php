<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Plan;

use Webconsulting\DocxEditor\PageSync\Matching\FieldMapping;
use Webconsulting\DocxEditor\PageSync\Matching\PartMatch;

/**
 * One record in the plan: a content element, a collection item, or the page.
 */
final readonly class PlanEntry
{
    /**
     * @param list<FieldChange> $fields
     * @param list<PlanEntry> $children
     * @param list<PlanMessage> $messages
     */
    public function __construct(
        public string $id,
        public EntryAction $action,
        public string $table,
        /** 0 for a record that does not exist yet. */
        public int $uid,
        /** The CType of a content element; the chosen one for a new element. */
        public string $type = '',
        public int $colPos = 0,
        /** Where a new element goes: after this element, 0 for the top of the column. */
        public int $afterUid = 0,
        /** A line the plan shows for the entry (its heading or first words). */
        public string $title = '',
        public array $fields = [],
        public array $children = [],
        public ?PartMatch $match = null,
        public array $messages = [],
        /** The collection field of a parent a child belongs to. */
        public string $parentField = '',
        /** The field values of a new collection item. */
        public ?FieldMapping $mapping = null,
        /** How the entry was recognised when its content control was missing: "bookmark" or "fingerprint". */
        public string $recognisedBy = '',
    ) {}

    public function requiresConfirmation(): bool
    {
        return $this->action === EntryAction::Delete;
    }

    public function field(string $name): ?FieldChange
    {
        return array_find($this->fields, static fn(FieldChange $change): bool => $change->field->name === $name);
    }

    /**
     * Whether anything in the entry or its children would be written.
     */
    public function hasWrites(): bool
    {
        if ($this->action->writes()) {
            return true;
        }

        return array_any($this->children, static fn(PlanEntry $child): bool => $child->hasWrites());
    }

    public function withAction(EntryAction $action): self
    {
        return new self(
            $this->id,
            $action,
            $this->table,
            $this->uid,
            $this->type,
            $this->colPos,
            $this->afterUid,
            $this->title,
            $this->fields,
            $this->children,
            $this->match,
            $this->messages,
            $this->parentField,
            $this->mapping,
            $this->recognisedBy,
        );
    }
}

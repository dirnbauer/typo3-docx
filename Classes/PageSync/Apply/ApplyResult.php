<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Apply;

/**
 * What applying a plan did.
 */
final readonly class ApplyResult
{
    /**
     * @param array<string, int> $created Entry id => uid of the new record
     * @param list<int> $updated
     * @param list<int> $deleted
     * @param list<int> $moved
     * @param array<string, int> $translated Entry id => uid of the new translation
     * @param list<string> $errors What the DataHandler refused
     */
    public function __construct(
        public array $created = [],
        public array $updated = [],
        public array $deleted = [],
        public array $moved = [],
        public array $translated = [],
        public array $errors = [],
        public int $workspaceId = 0,
        /** The page written to — for an import as a new page, the page created. */
        public int $pageUid = 0,
    ) {}

    public function succeeded(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'deleted' => $this->deleted,
            'moved' => $this->moved,
            'translated' => $this->translated,
            'errors' => $this->errors,
            'workspace' => $this->workspaceId,
            'page' => $this->pageUid,
        ];
    }
}

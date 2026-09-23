<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Plan;

/**
 * Everything importing a Word document into a page would do, for the editor to review before
 * anything is written.
 */
final readonly class SyncPlan
{
    /**
     * @param list<PlanEntry> $entries Content elements in document order
     * @param list<PlanMove> $moves
     * @param list<PlanMessage> $messages
     */
    public function __construct(
        public int $pageUid,
        public int $languageId,
        public int $workspaceId,
        public int $userId,
        public string $documentHash,
        public bool $manifestFound,
        public bool $manifestTrusted,
        public array $entries,
        public ?PlanEntry $page = null,
        public array $moves = [],
        public array $messages = [],
        /** The page is created by the import (import as new page). */
        public bool $newPage = false,
        /** Parent page of a new page. */
        public int $parentPageUid = 0,
    ) {}

    public function entry(string $id): ?PlanEntry
    {
        foreach ([...($this->page === null ? [] : [$this->page]), ...$this->entries] as $entry) {
            $found = self::find($entry, $id);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * How many entries (and collection items) per action.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = array_fill_keys(array_map(static fn(EntryAction $action): string => $action->value, EntryAction::cases()), 0);
        foreach ($this->entries as $entry) {
            self::count($entry, $counts);
        }
        if ($this->page !== null && $this->page->action !== EntryAction::Unchanged) {
            $counts[$this->page->action->value]++;
        }

        return $counts;
    }

    public function hasWrites(): bool
    {
        return $this->moves !== []
            || ($this->page?->hasWrites() ?? false)
            || array_any($this->entries, static fn(PlanEntry $entry): bool => $entry->hasWrites());
    }

    /**
     * A digest of what the plan would do. Rebuilt from the same document, a plan with another
     * digest means the page changed in TYPO3 since the preview.
     */
    public function digest(): string
    {
        $lines = [$this->pageUid . ':' . $this->languageId . ':' . $this->workspaceId . ':' . $this->documentHash];
        foreach ([...($this->page === null ? [] : [$this->page]), ...$this->entries] as $entry) {
            self::digestLines($entry, $lines);
        }
        foreach ($this->moves as $move) {
            $lines[] = 'move:' . $move->uid . ':' . $move->colPos . ':' . $move->afterUid;
        }

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * @param array<string, int> $counts
     */
    private static function count(PlanEntry $entry, array &$counts): void
    {
        $counts[$entry->action->value]++;
        foreach ($entry->children as $child) {
            self::count($child, $counts);
        }
    }

    /**
     * @param list<string> $lines
     */
    private static function digestLines(PlanEntry $entry, array &$lines): void
    {
        $line = $entry->id . ':' . $entry->action->value . ':' . $entry->table . ':' . $entry->uid . ':' . $entry->type;
        foreach ($entry->fields as $field) {
            $line .= '|' . $field->field->name . '=' . $field->status->value . '/' . hash('xxh128', $field->wordPreview . "\0" . $field->typo3Preview);
        }
        $lines[] = $line;
        foreach ($entry->children as $child) {
            self::digestLines($child, $lines);
        }
    }

    private static function find(PlanEntry $entry, string $id): ?PlanEntry
    {
        if ($entry->id === $id) {
            return $entry;
        }
        foreach ($entry->children as $child) {
            $found = self::find($child, $id);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Plan;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Manifest\RoundTripManifest;
use Webconsulting\DocxEditor\PageSync\Segmentation\Part;

/**
 * What the plan builder keeps track of while it walks one document.
 *
 * @internal
 */
final class PlanState
{
    /** @var list<PlanEntry|array{part: Part, colPos: int, afterUid: int}> Entries and new parts, in document order */
    public array $items = [];

    /** @var array<int, array<string, mixed>> Content elements of the language, by uid */
    public array $current = [];

    /** @var array<int, array<string, mixed>> Untranslated default-language elements, by uid (connected translations) */
    public array $sources = [];

    /** @var array<int, true> */
    public array $seen = [];

    /** @var array<int, list<int>> colPos => element uids in document order */
    public array $documentOrder = [];

    /** @var array<int, int> colPos => the last element placed there */
    public array $lastUid = [];

    /** @var list<Block> */
    public array $gap = [];

    public int $gapColPos = 0;
    public int $gapAfterUid = 0;

    public ?PlanEntry $page = null;

    /** @var list<PlanMessage> */
    public array $messages = [];

    private int $counter = 0;

    public function __construct(
        public readonly int $pageUid,
        public readonly int $languageId,
        public readonly int $workspaceId,
        public readonly BackendUserAuthentication $user,
        public readonly ?RoundTripManifest $manifest,
        public readonly bool $connectedTranslation,
    ) {}

    public function nextId(string $prefix = 'e'): string
    {
        return $prefix . (++$this->counter);
    }

    public function trusted(): bool
    {
        return $this->manifest !== null && $this->manifest->trusted;
    }

    /**
     * Collects content without a round-trip control; the builder flushes the gap before it
     * changes columns.
     */
    public function addToGap(Block $block, int $colPos): void
    {
        if ($this->gap === []) {
            $this->gapColPos = $colPos;
            $this->gapAfterUid = $this->lastUid[$colPos] ?? 0;
        }
        $this->gap[] = $block;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function record(int $uid): ?array
    {
        return $this->current[$uid] ?? $this->sources[$uid] ?? null;
    }
}

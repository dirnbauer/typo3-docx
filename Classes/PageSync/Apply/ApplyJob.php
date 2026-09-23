<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Apply;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Webconsulting\DocxEditor\PageSync\Matching\ContentTypeCandidate;
use Webconsulting\DocxEditor\PageSync\Plan\PlanDecisions;
use Webconsulting\DocxEditor\PageSync\Plan\PlanEntry;
use Webconsulting\DocxEditor\PageSync\Plan\SyncPlan;

/**
 * The data map, command maps and outcome of applying one plan.
 *
 * @internal
 */
final class ApplyJob
{
    /** @var array<string, array<int|string, array<string, mixed>>> */
    public array $data = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $commandsRun = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $referenceDeletions = [];

    /** @var list<array{entry: PlanEntry, newId: string, row: array<string, int|string>}> */
    public array $creates = [];

    /** @var array<string, string> Entry id => NEW id */
    public array $newIds = [];

    /** @var array<string, int> Entry id => uid of the source that is localized */
    public array $translations = [];

    /** @var array<int, array<string, ContentTypeCandidate>> colPos => allowed types */
    public array $candidates = [];

    /** @var list<string> */
    public array $errors = [];

    /** @var list<int> */
    public array $updated = [];

    /** @var list<int> */
    public array $deleted = [];

    /** @var list<int> */
    public array $moved = [];

    private int $counter = 0;

    public function __construct(
        public readonly SyncPlan $plan,
        public readonly PlanDecisions $decisions,
        public readonly BackendUserAuthentication $user,
        public readonly string $imageFolder,
    ) {}

    public function newId(): string
    {
        return 'NEW' . bin2hex(random_bytes(4)) . (++$this->counter);
    }
}

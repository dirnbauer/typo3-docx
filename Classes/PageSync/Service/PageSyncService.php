<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Service;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Webconsulting\DocxEditor\PageSync\Apply\ApplyResult;
use Webconsulting\DocxEditor\PageSync\Apply\PlanApplier;
use Webconsulting\DocxEditor\PageSync\Configuration\PageSyncSettings;
use Webconsulting\DocxEditor\PageSync\Document\DocxDocument;
use Webconsulting\DocxEditor\PageSync\Exception\PageSyncException;
use Webconsulting\DocxEditor\PageSync\Export\ExportResult;
use Webconsulting\DocxEditor\PageSync\Export\PageExporter;
use Webconsulting\DocxEditor\PageSync\Ooxml\ArchiveLimits;
use Webconsulting\DocxEditor\PageSync\Ooxml\DocumentReader;
use Webconsulting\DocxEditor\PageSync\Plan\NewPagePlanBuilder;
use Webconsulting\DocxEditor\PageSync\Plan\PageSplit;
use Webconsulting\DocxEditor\PageSync\Plan\PlanBuilder;
use Webconsulting\DocxEditor\PageSync\Plan\PlanDecisions;
use Webconsulting\DocxEditor\PageSync\Plan\PlanStore;
use Webconsulting\DocxEditor\PageSync\Plan\SyncPlan;

/**
 * The page round trip in three steps, as the backend and the CLI use it: export a page, preview
 * what importing a document would do, apply a reviewed preview.
 *
 * A preview stores the document and the digest of its plan. Applying rebuilds the plan from the
 * stored document: if TYPO3 changed in between, the digest differs and nothing is written — the
 * editor reviews again. New elements get the types the preview showed (or the editor chose),
 * whatever Jev would answer the second time.
 */
#[Autoconfigure(public: true)]
final readonly class PageSyncService
{
    public const string MODE_UPDATE = 'update';
    public const string MODE_NEW_PAGE = 'newPage';

    public function __construct(
        private DocumentReader $reader,
        private PageExporter $exporter,
        private PlanBuilder $planBuilder,
        private NewPagePlanBuilder $newPagePlanBuilder,
        private PlanApplier $applier,
        private PlanStore $store,
        private PageSyncSettings $settings,
    ) {}

    public function export(int $pageUid, int $languageId, BackendUserAuthentication $user): ExportResult
    {
        return $this->exporter->export($pageUid, $languageId, $user);
    }

    public function read(string $binary): DocxDocument
    {
        return $this->reader->read($binary, ArchiveLimits::fromMegabytes($this->settings->maxUploadMegabytes()));
    }

    public function preview(string $binary, int $pageUid, int $languageId, BackendUserAuthentication $user, bool $useJev = true): PreviewResult
    {
        $plan = $this->planBuilder->build($this->read($binary), sha1($binary), $pageUid, $languageId, $user, $useJev);

        return new PreviewResult($this->remember($binary, [$plan], [
            'mode' => self::MODE_UPDATE,
            'page' => $pageUid,
            'language' => $languageId,
        ], $user), [$plan]);
    }

    public function previewNewPages(string $binary, int $parentUid, PageSplit $split, BackendUserAuthentication $user, bool $useJev = true): PreviewResult
    {
        $plans = $this->newPagePlanBuilder->build($this->read($binary), sha1($binary), $parentUid, $user, $split, $useJev);

        return new PreviewResult($this->remember($binary, $plans, [
            'mode' => self::MODE_NEW_PAGE,
            'parent' => $parentUid,
            'split' => $split->value,
        ], $user), $plans);
    }

    /**
     * @return list<ApplyResult>
     */
    public function apply(string $id, PlanDecisions $decisions, BackendUserAuthentication $user): array
    {
        $stored = $this->store->load($id, (int)($user->user['uid'] ?? 0));
        $meta = $stored['meta'];
        if ((int)($meta['workspace'] ?? -1) !== (int)$user->workspace) {
            throw new PageSyncException('error.workspaceChanged', 409);
        }
        $document = $this->read($stored['binary']);
        $hash = sha1($stored['binary']);
        $plans = ($meta['mode'] ?? '') === self::MODE_NEW_PAGE
            ? $this->newPagePlanBuilder->build($document, $hash, (int)($meta['parent'] ?? 0), $user, PageSplit::tryFrom((string)($meta['split'] ?? '')) ?? PageSplit::None, false)
            : [$this->planBuilder->build($document, $hash, (int)($meta['page'] ?? 0), (int)($meta['language'] ?? 0), $user, false)];

        $digests = is_array($meta['digests'] ?? null) ? $meta['digests'] : [];
        if (array_map(static fn(SyncPlan $plan): string => $plan->digest(), $plans) !== $digests) {
            throw new PageSyncException('error.planOutdated', 409);
        }
        $types = [];
        foreach (is_array($meta['types'] ?? null) ? $meta['types'] : [] as $entryId => $type) {
            if (is_string($entryId) && is_string($type)) {
                $types[$entryId] = $type;
            }
        }
        $decisions = $decisions->withDefaultTypes($types);

        $results = ($meta['mode'] ?? '') === self::MODE_NEW_PAGE
            ? $this->applier->applyNewPages($plans, $decisions, $user)
            : [$this->applier->apply($plans[0], $decisions, $user)];
        $this->store->delete($id);

        return $results;
    }

    public function discard(string $id): void
    {
        $this->store->delete($id);
    }

    /**
     * @param list<SyncPlan> $plans
     * @param array<string, int|string> $meta
     */
    private function remember(string $binary, array $plans, array $meta, BackendUserAuthentication $user): string
    {
        $types = [];
        foreach ($plans as $plan) {
            $types += $plan->chosenTypes();
        }

        return $this->store->store($binary, $meta + [
            'workspace' => (int)$user->workspace,
            'digests' => array_map(static fn(SyncPlan $plan): string => $plan->digest(), $plans),
            'types' => $types,
        ], (int)($user->user['uid'] ?? 0));
    }
}

<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Service;

use Webconsulting\DocxEditor\PageSync\Plan\SyncPlan;

/**
 * A reviewed-but-not-applied import: the stored document's id and the plans to show.
 */
final readonly class PreviewResult
{
    /**
     * @param list<SyncPlan> $plans One plan, or one per new page
     */
    public function __construct(
        public string $id,
        public array $plans,
    ) {}
}

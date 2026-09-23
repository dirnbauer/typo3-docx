<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Export;

use Webconsulting\DocxEditor\PageSync\Manifest\RoundTripManifest;

final readonly class ExportResult
{
    /**
     * @param list<int> $skipped Elements that are not part of the document (no column on the page, inside a container)
     */
    public function __construct(
        public string $binary,
        public string $fileName,
        public RoundTripManifest $manifest,
        public int $elementCount,
        public array $skipped = [],
    ) {}
}

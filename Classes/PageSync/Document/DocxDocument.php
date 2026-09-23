<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

use Webconsulting\DocxEditor\PageSync\Manifest\RoundTripManifest;
use Webconsulting\DocxEditor\PageSync\Ooxml\ReadWarning;

/**
 * A Word document as the page round trip sees it: the body as blocks, the round-trip
 * manifest when the document came from a TYPO3 export, and the custom document properties.
 */
final readonly class DocxDocument
{
    /**
     * @param list<Block> $blocks
     * @param array<string, string> $customProperties
     * @param list<ReadWarning> $warnings
     */
    public function __construct(
        public array $blocks,
        public ?RoundTripManifest $manifest = null,
        public array $customProperties = [],
        public string $title = '',
        public array $warnings = [],
    ) {}
}

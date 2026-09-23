<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Ooxml;

use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Manifest\RoundTripManifest;

final readonly class WriteRequest
{
    /**
     * @param list<Block> $blocks
     * @param array<string, string> $customProperties Shown in Word under File > Info > Properties
     */
    public function __construct(
        public array $blocks,
        public ?RoundTripManifest $manifest = null,
        public string $title = '',
        public string $creator = '',
        /** BCP 47 tag for Word's proofing tools, e.g. "de-AT". */
        public string $languageTag = '',
        public array $customProperties = [],
        /** An EXT: or project path to a .dotx/.docx whose styles to use; "" for the built-in template. */
        public string $templatePath = '',
    ) {}
}

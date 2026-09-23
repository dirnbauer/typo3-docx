<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Manifest;

/**
 * One exported field and the hash of the value it had in the export — the base of the
 * three-way comparison between Word, TYPO3 and the export. Collections and read-only fields
 * have no hash; they are listed only when their control's tag is a short reference, or when the
 * document shows a label in place of their stored value (a link field shows the page title, the
 * stored value is the typolink).
 */
final readonly class ManifestField
{
    public function __construct(
        public string $name,
        public string $hash,
        /** The heading level the field was written with, for header fields; 0 otherwise. */
        public int $level = 0,
        /** Position of the field in the manifest, for the short "typo3:#record:field" tags. */
        public int $reference = 0,
        /** The stored value of a read-only field the document shows as a label. */
        public string $value = '',
    ) {}
}

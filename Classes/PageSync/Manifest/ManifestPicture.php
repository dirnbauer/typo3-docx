<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Manifest;

/**
 * One exported picture: the file reference and file it came from, and the hash of the bytes the
 * document holds — a copy scaled to what Word shows, not the file itself.
 *
 * A picture that comes back with exactly these bytes is the same file reference; any other
 * picture is one the editor put into the document.
 */
final readonly class ManifestPicture
{
    public function __construct(
        /** sys_file_reference uid; the picture's wp:docPr name is "typo3:sys_file_reference:<uid>". */
        public int $reference,
        /** sys_file uid. */
        public int $file,
        /** sha1 of the file in TYPO3 at export time. */
        public string $sha1,
        /** sha1 of the bytes embedded in the document. */
        public string $embedded,
    ) {}

    public function tag(): string
    {
        return ControlTag::record('sys_file_reference', $this->reference)->toString();
    }
}

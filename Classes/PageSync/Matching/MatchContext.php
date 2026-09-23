<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching;

/**
 * Where the new elements go, and what Jev may know about the document.
 */
final readonly class MatchContext
{
    public function __construct(
        public int $pageUid,
        public int $colPos = 0,
        public int $languageId = 0,
        /** BCP 47 tag of the content language, e.g. "de-AT" — the language the parts are written in. */
        public string $languageTag = '',
        public string $documentTitle = '',
        /** Ask Jev about ambiguous parts, if it is installed and configured. */
        public bool $useJev = true,
    ) {}
}

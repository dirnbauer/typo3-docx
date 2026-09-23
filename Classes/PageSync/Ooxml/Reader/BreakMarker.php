<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Ooxml\Reader;

use Webconsulting\DocxEditor\PageSync\Document\Inline;

/**
 * A page break (w:br w:type="page") or a VML horizontal line found inside a paragraph. The
 * paragraph reader splits the paragraph there; the marker never leaves the reader.
 *
 * @internal
 */
final readonly class BreakMarker implements Inline
{
    public function __construct(
        public bool $rule = false,
    ) {}
}

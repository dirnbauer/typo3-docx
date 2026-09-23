<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

/**
 * A horizontal line: an empty paragraph with a bottom border in Word, <hr> in HTML. A
 * boundary between content elements, like a page break.
 */
final readonly class HorizontalRule implements Block {}

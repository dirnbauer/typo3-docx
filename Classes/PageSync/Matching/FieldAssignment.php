<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching;

use Webconsulting\DocxEditor\PageSync\Matching\Value\FieldValue;
use Webconsulting\DocxEditor\PageSync\Schema\FieldInfo;

final readonly class FieldAssignment
{
    public function __construct(
        public FieldInfo $field,
        public FieldValue $value,
        /** What of the part went here: "heading", "text", "images", "items" … */
        public string $source,
    ) {}
}

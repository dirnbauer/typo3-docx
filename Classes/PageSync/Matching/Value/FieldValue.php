<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching\Value;

/**
 * What a new element's field receives from the document: text, rich text, pictures, a table,
 * list items, a link, or the child records of a collection.
 */
interface FieldValue
{
    /**
     * A one-line account for the plan preview ("3 paragraphs", "2 images").
     */
    public function preview(): string;
}

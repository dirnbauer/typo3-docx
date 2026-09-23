<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

/**
 * A block of the format-neutral document model the Word reader produces, the Word writer
 * consumes and the HTML converters translate from and to: a heading, a paragraph, a list, a
 * table, a figure, a quote, a code block, a break or a content control around other blocks.
 */
interface Block {}

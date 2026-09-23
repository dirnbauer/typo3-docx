<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

/**
 * A page or section break. Segmentation never lets a content element span one.
 */
final readonly class PageBreak implements Block {}

<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Segmentation;

use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Document\Figure;
use Webconsulting\DocxEditor\PageSync\Document\Inline;
use Webconsulting\DocxEditor\PageSync\Document\Link;
use Webconsulting\DocxEditor\PageSync\Document\PlainText;

/**
 * One repeated unit of a part — a question and its answer, a feature and its description, a
 * step — which becomes one record of a collection.
 */
final readonly class PartItem
{
    /**
     * @param list<Inline> $title
     * @param list<Block> $body
     */
    public function __construct(
        public array $title,
        public array $body = [],
        public ?Figure $image = null,
        public ?Link $link = null,
    ) {}

    public function titleText(): string
    {
        return trim(PlainText::ofInlines($this->title));
    }

    public function bodyText(): string
    {
        return PlainText::ofBlocks($this->body);
    }

    public function isQuestion(): bool
    {
        return str_ends_with($this->titleText(), '?');
    }

    /**
     * A title that is a figure ("98 %", "2.4k", "€ 12") — the value of a statistic.
     */
    public function isFigure(): bool
    {
        return preg_match('/^[^\p{L}]{0,3}[0-9][0-9.,\s]*[%+kKmMx×]?[^\p{L}]{0,3}$/u', $this->titleText()) === 1;
    }
}

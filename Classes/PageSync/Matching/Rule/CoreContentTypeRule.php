<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching\Rule;

use Webconsulting\DocxEditor\PageSync\Matching\ContentTypeCandidate;
use Webconsulting\DocxEditor\PageSync\Matching\FieldPlacer;
use Webconsulting\DocxEditor\PageSync\Matching\MappingRuleInterface;
use Webconsulting\DocxEditor\PageSync\Matching\Proposal;
use Webconsulting\DocxEditor\PageSync\Segmentation\PartShape;

/**
 * What TYPO3's own content types are for, on top of their structural fit:
 *
 * - header     a heading on its own (with its subtitle)
 * - text       headed or unheaded running text — the safe default
 * - textmedia  running text with pictures (textpic, its older sibling, ranks just below)
 * - image      pictures, with at most a heading
 * - table      a table, with at most a heading
 * - bullets    a list and nothing else
 *
 * The bonus is small on purpose: it breaks ties in favour of the generic type, it does not
 * override a site's element that fits better.
 */
final readonly class CoreContentTypeRule implements MappingRuleInterface
{
    public const string IDENTIFIER = 'core';

    private const float BONUS = 0.04;

    public function __construct(
        private FieldPlacer $placer,
    ) {}

    #[\Override]
    public function propose(PartShape $part, ContentTypeCandidate $candidate): ?Proposal
    {
        if (!$candidate->isCore()) {
            return null;
        }
        $placement = $this->placer->place($part, $candidate->shape);
        if ($placement->mapping->isEmpty()) {
            return null;
        }
        $score = $placement->score();
        $onlyHeading = $part->heading !== null && $part->body === [] && $part->images === [] && $part->table === null
            && $part->quote === null && $part->code === null && $part->items === [] && $part->links === [];
        $imagesOnly = $part->images !== [] && $part->body === [] && $part->table === null && $part->quote === null
            && $part->code === null && $part->items === [];

        $plainText = ($part->body !== [] || $part->items !== []) && $part->images === [] && $part->table === null
            && $part->quote === null && $part->code === null && !$part->bodyIsOnlyList();
        $preference = match ($candidate->cType) {
            'header' => $onlyHeading ? self::BONUS : null,
            'text' => $plainText ? self::BONUS : null,
            'textmedia' => $part->images !== [] ? self::BONUS : null,
            // The older sibling of textmedia: offered, but textmedia comes first where both exist.
            'textpic' => $part->images !== [] ? -self::BONUS / 2 : null,
            'image' => $imagesOnly ? self::BONUS : null,
            'table' => $part->table !== null ? self::BONUS : null,
            'bullets' => $part->bodyIsOnlyList() && $part->items === [] ? self::BONUS : null,
            default => null,
        };
        if ($preference === null) {
            return null;
        }
        // A heading on its own is exactly what the header type is for.
        $score = $candidate->cType === 'header' ? 1.0 : $score;

        return new Proposal($candidate->cType, $score, $placement, self::IDENTIFIER, $preference);
    }
}

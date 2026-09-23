<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching\Rule;

use Webconsulting\DocxEditor\PageSync\Matching\ContentTypeCandidate;
use Webconsulting\DocxEditor\PageSync\Matching\FieldPlacer;
use Webconsulting\DocxEditor\PageSync\Matching\MappingRuleInterface;
use Webconsulting\DocxEditor\PageSync\Matching\Proposal;
use Webconsulting\DocxEditor\PageSync\Segmentation\PartShape;

/**
 * The generic rule: fills the part into the type's fields by role and scores the fit. It needs
 * no knowledge of any particular type, which is what makes Content Blocks elements work.
 */
final readonly class StructuralRule implements MappingRuleInterface
{
    public const string IDENTIFIER = 'structural';

    public function __construct(
        private FieldPlacer $placer,
    ) {}

    #[\Override]
    public function propose(PartShape $part, ContentTypeCandidate $candidate): ?Proposal
    {
        if (!$candidate->shape->hasContentFields()) {
            return null;
        }
        $placement = $this->placer->place($part, $candidate->shape);
        if ($placement->mapping->isEmpty()) {
            return null;
        }

        return new Proposal($candidate->cType, $placement->score(), $placement, self::IDENTIFIER);
    }
}

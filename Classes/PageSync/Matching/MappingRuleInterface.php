<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Webconsulting\DocxEditor\PageSync\Segmentation\PartShape;

/**
 * Proposes a content element type for a part of a Word document.
 *
 * Every rule is asked about every candidate type; the highest score per type wins, so a rule
 * can raise a type it knows well (a site package's "Team member" for parts that read like a
 * person) without having to know about any other type. Implementations are collected by the
 * service tag automatically — implementing the interface in a site package is enough.
 */
#[AutoconfigureTag(self::TAG)]
interface MappingRuleInterface
{
    public const string TAG = 'docx_editor.page_sync.mapping_rule';

    /**
     * @return Proposal|null null when the rule has nothing to say about this candidate
     */
    public function propose(PartShape $part, ContentTypeCandidate $candidate): ?Proposal;
}

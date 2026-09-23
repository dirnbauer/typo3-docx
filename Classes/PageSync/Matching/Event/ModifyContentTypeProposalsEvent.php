<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching\Event;

use Webconsulting\DocxEditor\PageSync\Matching\ContentTypeCandidate;
use Webconsulting\DocxEditor\PageSync\Matching\MatchContext;
use Webconsulting\DocxEditor\PageSync\Matching\Proposal;
use Webconsulting\DocxEditor\PageSync\Segmentation\Part;

/**
 * Dispatched once per document part after the rules scored every allowed content type.
 *
 * Listeners may re-rank, remove or add proposals — for example to prefer a site's own element,
 * or to rule a type out for parts that mention prices. Proposals are sorted by score again after
 * the event; a proposal for a type that is not among the candidates is dropped, so a listener
 * can never propose a type the editor is not allowed to create.
 */
final class ModifyContentTypeProposalsEvent
{
    /**
     * @param list<Proposal> $proposals Sorted by score, best first
     * @param array<string, ContentTypeCandidate> $candidates Keyed by CType
     */
    public function __construct(
        public readonly Part $part,
        private array $proposals,
        public readonly array $candidates,
        public readonly MatchContext $context,
    ) {}

    /**
     * @return list<Proposal>
     */
    public function getProposals(): array
    {
        return $this->proposals;
    }

    /**
     * @param list<Proposal> $proposals
     */
    public function setProposals(array $proposals): void
    {
        $this->proposals = $proposals;
    }
}

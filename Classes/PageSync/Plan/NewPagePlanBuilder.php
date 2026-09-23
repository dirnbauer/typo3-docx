<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Plan;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Document\DocxDocument;
use Webconsulting\DocxEditor\PageSync\Document\Heading;
use Webconsulting\DocxEditor\PageSync\Document\PageBreak;
use Webconsulting\DocxEditor\PageSync\Document\PlainText;
use Webconsulting\DocxEditor\PageSync\Exception\PageSyncException;
use Webconsulting\DocxEditor\PageSync\Field\FieldUpdate;
use Webconsulting\DocxEditor\PageSync\Matching\CandidateProvider;
use Webconsulting\DocxEditor\PageSync\Matching\ContentTypeMatcher;
use Webconsulting\DocxEditor\PageSync\Matching\MatchContext;
use Webconsulting\DocxEditor\PageSync\Record\RecordRepository;
use Webconsulting\DocxEditor\PageSync\Schema\FieldInfo;
use Webconsulting\DocxEditor\PageSync\Schema\FieldKind;
use Webconsulting\DocxEditor\PageSync\Schema\FieldRole;
use Webconsulting\DocxEditor\PageSync\Segmentation\PartAnalyzer;
use Webconsulting\DocxEditor\PageSync\Segmentation\Segmenter;

/**
 * Plans a Word document as one or more new pages under a parent page: the first heading 1 of
 * each page becomes its title, the rest is segmented and matched like new content on an
 * existing page.
 */
#[Autoconfigure(public: true)]
final readonly class NewPagePlanBuilder
{
    public function __construct(
        private RecordRepository $records,
        private Segmenter $segmenter,
        private ContentTypeMatcher $matcher,
        private CandidateProvider $candidates,
        private SiteFinder $siteFinder,
    ) {}

    /**
     * @return list<SyncPlan> One plan per new page, in document order
     */
    public function build(DocxDocument $document, string $documentHash, int $parentUid, BackendUserAuthentication $user, PageSplit $split = PageSplit::None, bool $useJev = true): array
    {
        $workspaceId = (int)$user->workspace;
        $parent = $this->records->record('pages', $parentUid, $workspaceId);
        if ($parent === null) {
            throw new PageSyncException('error.pageNotFound', 404, [$parentUid]);
        }
        if (!$user->isAdmin() && (!$user->doesUserHaveAccess($parent, Permission::PAGE_NEW) || !$user->check('tables_modify', 'pages') || !$user->check('tables_modify', 'tt_content'))) {
            throw new PageSyncException('error.noPageCreation', 403, [$parentUid]);
        }

        $messages = [];
        foreach ($document->warnings as $warning) {
            $messages[] = new PlanMessage($warning->labelKey, $warning->arguments);
        }
        // Breaks stay: they split the document into pages, or the page into parts.
        $blocks = PartAnalyzer::flatten($document->blocks, true);
        $candidates = $this->candidates->forColumn($parentUid, 0, $user);
        $languageTag = $this->languageTag($parentUid);

        $plans = [];
        foreach ($this->pages($blocks, $split) as $index => $pageBlocks) {
            $title = '';
            $first = $pageBlocks[0] ?? null;
            if ($first instanceof Heading && $first->level === 1) {
                $title = trim(PlainText::ofBlock($first));
                array_shift($pageBlocks);
            }
            if ($title === '') {
                $title = $index === 0 && $document->title !== '' ? $document->title : self::firstHeading($pageBlocks);
            }
            $title = $title !== '' ? $title : 'Untitled';

            $parts = $this->segmenter->segment($pageBlocks, 'n' . ($index + 1) . 'p');
            $matches = $this->matcher->match($parts, $candidates, new MatchContext($parentUid, 0, 0, $languageTag, $title, $useJev));
            $entries = [];
            $counter = 0;
            foreach ($matches as $match) {
                $part = $match->part;
                $heading = $part->shape->headingText();
                $chosen = $match->chosen;
                $entries[] = new PlanEntry(
                    id: 'n' . ($index + 1) . 'e' . (++$counter),
                    action: $chosen === null ? EntryAction::Skip : EntryAction::Create,
                    table: 'tt_content',
                    uid: 0,
                    type: $chosen->cType ?? '',
                    colPos: 0,
                    afterUid: 0,
                    title: $heading !== '' ? $heading : mb_substr($part->shape->excerpt(), 0, 80),
                    fields: $chosen === null ? [] : array_map(
                        static fn($assignment): FieldChange => new FieldChange($assignment->field, FieldStatus::New, $assignment->value->preview(), ''),
                        $chosen->mapping()->assignments,
                    ),
                    match: $match,
                    messages: $chosen === null ? [new PlanMessage('plan.message.noType')] : [],
                );
            }
            $titleField = new FieldInfo('pages', 'title', 'LLL:EXT:frontend/Resources/Private/Language/locallang_tca.xlf:pages.title', FieldKind::Input, FieldRole::Heading);
            $plans[] = new SyncPlan(
                pageUid: 0,
                languageId: 0,
                workspaceId: $workspaceId,
                userId: (int)($user->user['uid'] ?? 0),
                documentHash: $documentHash . ':' . $index,
                manifestFound: $document->manifest !== null,
                manifestTrusted: false,
                entries: $entries,
                page: new PlanEntry(
                    id: 'page',
                    action: EntryAction::Create,
                    table: 'pages',
                    uid: 0,
                    title: $title,
                    fields: [new FieldChange($titleField, FieldStatus::New, $title, '', new FieldUpdate($title))],
                ),
                messages: $index === 0 ? $messages : [],
                newPage: true,
                parentPageUid: $parentUid,
            );
        }

        return $plans;
    }

    /**
     * @param list<Block> $blocks
     *
     * @return list<list<Block>>
     */
    private function pages(array $blocks, PageSplit $split): array
    {
        if ($split === PageSplit::None) {
            return [$blocks];
        }
        $pages = [];
        $current = [];
        foreach ($blocks as $block) {
            $starts = match ($split) {
                PageSplit::Heading1 => $block instanceof Heading && $block->level === 1,
                PageSplit::PageBreak => $block instanceof PageBreak,
            };
            if ($starts && $current !== []) {
                $pages[] = $current;
                $current = [];
            }
            if ($block instanceof PageBreak && $split === PageSplit::PageBreak) {
                continue;
            }
            $current[] = $block;
        }
        if ($current !== []) {
            $pages[] = $current;
        }

        return $pages === [] ? [[]] : $pages;
    }

    /**
     * @param list<Block> $blocks
     */
    private static function firstHeading(array $blocks): string
    {
        foreach ($blocks as $block) {
            if ($block instanceof Heading) {
                return trim(PlainText::ofBlock($block));
            }
        }

        return '';
    }

    private function languageTag(int $pageUid): string
    {
        try {
            return str_replace('_', '-', $this->siteFinder->getSiteByPageId($pageUid)->getDefaultLanguage()->getLocale()->getName());
        } catch (SiteNotFoundException) {
            return '';
        }
    }
}

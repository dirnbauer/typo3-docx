<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Plan;

use TYPO3\CMS\Core\Localization\LanguageService;
use Webconsulting\DocxEditor\PageSync\Matching\PartMatch;
use Webconsulting\DocxEditor\PageSync\Matching\Proposal;
use Webconsulting\DocxEditor\PageSync\Schema\ElementShapeFactory;

/**
 * A plan as plain data with every label resolved in the backend user's language — what the
 * review screen renders and the CLI prints.
 */
final readonly class PlanPresenter
{
    public function __construct(
        private ElementShapeFactory $shapes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(SyncPlan $plan, LanguageService $labels): array
    {
        return [
            'pageUid' => $plan->pageUid,
            'parentPageUid' => $plan->parentPageUid,
            'newPage' => $plan->newPage,
            'languageId' => $plan->languageId,
            'workspaceId' => $plan->workspaceId,
            'manifest' => ['found' => $plan->manifestFound, 'trusted' => $plan->manifestTrusted],
            'digest' => $plan->digest(),
            'counts' => $plan->counts(),
            'hasWrites' => $plan->hasWrites(),
            'messages' => $this->messages($plan->messages, $labels),
            'page' => $plan->page === null ? null : $this->entry($plan->page, $labels),
            'entries' => array_map(fn(PlanEntry $entry): array => $this->entry($entry, $labels), $plan->entries),
            'moves' => array_map(static fn(PlanMove $move): array => $move->toArray(), $plan->moves),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(PlanEntry $entry, LanguageService $labels): array
    {
        return [
            'id' => $entry->id,
            'action' => $entry->action->value,
            'actionLabel' => $this->label($labels, 'plan.action.' . $entry->action->value),
            'table' => $entry->table,
            'uid' => $entry->uid,
            'type' => $entry->type,
            'typeLabel' => $this->typeLabel($entry->type, $labels),
            'colPos' => $entry->colPos,
            'afterUid' => $entry->afterUid,
            'title' => $entry->title,
            'requiresConfirmation' => $entry->requiresConfirmation(),
            'recognisedBy' => $entry->recognisedBy,
            'fields' => array_map(
                fn(FieldChange $change): array => $change->toArray($this->fieldLabel($change->field->label, $change->field->name, $labels))
                    + ['statusLabel' => $this->label($labels, 'plan.status.' . $change->status->value)],
                $entry->fields,
            ),
            'children' => array_map(fn(PlanEntry $child): array => $this->entry($child, $labels), $entry->children),
            'messages' => $this->messages($entry->messages, $labels),
            'match' => $entry->match === null ? null : $this->match($entry->match, $labels),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function match(PartMatch $match, LanguageService $labels): array
    {
        return [
            'structure' => $match->part->shape->describe(),
            'excerpt' => $match->part->shape->excerpt(240),
            'chosen' => $match->chosen?->cType,
            'confidence' => round($match->confidence, 4),
            'decidedBy' => $match->decidedBy,
            'needsReview' => $match->needsReview,
            'proposals' => array_map(fn(Proposal $proposal): array => [
                'type' => $proposal->cType,
                'label' => $this->typeLabel($proposal->cType, $labels),
                'score' => round($proposal->score, 4),
                'rank' => round($proposal->rank(), 4),
                'rule' => $proposal->rule,
                'placed' => $proposal->placement->placed,
                'demoted' => $proposal->placement->demoted,
                'lost' => $proposal->placement->lost,
                'missingRequired' => $proposal->placement->missingRequired,
            ], $match->proposals),
            'jev' => $match->jev?->toArray(),
        ];
    }

    /**
     * @param list<PlanMessage> $messages
     *
     * @return list<array{key: string, text: string}>
     */
    private function messages(array $messages, LanguageService $labels): array
    {
        return array_map(function (PlanMessage $message) use ($labels): array {
            $text = $this->label($labels, $message->key);
            if ($message->arguments !== []) {
                $text = vsprintf($text, array_map(static fn(string|int $argument): string => (string)$argument, $message->arguments));
            }

            return ['key' => $message->key, 'text' => $text];
        }, $messages);
    }

    private function typeLabel(string $type, LanguageService $labels): string
    {
        if ($type === '') {
            return '';
        }
        $label = $this->shapes->contentTypes()[$type]['label'] ?? $type;
        $resolved = trim($labels->sL($label));

        return $resolved !== '' ? $resolved : $type;
    }

    private function fieldLabel(string $label, string $name, LanguageService $labels): string
    {
        $resolved = trim($labels->sL($label));

        return $resolved !== '' ? $resolved : $name;
    }

    private function label(LanguageService $labels, string $key): string
    {
        $label = $labels->sL('docx_editor.pagesync:' . $key);

        return $label !== '' ? $label : $key;
    }
}

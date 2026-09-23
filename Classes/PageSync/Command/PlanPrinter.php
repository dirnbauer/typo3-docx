<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Command;

use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Localization\LanguageService;
use Webconsulting\DocxEditor\PageSync\Apply\ApplyResult;
use Webconsulting\DocxEditor\PageSync\Matching\PartMatch;
use Webconsulting\DocxEditor\PageSync\Plan\EntryAction;
use Webconsulting\DocxEditor\PageSync\Plan\FieldChange;
use Webconsulting\DocxEditor\PageSync\Plan\FieldStatus;
use Webconsulting\DocxEditor\PageSync\Plan\PlanEntry;
use Webconsulting\DocxEditor\PageSync\Plan\PlanMessage;
use Webconsulting\DocxEditor\PageSync\Plan\SyncPlan;

/**
 * Prints a plan and an apply result on the console.
 */
final readonly class PlanPrinter
{
    public function __construct(
        private SymfonyStyle $io,
        private LanguageService $labels,
    ) {}

    public function plan(SyncPlan $plan): void
    {
        $this->io->section($plan->newPage
            ? sprintf('%s "%s"', $this->label('cli.newPage'), $plan->page->title ?? '')
            : sprintf('%s %d (%s %d, %s %d)', $this->label('cli.page'), $plan->pageUid, $this->label('cli.language'), $plan->languageId, $this->label('cli.workspace'), $plan->workspaceId));
        $this->messages($plan->messages, '');

        $rows = [];
        $page = $plan->newPage ? null : $plan->page;
        $entries = $page === null ? $plan->entries : [$page, ...$plan->entries];
        foreach ($entries as $entry) {
            $this->rows($entry, 0, $rows);
        }
        $this->io->table([$this->label('cli.column.action'), $this->label('cli.column.record'), $this->label('cli.column.type'), $this->label('cli.column.content'), $this->label('cli.column.details')], $rows);

        $counts = array_filter($plan->counts());
        $this->io->text(implode(', ', array_map(
            fn(string $action, int $count): string => $count . ' × ' . $this->label('plan.action.' . $action),
            array_keys($counts),
            $counts,
        )));
        foreach ($plan->moves as $move) {
            $this->io->text(sprintf('%s tt_content:%d → colPos %d, %s %d', $this->label('cli.move'), $move->uid, $move->colPos, $this->label('cli.after'), $move->afterUid));
        }
    }

    public function result(ApplyResult $result): void
    {
        $this->io->definitionList(
            [$this->label('cli.result.page') => (string)$result->pageUid],
            [$this->label('cli.result.created') => self::uids(array_values($result->created))],
            [$this->label('cli.result.updated') => self::uids($result->updated)],
            [$this->label('cli.result.deleted') => self::uids($result->deleted)],
            [$this->label('cli.result.moved') => self::uids($result->moved)],
            [$this->label('cli.result.translated') => self::uids(array_values($result->translated))],
        );
        foreach ($result->errors as $error) {
            $this->io->error($error);
        }
    }

    /**
     * @param list<list<string>> $rows
     */
    private function rows(PlanEntry $entry, int $depth, array &$rows): void
    {
        if ($entry->action === EntryAction::Unchanged && $depth > 0) {
            return;
        }
        $details = [];
        foreach ($entry->fields as $change) {
            if ($change->status !== FieldStatus::Unchanged) {
                $details[] = $this->field($change);
            }
        }
        if ($entry->match !== null) {
            $details[] = $this->match($entry->match);
        }
        if ($entry->recognisedBy !== '') {
            $details[] = $this->label('cli.recognisedBy') . ' ' . $entry->recognisedBy;
        }
        foreach ($entry->messages as $message) {
            $details[] = $this->message($message);
        }
        $rows[] = [
            str_repeat('  ', $depth) . $this->label('plan.action.' . $entry->action->value),
            $entry->uid > 0 ? $entry->table . ':' . $entry->uid : $entry->table,
            $entry->type,
            self::shorten($entry->title, 48),
            implode("\n", $details),
        ];
        foreach ($entry->children as $child) {
            $this->rows($child, $depth + 1, $rows);
        }
    }

    private function field(FieldChange $change): string
    {
        $line = $change->field->name . ': ' . $this->label('plan.status.' . $change->status->value);
        if ($change->status === FieldStatus::Conflict) {
            $line .= sprintf(' (Word: "%s", TYPO3: "%s")', self::shorten($change->wordPreview, 30), self::shorten($change->typo3Preview, 30));
        }

        return $line . ($change->lossy ? ' ' . $this->label('cli.lossy') : '');
    }

    private function match(PartMatch $match): string
    {
        $contenders = array_map(
            static fn($proposal): string => sprintf('%s %.2f', $proposal->cType, $proposal->rank()),
            array_slice($match->proposals, 0, 3),
        );
        $line = sprintf('%s: %s (%.2f) — %s', $this->label('cli.decidedBy.' . $match->decidedBy), $match->chosen->cType ?? '-', $match->confidence, implode(', ', $contenders));
        if ($match->jev !== null && $match->jev->fallbackReason !== '') {
            $line .= ' — Jev: ' . $match->jev->fallbackReason;
        }

        return $line . ($match->needsReview ? ' ' . $this->label('cli.needsReview') : '');
    }

    /**
     * @param list<PlanMessage> $messages
     */
    private function messages(array $messages, string $prefix): void
    {
        foreach ($messages as $message) {
            $this->io->warning($prefix . $this->message($message));
        }
    }

    private function message(PlanMessage $message): string
    {
        $text = $this->label($message->key);

        return $message->arguments === [] ? $text : vsprintf($text, array_map(static fn(string|int $argument): string => (string)$argument, $message->arguments));
    }

    private function label(string $key): string
    {
        $label = $this->labels->sL('docx_editor.pagesync:' . $key);

        return $label !== '' ? $label : $key;
    }

    /**
     * @param list<int> $uids
     */
    private static function uids(array $uids): string
    {
        return $uids === [] ? '-' : implode(', ', $uids);
    }

    private static function shorten(string $text, int $length): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
    }
}

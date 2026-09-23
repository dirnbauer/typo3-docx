<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Plan;

/**
 * What the editor decided while reviewing a plan: which entries to leave out, which content type
 * a new element gets, which side wins a conflict, and which deletions are confirmed.
 *
 * Without a decision, an entry is applied as planned — except conflicts, where TYPO3's value is
 * kept unless Word is chosen, and deletions, which need an explicit confirmation.
 */
final readonly class PlanDecisions
{
    public const string WORD = 'word';
    public const string TYPO3 = 'typo3';

    /**
     * @param list<string> $excluded Entry ids not to apply
     * @param array<string, string> $types Entry id => CType for new elements
     * @param array<string, array<string, 'word'|'typo3'>> $resolutions Entry id => field => winner
     * @param list<string> $confirmedDeletions Entry ids of deletions to carry out
     * @param 'word'|'typo3' $conflictDefault Who wins a conflict without a decision
     */
    public function __construct(
        public array $excluded = [],
        public array $types = [],
        public array $resolutions = [],
        public array $confirmedDeletions = [],
        public string $conflictDefault = self::TYPO3,
        public bool $applyMoves = true,
    ) {}

    /**
     * Decisions as the review UI posts them. Anything malformed is dropped rather than trusted.
     *
     * @param array<mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $excluded = [];
        foreach (is_array($raw['excluded'] ?? null) ? $raw['excluded'] : [] as $id) {
            if (is_string($id) && self::isId($id)) {
                $excluded[] = $id;
            }
        }
        $types = [];
        foreach (is_array($raw['types'] ?? null) ? $raw['types'] : [] as $id => $type) {
            if (is_string($id) && self::isId($id) && is_string($type) && preg_match('/^[A-Za-z0-9_.:-]{1,255}$/', $type) === 1) {
                $types[$id] = $type;
            }
        }
        $resolutions = [];
        foreach (is_array($raw['resolutions'] ?? null) ? $raw['resolutions'] : [] as $id => $fields) {
            if (!is_string($id) || !self::isId($id) || !is_array($fields)) {
                continue;
            }
            foreach ($fields as $field => $winner) {
                if (is_string($field) && preg_match('/^[A-Za-z0-9_]{1,64}$/', $field) === 1 && ($winner === self::WORD || $winner === self::TYPO3)) {
                    $resolutions[$id][$field] = $winner;
                }
            }
        }
        $confirmed = [];
        foreach (is_array($raw['confirmedDeletions'] ?? null) ? $raw['confirmedDeletions'] : [] as $id) {
            if (is_string($id) && self::isId($id)) {
                $confirmed[] = $id;
            }
        }

        return new self(
            excluded: $excluded,
            types: $types,
            resolutions: $resolutions,
            confirmedDeletions: $confirmed,
            conflictDefault: ($raw['conflictDefault'] ?? '') === self::WORD ? self::WORD : self::TYPO3,
            applyMoves: ($raw['applyMoves'] ?? true) !== false,
        );
    }

    public function includes(PlanEntry $entry): bool
    {
        if (in_array($entry->id, $this->excluded, true)) {
            return false;
        }
        if ($entry->action === EntryAction::Delete) {
            return in_array($entry->id, $this->confirmedDeletions, true);
        }

        return $entry->action->writes();
    }

    public function typeFor(PlanEntry $entry): string
    {
        return $this->types[$entry->id] ?? $entry->type;
    }

    /**
     * Whether Word's value of the field is written.
     */
    public function takesWord(PlanEntry $entry, FieldChange $change): bool
    {
        return match ($change->status) {
            FieldStatus::Changed, FieldStatus::New => true,
            FieldStatus::Conflict => ($this->resolutions[$entry->id][$change->field->name] ?? $this->conflictDefault) === self::WORD,
            default => false,
        };
    }

    private static function isId(string $id): bool
    {
        return preg_match('/^[a-z0-9-]{1,64}$/', $id) === 1;
    }
}

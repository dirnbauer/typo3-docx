<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching;

use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Matching\Value\BlocksValue;
use Webconsulting\DocxEditor\PageSync\Matching\Value\FieldValue;
use Webconsulting\DocxEditor\PageSync\Schema\ElementShape;
use Webconsulting\DocxEditor\PageSync\Schema\FieldInfo;
use Webconsulting\DocxEditor\PageSync\Schema\FieldKind;
use Webconsulting\DocxEditor\PageSync\Schema\FieldRole;

/**
 * Bookkeeping while one part is placed into one record type: which fields are taken, what
 * collects in the rich text field, and the weights and credits coverage is computed from.
 *
 * @internal
 */
final class PlacementBuilder
{
    /** @var array<string, true> */
    private array $used = [];

    /** @var list<FieldAssignment> */
    private array $assignments = [];

    /** @var array<string, int|string> */
    private array $settings = [];

    private float $weight = 0.0;
    private float $credit = 0.0;

    private ?FieldInfo $richField = null;
    private bool $richChosen = false;

    /** @var list<Block> */
    private array $richHead = [];

    /** @var list<Block> */
    private array $richBody = [];

    /** @var list<string> */
    private array $placed = [];

    /** @var list<string> */
    private array $demoted = [];

    /** @var list<string> */
    private array $lost = [];

    public function __construct(
        public readonly ElementShape $shape,
    ) {}

    public function peek(FieldRole $role): ?FieldInfo
    {
        $candidates = $role === FieldRole::Body ? $this->shape->bodyFields() : $this->shape->fieldsWithRole($role);
        foreach ($candidates as $field) {
            if (!$this->isUsed($field) && $field !== $this->richField) {
                return $field;
            }
        }

        return null;
    }

    public function take(FieldRole $role): ?FieldInfo
    {
        $field = $this->peek($role);
        if ($field !== null) {
            $this->markUsed($field);
        }

        return $field;
    }

    public function markUsed(FieldInfo $field): void
    {
        $this->used[$field->name] = true;
    }

    public function isUsed(FieldInfo $field): bool
    {
        return isset($this->used[$field->name]);
    }

    public function assign(FieldInfo $field, FieldValue $value, string $source, float $weight, float $credit): void
    {
        $this->markUsed($field);
        $this->assignments[] = new FieldAssignment($field, $value, $source);
        $this->credit($source . ' → ' . $field->name, $weight, $credit);
    }

    /**
     * Counts a unit of the part as placed with the given credit.
     */
    public function credit(string $description, float $weight, float $credit): void
    {
        if ($weight <= 0.0) {
            return;
        }
        $this->weight += $weight;
        $this->credit += $weight * max(0.0, min(1.0, $credit));
        if ($credit >= 0.95) {
            $this->placed[] = $description;
        } else {
            $this->demoted[] = $description;
        }
    }

    /**
     * Counts content that was appended to the rich text field without a weight of its own.
     */
    public function creditRich(string $source, float $weight, float $credit): void
    {
        $this->credit($source . ' → ' . $this->richFieldName(), $weight, $credit);
    }

    public function lose(string $source, float $weight): void
    {
        $this->weight += $weight;
        $this->lost[] = $source;
    }

    /**
     * Whether a rich text field is available to take running content.
     */
    public function hasRichBody(): bool
    {
        if ($this->richChosen) {
            return $this->richField !== null;
        }
        foreach ($this->shape->bodyFields() as $field) {
            if ($field->kind === FieldKind::RichText && !$this->isUsed($field)) {
                return true;
            }
        }

        return false;
    }

    public function prependRich(Block $block, string $source, float $weight, float $credit): void
    {
        if (!$this->chooseRichField()) {
            $this->lose($source, $weight);

            return;
        }
        $this->richHead[] = $block;
        $this->credit($source . ' → ' . $this->richFieldName(), $weight, $credit);
    }

    public function appendRich(Block $block, string $source, float $weight, float $credit): void
    {
        if (!$this->chooseRichField()) {
            $this->lose($source, $weight);

            return;
        }
        $this->richBody[] = $block;
        if ($weight > 0.0) {
            $this->credit($source . ' → ' . $this->richFieldName(), $weight, $credit);
        }
    }

    public function setting(string $field, int|string $value): void
    {
        $this->settings[$field] = $value;
    }

    public function finish(): Placement
    {
        $assignments = $this->assignments;
        if ($this->richField !== null && ($this->richHead !== [] || $this->richBody !== [])) {
            array_unshift($assignments, new FieldAssignment($this->richField, new BlocksValue([...$this->richHead, ...$this->richBody]), 'text'));
        }

        $missing = [];
        foreach ($this->shape->requiredFields() as $field) {
            if (!$this->isUsed($field)) {
                $missing[] = $field->name;
            }
        }
        $unused = 0.0;
        foreach ($this->shape->contentFields() as $field) {
            if ($this->isUsed($field)) {
                continue;
            }
            $unused += match ($field->role) {
                FieldRole::Collection => FieldPlacer::UNUSED_COLLECTION_PENALTY,
                FieldRole::Image => FieldPlacer::UNUSED_IMAGE_PENALTY,
                default => FieldPlacer::UNUSED_PENALTY,
            };
        }
        $penalty = count($missing) * FieldPlacer::REQUIRED_PENALTY + min(FieldPlacer::MAX_UNUSED_PENALTY, $unused);

        return new Placement(
            mapping: new FieldMapping($assignments, $this->settings),
            coverage: $this->weight > 0.0 ? $this->credit / $this->weight : 0.0,
            penalty: $penalty,
            placed: $this->placed,
            demoted: $this->demoted,
            lost: $this->lost,
            missingRequired: $missing,
        );
    }

    private function chooseRichField(): bool
    {
        if (!$this->richChosen) {
            $this->richChosen = true;
            foreach ($this->shape->bodyFields() as $field) {
                if ($field->kind === FieldKind::RichText && !$this->isUsed($field)) {
                    $this->richField = $field;
                    $this->markUsed($field);
                    break;
                }
            }
        }

        return $this->richField !== null;
    }

    private function richFieldName(): string
    {
        return $this->richField === null ? '' : $this->richField->name;
    }
}

<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Plan;

/**
 * A notice in the plan: a label key of docx_editor.pagesync and its arguments.
 */
final readonly class PlanMessage
{
    /**
     * @param list<string|int> $arguments
     */
    public function __construct(
        public string $key,
        public array $arguments = [],
    ) {}

    /**
     * @return array{key: string, arguments: list<string|int>}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'arguments' => $this->arguments];
    }
}

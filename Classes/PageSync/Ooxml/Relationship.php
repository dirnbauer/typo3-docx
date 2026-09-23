<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Ooxml;

final readonly class Relationship
{
    public function __construct(
        public string $id,
        public string $type,
        /** For an internal relationship, the target part name resolved against its source part. */
        public string $target,
        public bool $external,
    ) {}
}

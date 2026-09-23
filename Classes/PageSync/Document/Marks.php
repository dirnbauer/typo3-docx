<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Document;

/**
 * Character formatting that survives the round trip. Everything else Word can do to a run —
 * fonts, sizes, colours, highlighting — is presentation the website's CSS owns.
 */
final readonly class Marks
{
    public function __construct(
        public bool $bold = false,
        public bool $italic = false,
        public bool $underline = false,
        public bool $strike = false,
        public bool $subscript = false,
        public bool $superscript = false,
        public bool $code = false,
    ) {}

    public static function none(): self
    {
        return new self();
    }

    public function isPlain(): bool
    {
        return !$this->bold && !$this->italic && !$this->underline && !$this->strike
            && !$this->subscript && !$this->superscript && !$this->code;
    }

    public function equals(self $other): bool
    {
        return $this->bold === $other->bold
            && $this->italic === $other->italic
            && $this->underline === $other->underline
            && $this->strike === $other->strike
            && $this->subscript === $other->subscript
            && $this->superscript === $other->superscript
            && $this->code === $other->code;
    }

    /**
     * The union of both sets of marks — how nested HTML formatting (<strong><em>) collapses
     * onto one run.
     */
    public function merge(self $other): self
    {
        return new self(
            bold: $this->bold || $other->bold,
            italic: $this->italic || $other->italic,
            underline: $this->underline || $other->underline,
            strike: $this->strike || $other->strike,
            subscript: ($this->subscript || $other->subscript) && !($this->superscript || $other->superscript),
            superscript: $this->superscript || $other->superscript,
            code: $this->code || $other->code,
        );
    }
}

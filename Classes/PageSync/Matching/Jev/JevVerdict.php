<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching\Jev;

/**
 * What Jev answered for one part.
 */
final readonly class JevVerdict
{
    /**
     * @param array<string, float> $probabilities CType => probability
     */
    public function __construct(
        /** The CType Jev chose, or null when it could not answer. */
        public ?string $choice,
        public float $confidence = 0.0,
        public array $probabilities = [],
        /** The answer clears the configured confidence threshold. */
        public bool $confident = false,
        /** Why there is no usable answer: not installed, no token, an outage … */
        public string $fallbackReason = '',
    ) {}

    public static function unavailable(string $reason): self
    {
        return new self(null, fallbackReason: $reason);
    }

    /**
     * @return array{choice: ?string, confidence: float, probabilities: array<string, float>, confident: bool, fallbackReason: string}
     */
    public function toArray(): array
    {
        return [
            'choice' => $this->choice,
            'confidence' => $this->confidence,
            'probabilities' => $this->probabilities,
            'confident' => $this->confident,
            'fallbackReason' => $this->fallbackReason,
        ];
    }
}

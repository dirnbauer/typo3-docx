<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Fixtures\PageSync;

use Webconsulting\WebconJev\Client\Dto\Answer;
use Webconsulting\WebconJev\Client\Dto\DecisionResult;
use Webconsulting\WebconJev\Client\Dto\Question;
use Webconsulting\WebconJev\Client\Dto\QuestionType;
use Webconsulting\WebconJev\Client\Dto\Usage;
use Webconsulting\WebconJev\Client\JevClientInterface;

/**
 * Stands in for TypeSafe's API: answers every choice question with what the test says, and
 * records what it was asked. No network, no token.
 */
final class FakeJevClient implements JevClientInterface
{
    /** @var list<array{state: string|array<mixed>|null, questions: array<string, Question>}> */
    public array $calls = [];

    /**
     * @param \Closure(string, Question): array{0: string, 1: float} $answer Question name and question => chosen option and confidence
     */
    public function __construct(
        private readonly \Closure $answer,
        private readonly bool $configured = true,
    ) {}

    #[\Override]
    public function ask(string|array|null $state, array $questions, ?string $model = null): DecisionResult
    {
        $this->calls[] = ['state' => $state, 'questions' => $questions];
        $answers = [];
        foreach ($questions as $name => $question) {
            [$choice, $confidence] = ($this->answer)($name, $question);
            $options = array_keys($question->criteria);
            $rest = count($options) > 1 ? (1.0 - $confidence) / (count($options) - 1) : 0.0;
            $probabilities = [];
            foreach ($options as $option) {
                $probabilities[(string)$option] = $option === $choice ? $confidence : $rest;
            }
            $answers[$name] = Answer::fromResponse($name, QuestionType::Choice, [
                'choice' => $choice,
                'confidence' => $confidence,
                'probabilities' => $probabilities,
            ]);
        }

        return new DecisionResult($answers, 'jev-test', new Usage(420, 0), 12.5);
    }

    #[\Override]
    public function isConfigured(): bool
    {
        return $this->configured;
    }
}

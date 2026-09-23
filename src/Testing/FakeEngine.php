<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Testing;

use Swis\DecisionEngine\Contracts\Engine;
use Swis\DecisionEngine\Contracts\Question;
use Swis\DecisionEngine\Engines\Capabilities;
use Swis\DecisionEngine\Engines\Jev\JevEngine;
use Swis\DecisionEngine\Outcome\Outcome;
use Swis\DecisionEngine\Outcome\Thresholds;
use Swis\DecisionEngine\Questions\Choice;
use Swis\DecisionEngine\Questions\QuestionType;
use Swis\DecisionEngine\Questions\RawQuestion;
use Swis\DecisionEngine\Request\DecisionRequest;
use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Transport\HttpResponse;

/**
 * An Engine that never talks to a network. `prepare()` renders a `fake://decision` request with the
 * Jev-shaped payload; `interpret()` reuses the real Jev parser, so fakes exercise the same answer
 * code paths as production.
 *
 * Pair it with `FakeEngine::respond()` to build a canned response body for a request:
 *
 * ```php
 * $engine = new FakeEngine();
 * $body = $engine->respond($request, ['urgent' => Fake::noul(0.9)]); // unanswered questions get neutral defaults
 * ```
 */
final class FakeEngine implements Engine
{
    public const NAME = 'fake';

    private readonly JevEngine $parser;

    public function __construct(
        private readonly string $model = 'fake-1',
        private readonly bool $calibrated = true,
        private readonly Thresholds $thresholds = new Thresholds(),
    ) {
        $this->parser = new JevEngine('fake-key', 'fake://', $model, $thresholds, new Capabilities(calibrated: $calibrated));
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function defaultModel(): string
    {
        return $this->model;
    }

    public function capabilities(): Capabilities
    {
        return Capabilities::jev()->with(calibrated: $this->calibrated);
    }

    public function prepare(DecisionRequest $request): PreparedRequest
    {
        $payload = $request->payload();
        $payload['model'] ??= $this->model;

        return PreparedRequest::jsonRequest('POST', 'fake://decision', [], $payload, ['engine' => self::NAME, 'model' => $payload['model'], 'questions' => $request->questionIds()]);
    }

    public function interpret(DecisionRequest $request, HttpResponse $response): Outcome
    {
        $outcome = $this->parser->interpret($request, $response);

        return new Outcome($outcome->answers, $outcome->model, self::NAME, $outcome->usage, $outcome->meta, $outcome->raw(), $response);
    }

    /**
     * Build a Jev-shaped response body answering every question in the request.
     *
     * @param  array<string, FakeAnswer|array<string, mixed>>  $answers  keyed by question id; missing ids get neutral defaults
     * @return array<string, mixed>
     */
    public function respond(DecisionRequest $request, array $answers = []): array
    {
        $rendered = [];

        foreach ($request->questions as $id => $question) {
            $answer = $answers[$id] ?? self::defaultFor($question);
            $rendered[$id] = $answer instanceof FakeAnswer ? $answer->render($question) : $answer;
        }

        // Answers for ids that are not questions are passed through untouched (e.g. simulating extra server output).
        foreach ($answers as $id => $answer) {
            if (! isset($rendered[$id])) {
                $rendered[$id] = $answer instanceof FakeAnswer ? $answer->render(null) : $answer;
            }
        }

        $inputTokens = (int) ceil(strlen($this->prepare($request)->body) / 4);

        return [
            'model' => $request->model ?? $this->model,
            'answers' => $rendered,
            'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => count($rendered) * 12],
        ];
    }

    /**
     * Neutral answer: first option at moderate confidence, lowest level, or noul 0.5.
     *
     * @return FakeAnswer|array<string, mixed>
     */
    public static function defaultFor(Question $question): FakeAnswer|array
    {
        if ($question instanceof RawQuestion && ! $question->isKnownType()) {
            return ['type' => $question->rawType() ?? 'unknown'];
        }

        return match ($question->type()) {
            QuestionType::Choice => Fake::choice($question instanceof Choice ? ($question->optionNames()[0] ?? 'unknown') : 'unknown', 0.6),
            QuestionType::Score => Fake::score(0.0),
            QuestionType::Noul => Fake::noul(0.5),
        };
    }
}

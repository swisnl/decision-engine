<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Serialization;

use Swis\DecisionEngine\Exceptions\InvalidDecisionException;
use Swis\DecisionEngine\Questions\QuestionSet;
use Swis\DecisionEngine\Request\DecisionRequest;
use Swis\DecisionEngine\Request\RequestOptions;
use Swis\DecisionEngine\State\State;
use Swis\DecisionEngine\Support\Arr;

/**
 * Converts a DecisionRequest to and from its canonical array form:
 *
 * ```jsonc
 * {
 *   "state": ..., "questions": {...},          // exactly the TypeSafe wire shape
 *   "engine": "jev", "model": "jev-latest",     // optional
 *   "options": {...}, "engine_options": {...},  // optional
 *   "payload": { "merge": {...} }               // optional
 * }
 * ```
 *
 * Key order is fixed; question order is preserved. `fromArray()` also accepts the bare wire
 * form `{state, model?, questions}`; unknown top-level keys are kept as `payload.merge`.
 */
final class DecisionRequestSerializer
{
    private const KNOWN_KEYS = ['state', 'questions', 'engine', 'model', 'options', 'engine_options', 'payload'];

    /**
     * @return array<string, mixed>
     */
    public static function toArray(DecisionRequest $request): array
    {
        $payload = $request->payload();

        $state = $payload['state'] ?? null;
        $questions = $payload['questions'] ?? [];
        $model = $payload['model'] ?? null;
        $merge = Arr::except($payload, ['state', 'questions', 'model']);

        $array = [
            'state' => $state,
            'questions' => $questions,
        ];

        if ($request->engine !== null) {
            $array['engine'] = $request->engine;
        }

        if ($model !== null) {
            $array['model'] = $model;
        }

        $options = $request->options->toArray();

        if ($options !== []) {
            $array['options'] = $options;
        }

        if ($request->engineOptions !== []) {
            $array['engine_options'] = $request->engineOptions;
        }

        if ($merge !== []) {
            $array['payload'] = ['merge' => $merge];
        }

        return $array;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): DecisionRequest
    {
        try {
            if (! array_key_exists('questions', $data)) {
                throw new \InvalidArgumentException('Missing [questions].');
            }

            $questions = $data['questions'];

            if (! is_array($questions)) {
                throw new \InvalidArgumentException('Expected [questions] to be an object of id → question.');
            }

            $options = Arr::array($data, 'options') ?? [];
            $engineOptions = Arr::array($data, 'engine_options') ?? [];
            $payload = Arr::array($data, 'payload') ?? [];
            $merge = Arr::array(Arr::stringKeys($payload), 'merge') ?? [];
            $unknown = Arr::except($data, self::KNOWN_KEYS);

            return new DecisionRequest(
                state: State::from($data['state'] ?? null),
                questions: QuestionSet::fromArray($questions),
                engine: Arr::string($data, 'engine'),
                model: Arr::string($data, 'model'),
                options: RequestOptions::fromArray(Arr::stringKeys($options)),
                engineOptions: Arr::stringKeys($engineOptions),
                merge: Arr::stringKeys(array_replace($unknown, $merge)),
            );
        } catch (\InvalidArgumentException $e) {
            if ($e instanceof InvalidDecisionException) {
                throw $e;
            }

            throw new InvalidDecisionException(['request' => [$e->getMessage()]], 'Cannot build a decision from the given array: ' . $e->getMessage(), $e);
        }
    }
}

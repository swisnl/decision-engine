<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Engines\Llm;

use Swis\DecisionEngine\Answers\AnswerFactory;
use Swis\DecisionEngine\Answers\MalformedAnswerException;
use Swis\DecisionEngine\Contracts\Engine;
use Swis\DecisionEngine\Engines\Capabilities;
use Swis\DecisionEngine\Exceptions\ApiException;
use Swis\DecisionEngine\Exceptions\ConfigurationException;
use Swis\DecisionEngine\Exceptions\ResponseValidationException;
use Swis\DecisionEngine\Outcome\Meta;
use Swis\DecisionEngine\Outcome\Outcome;
use Swis\DecisionEngine\Outcome\Thresholds;
use Swis\DecisionEngine\Outcome\Usage;
use Swis\DecisionEngine\Questions\QuestionSet;
use Swis\DecisionEngine\Request\DecisionRequest;
use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Support\Arr;
use Swis\DecisionEngine\Support\Json;
use Swis\DecisionEngine\Transport\HttpResponse;
use Swis\DecisionEngine\Version;

/**
 * Base for engines that emulate System One by having a general-purpose LLM fill a JSON schema in
 * one call. Probabilities are self-reported and therefore **uncalibrated**: every Outcome carries
 * `meta.calibrated = false`.
 *
 * Subclasses supply the provider specifics: endpoint, headers, request body, and how to pull the
 * structured object, usage and ids out of the response.
 */
abstract class StructuredOutputEngine implements Engine
{
    /**
     * @param  array<string, mixed>  $options  engine-level defaults, overridden per request by `engineOptions()`
     */
    public function __construct(
        protected readonly string $apiKey,
        protected readonly string $baseUrl,
        protected readonly string $model,
        protected readonly array $options = [],
        protected readonly Thresholds $thresholds = new Thresholds(),
    ) {
        if (trim($apiKey) === '') {
            throw ConfigurationException::missing('engines.' . $this->name() . '.api_key');
        }
    }

    public function defaultModel(): string
    {
        return $this->model;
    }

    public function capabilities(): Capabilities
    {
        return Capabilities::llm();
    }

    /**
     * Whether the provider's strict schema mode accepts `minimum` / `maximum` on numbers.
     */
    abstract protected function supportsNumericConstraints(): bool;

    abstract protected function endpoint(): string;

    /**
     * @return array<string, string>
     */
    abstract protected function headers(): array;

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $options  merged engine options for this request
     * @return array<string, mixed>
     */
    abstract protected function body(string $model, string $system, string $user, array $schema, array $options, int $questionCount): array;

    /**
     * Pull the model-filled object out of the decoded response body.
     *
     * @param  array<string, mixed>  $body
     * @return array<array-key, mixed>
     *
     * @throws ResponseValidationException
     */
    abstract protected function extractOutput(array $body, HttpResponse $response): array;

    /**
     * @param  array<string, mixed>  $body
     */
    abstract protected function extractUsage(array $body): Usage;

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>  extras for Meta (provider response id, stop reason, …)
     */
    abstract protected function extractMeta(array $body, HttpResponse $response): array;

    abstract protected function requestIdHeader(): string;

    public function prepare(DecisionRequest $request): PreparedRequest
    {
        $payload = $request->payload();
        $questionsWire = $payload['questions'] ?? [];
        $questions = is_array($questionsWire) ? QuestionSet::fromArray($questionsWire) : $request->questions;
        $state = $payload['state'] ?? null;
        $model = $payload['model'] ?? $this->model;
        $model = is_string($model) ? $model : $this->model;
        $options = $this->mergedOptions($request);

        $schema = SchemaBuilder::build($questions, $this->supportsNumericConstraints());
        $promptState = is_array($state) || is_string($state) || $state === null ? $state : Json::encode($state);
        $body = $this->body($model, PromptBuilder::system(), PromptBuilder::user($promptState, $questions->toArray()), $schema, $options, count($questions));

        $mergeBody = $options['merge_body'] ?? null;

        if (is_array($mergeBody)) {
            $body = Arr::replaceRecursive($body, Arr::stringKeys($mergeBody));
        }

        return PreparedRequest::jsonRequest(
            'POST',
            rtrim($this->baseUrl, '/') . $this->endpoint(),
            ['User-Agent' => Version::userAgent()] + $this->headers(),
            $body,
            ['engine' => $this->name(), 'model' => $model, 'questions' => $questions->ids()],
        );
    }

    public function interpret(DecisionRequest $request, HttpResponse $response): Outcome
    {
        if (! $response->isSuccess()) {
            throw ApiException::fromResponse($response, null, $this->name());
        }

        try {
            $body = $response->json();
        } catch (\JsonException $e) {
            throw ResponseValidationException::at('', 'body is not valid JSON', $response, null, $this->name(), $e);
        }

        $output = $this->extractOutput($body, $response);

        try {
            $answers = AnswerFactory::fromAnswers(AnswerNormalizer::normalize($this->effectiveQuestions($request), $output), $this->thresholds);
        } catch (MalformedAnswerException $e) {
            throw ResponseValidationException::at($e->fieldPath, $e->getMessage(), $response, null, $this->name(), $e);
        }

        $model = $body['model'] ?? null;

        return new Outcome(
            answers: $answers,
            model: is_string($model) ? $model : ($request->model ?? $this->model),
            engine: $this->name(),
            usage: $this->extractUsage($body),
            meta: new Meta(
                calibrated: false,
                requestId: $response->header($this->requestIdHeader()),
                latencyMs: $response->latencyMs,
                extra: $this->extractMeta($body, $response),
            ),
            raw: $body,
            response: $response,
        );
    }

    /**
     * Questions as the model saw them (payload mutators applied).
     */
    protected function effectiveQuestions(DecisionRequest $request): QuestionSet
    {
        if (! $request->hasMutators()) {
            return $request->questions;
        }

        $questions = $request->payload()['questions'] ?? null;

        return is_array($questions) ? QuestionSet::fromArray($questions) : $request->questions;
    }

    /**
     * Engine config `options` overridden by the request's `engineOptions`.
     *
     * @return array<string, mixed>
     */
    protected function mergedOptions(DecisionRequest $request): array
    {
        return Arr::replaceRecursive($this->options, $request->engineOptions);
    }

    /**
     * Copy allow-listed keys from the merged options into the provider body.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $options
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    protected static function passthrough(array $body, array $options, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $options)) {
                $body[$key] = $options[$key];
            }
        }

        return $body;
    }

    /**
     * Decode the JSON text a model produced.
     *
     * @return array<array-key, mixed>
     */
    protected function decodeOutput(string $text, string $path, HttpResponse $response): array
    {
        try {
            return Json::decodeArray($text);
        } catch (\JsonException $e) {
            throw ResponseValidationException::at($path, 'model output is not a JSON object: ' . $e->getMessage(), $response, null, $this->name(), $e);
        }
    }
}

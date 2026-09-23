<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Engines\Anthropic;

use Swis\DecisionEngine\Engines\Llm\StructuredOutputEngine;
use Swis\DecisionEngine\Exceptions\ConfigurationException;
use Swis\DecisionEngine\Exceptions\ResponseValidationException;
use Swis\DecisionEngine\Outcome\Thresholds;
use Swis\DecisionEngine\Outcome\Usage;
use Swis\DecisionEngine\Support\Arr;
use Swis\DecisionEngine\Transport\HttpResponse;

/**
 * Anthropic Messages API (`POST /v1/messages`). Default model `claude-haiku-4-5`.
 *
 * Two modes, chosen with the `mode` engine option:
 * - `structured` (default): `output_config.format` = json_schema; the JSON comes back as a text block.
 * - `tool`: one forced tool `record_decision` whose `input_schema` is our schema; the JSON is the
 *   tool call's `input`. Fallback for models/platforms without structured outputs.
 *
 * Other engine options: `anthropic_version` (header, default 2023-06-01), `anthropic_beta`
 * (header), `max_tokens` (default `256 + 64 × questions`), `temperature`, `metadata`, `merge_body`.
 *
 * ```php
 * Decision::for($state)->using('haiku')->engineOptions(['mode' => 'tool'])->…
 * ```
 */
final class AnthropicEngine extends StructuredOutputEngine
{
    public const NAME = 'anthropic';

    public const DEFAULT_BASE_URL = 'https://api.anthropic.com';

    public const DEFAULT_MODEL = 'claude-haiku-4-5';

    public const DEFAULT_VERSION = '2023-06-01';

    public const TOOL_NAME = 'record_decision';

    private const PASSTHROUGH = ['temperature', 'top_p', 'top_k', 'metadata', 'max_tokens'];

    /**
     * @param  array<string, mixed>  $config  `{api_key, base_url?, model?, options?}`
     */
    public static function fromConfig(array $config, ?Thresholds $thresholds = null): self
    {
        $apiKey = $config['api_key'] ?? null;

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw ConfigurationException::missing('engines.anthropic.api_key');
        }

        return new self(
            $apiKey,
            Arr::string($config, 'base_url') ?? self::DEFAULT_BASE_URL,
            Arr::string($config, 'model') ?? self::DEFAULT_MODEL,
            Arr::stringKeys(Arr::array($config, 'options') ?? []),
            $thresholds ?? new Thresholds(),
        );
    }

    public function name(): string
    {
        return self::NAME;
    }

    protected function supportsNumericConstraints(): bool
    {
        return false;
    }

    protected function endpoint(): string
    {
        return '/v1/messages';
    }

    protected function headers(): array
    {
        $headers = [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => is_string($this->options['anthropic_version'] ?? null) ? $this->options['anthropic_version'] : self::DEFAULT_VERSION,
        ];

        if (is_string($this->options['anthropic_beta'] ?? null)) {
            $headers['anthropic-beta'] = $this->options['anthropic_beta'];
        }

        return $headers;
    }

    protected function body(string $model, string $system, string $user, array $schema, array $options, int $questionCount): array
    {
        $body = [
            'model' => $model,
            'max_tokens' => 256 + 64 * $questionCount,
            'system' => $system,
            'messages' => [
                ['role' => 'user', 'content' => $user],
            ],
        ];

        if (($options['mode'] ?? 'structured') === 'tool') {
            $body['tools'] = [[
                'name' => self::TOOL_NAME,
                'description' => 'Record the probability distributions for every question. Call exactly once.',
                'input_schema' => $schema,
            ]];
            $body['tool_choice'] = ['type' => 'tool', 'name' => self::TOOL_NAME];
        } else {
            $body['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => $schema]];
        }

        return self::passthrough($body, $options, self::PASSTHROUGH);
    }

    protected function extractOutput(array $body, HttpResponse $response): array
    {
        $stopReason = $body['stop_reason'] ?? null;

        if ($stopReason === 'refusal') {
            throw ResponseValidationException::at('stop_reason', 'the model refused to answer', $response, null, self::NAME);
        }

        if ($stopReason === 'max_tokens') {
            throw ResponseValidationException::at('stop_reason', 'output was cut off (max_tokens); raise the max_tokens engine option', $response, null, self::NAME);
        }

        $content = $body['content'] ?? null;

        if (! is_array($content)) {
            throw ResponseValidationException::at('content', 'expected a list of content blocks', $response, null, self::NAME);
        }

        // A forced tool call may be preceded by a short text block; prefer the tool input.
        foreach ($content as $i => $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === self::TOOL_NAME) {
                $input = $block['input'] ?? null;

                if (! is_array($input)) {
                    throw ResponseValidationException::at("content.{$i}.input", 'expected an object', $response, null, self::NAME);
                }

                return $input;
            }
        }

        foreach ($content as $i => $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                return $this->decodeOutput($block['text'], "content.{$i}.text", $response);
            }
        }

        throw ResponseValidationException::at('content', 'no text or record_decision tool_use block found', $response, null, self::NAME);
    }

    protected function extractUsage(array $body): Usage
    {
        $usage = $body['usage'] ?? [];

        return is_array($usage) ? Usage::fromArray(Arr::stringKeys($usage)) : Usage::zero();
    }

    protected function extractMeta(array $body, HttpResponse $response): array
    {
        return Arr::withoutNulls([
            'response_id' => is_string($body['id'] ?? null) ? $body['id'] : null,
            'stop_reason' => is_string($body['stop_reason'] ?? null) ? $body['stop_reason'] : null,
        ]);
    }

    protected function requestIdHeader(): string
    {
        return 'request-id';
    }
}

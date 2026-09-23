<?php

declare(strict_types=1);

namespace Swis\DecisionEngine;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Swis\DecisionEngine\Contracts\Engine;
use Swis\DecisionEngine\Contracts\Transport;
use Swis\DecisionEngine\Engines\EngineManager;
use Swis\DecisionEngine\Engines\Jev\JevEngine;
use Swis\DecisionEngine\Engines\Jev\ModelCard;
use Swis\DecisionEngine\Exceptions\ConfigurationException;
use Swis\DecisionEngine\Exceptions\ResponseValidationException;
use Swis\DecisionEngine\Exceptions\TransportException;
use Swis\DecisionEngine\Outcome\Outcome;
use Swis\DecisionEngine\Questions\Validator;
use Swis\DecisionEngine\Request\DecisionRequest;
use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Request\RequestOptions;
use Swis\DecisionEngine\Testing\DecisionFake;
use Swis\DecisionEngine\Transport\CurlTransport;
use Swis\DecisionEngine\Transport\HttpResponse;
use Swis\DecisionEngine\Transport\Retrier;
use Swis\DecisionEngine\Transport\RetryPolicy;

/**
 * Wires engines, transport, retries, hooks and logging together. `execute()` is the single
 * pipeline every decision goes through, blocking or inside the Fiber scheduler alike:
 *
 * validate → prepare → before hooks → send (with retries) → interpret → after hooks.
 *
 * An interpreted outcome must answer every question; an invalid 2xx response (missing answer,
 * malformed body) is re-asked up to `RetryPolicy::$invalidResponses` times.
 *
 * While `Decision::fake()` is active every client answers from the fake, whatever engine the
 * request names: hooks (and so Laravel's events) still run, nothing is sent.
 *
 * ```php
 * $client = Client::fromConfig(Config::fromEnv());
 * $outcome = $client->execute(DecisionRequest::fromArray($payload));
 * $client->for($state)->noul('urgent', 'Is this urgent?')->decide(); // fluent entry, same pipeline
 * ```
 */
final class Client
{
    private readonly LoggerInterface $logger;

    private readonly Retrier $retrier;

    /**
     * @var \Closure(float): void
     */
    private \Closure $sleeper;

    /**
     * @var list<\Closure(DecisionRequest, PreparedRequest): void>
     */
    private array $beforeHooks = [];

    /**
     * @var list<\Closure(DecisionRequest, Outcome): void>
     */
    private array $afterHooks = [];

    /**
     * @var list<\Closure(DecisionRequest, \Throwable): void>
     */
    private array $failureHooks = [];

    public function __construct(
        private readonly EngineManager $engines,
        private readonly Transport $transport = new CurlTransport(),
        private readonly RequestOptions $defaultOptions = new RequestOptions(),
        private readonly RetryPolicy $retryPolicy = new RetryPolicy(),
        ?LoggerInterface $logger = null,
        private readonly int $concurrency = 10,
        ?Retrier $retrier = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->retrier = $retrier ?? new Retrier();
        $this->sleeper = static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
    }

    public static function fromConfig(Config $config, ?Transport $transport = null, ?LoggerInterface $logger = null): self
    {
        return new self(
            $config->engines(),
            $transport ?? CurlTransport::fromConfig($config->transport),
            new RequestOptions(timeout: $config->timeout(), connectTimeout: $config->connectTimeout()),
            $config->retry,
            $logger,
            $config->concurrency,
        );
    }

    public function engines(): EngineManager
    {
        return $this->engines;
    }

    public function transport(): Transport
    {
        return $this->transport;
    }

    public function concurrency(): int
    {
        return $this->concurrency;
    }

    public function retryPolicy(): RetryPolicy
    {
        return $this->retryPolicy;
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    public function engine(?string $name = null): Engine
    {
        return DecisionFake::active()?->engine() ?? $this->engines->engine($name);
    }

    /**
     * Start a fluent decision bound to this client.
     */
    public function for(mixed $state): Decision
    {
        return Decision::for($state)->withClient($this);
    }

    /**
     * Register a hook that runs after `prepare()` and before anything is sent.
     *
     * @param  \Closure(DecisionRequest, PreparedRequest): void  $hook
     */
    public function before(\Closure $hook): self
    {
        $this->beforeHooks[] = $hook;

        return $this;
    }

    /**
     * @param  \Closure(DecisionRequest, Outcome): void  $hook
     */
    public function after(\Closure $hook): self
    {
        $this->afterHooks[] = $hook;

        return $this;
    }

    /**
     * @param  \Closure(DecisionRequest, \Throwable): void  $hook
     */
    public function onFailure(\Closure $hook): self
    {
        $this->failureHooks[] = $hook;

        return $this;
    }

    /**
     * Replace how retry backoff waits (the Fiber scheduler installs a non-blocking sleeper).
     *
     * @param  \Closure(float): void  $sleeper
     */
    public function withSleeper(\Closure $sleeper): self
    {
        $this->sleeper = $sleeper;

        return $this;
    }

    /**
     * Validate and render the request without sending it (dry run).
     */
    public function prepare(DecisionRequest $request, ?Engine $engine = null): PreparedRequest
    {
        $engine = $this->resolveEngine($request, $engine);

        Validator::validate($request->questions, $engine->capabilities(), $request->state);

        return $engine->prepare($request)->withHeaders($this->options($request)->headers);
    }

    public function execute(DecisionRequest $request, ?Engine $engine = null): Outcome
    {
        $fake = DecisionFake::active();
        $engine = $this->resolveEngine($request, $engine);

        try {
            $prepared = $this->prepare($request, $engine);
            $options = $this->options($request);

            foreach ($this->beforeHooks as $hook) {
                $hook($request, $prepared);
            }

            $start = hrtime(true);
            $outcome = $this->answer($request, $prepared, $options, $engine, $fake);

            if ($outcome->meta->latencyMs === null) {
                $outcome = $outcome->withMeta($outcome->meta->withLatency((hrtime(true) - $start) / 1e6));
            }

            $this->logger->info('Decision made', [
                'engine' => $outcome->engine,
                'model' => $outcome->model,
                'questions' => count($request->questions),
                'latency_ms' => $outcome->meta->latencyMs === null ? null : round($outcome->meta->latencyMs, 1),
                'request_id' => $outcome->meta->requestId,
                'input_tokens' => $outcome->usage->inputTokens,
                'output_tokens' => $outcome->usage->outputTokens,
                'calibrated' => $outcome->meta->calibrated,
            ]);

            $fake?->recordOutcome($outcome);

            foreach ($this->afterHooks as $hook) {
                $hook($request, $outcome);
            }

            return $outcome;
        } catch (\Throwable $e) {
            $this->logger->warning('Decision failed', [
                'engine' => $engine->name(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            foreach ($this->failureHooks as $hook) {
                $hook($request, $e);
            }

            throw $e;
        }
    }

    /**
     * Send and interpret, re-asking when a 2xx response fails validation.
     */
    private function answer(DecisionRequest $request, PreparedRequest $prepared, RequestOptions $options, Engine $engine, ?DecisionFake $fake): Outcome
    {
        $policy = $options->retry ?? $this->retryPolicy;

        for ($attempt = 0; ; $attempt++) {
            $response = $fake === null ? $this->send($prepared, $options) : $fake->respond($request);

            try {
                $outcome = $engine->interpret($request, $response)->withRequest($prepared);

                foreach ($request->questionIds() as $id) {
                    if (! $outcome->has($id)) {
                        throw ResponseValidationException::at("answers.{$id}", "no answer for question [{$id}]", $response, $prepared, $engine->name());
                    }
                }

                return $outcome;
            } catch (ResponseValidationException $e) {
                if ($attempt >= $policy->invalidResponses) {
                    throw $e;
                }

                $this->logger->debug('Retrying after invalid response', ['attempt' => $attempt + 1, 'field' => $e->fieldPath]);
            }
        }
    }

    /**
     * Send with the retry policy applied. Returns the final response (which may still be a non-2xx
     * that is not retryable, or the last retryable one after retries are exhausted).
     *
     * @throws TransportException when the last attempt did not produce a response
     */
    public function send(PreparedRequest $prepared, RequestOptions $options): HttpResponse
    {
        $policy = $options->retry ?? $this->retryPolicy;
        $attempt = 0;

        while (true) {
            try {
                $response = $this->dispatch($prepared, $options);
            } catch (TransportException $e) {
                $delay = $this->retrier->delayFor($attempt, $e, $policy);

                if ($delay === null) {
                    throw $e;
                }

                $this->logger->debug('Retrying after transport error', ['attempt' => $attempt + 1, 'delay_s' => $delay, 'exception' => $e::class]);
                $this->sleep($delay);
                $attempt++;

                continue;
            }

            $delay = $this->retrier->delayFor($attempt, $response, $policy);

            if ($delay === null) {
                return $response;
            }

            $this->logger->debug('Retrying after HTTP status', ['attempt' => $attempt + 1, 'delay_s' => $delay, 'status' => $response->status]);
            $this->sleep($delay);
            $attempt++;
        }
    }

    /**
     * List the models the Jev account can use (`GET /v1/models`).
     *
     * @return list<ModelCard>
     */
    public function models(?string $engineName = null): array
    {
        $engine = $this->engine($engineName);

        if (! $engine instanceof JevEngine) {
            throw ConfigurationException::invalid('engines.' . ($engineName ?? $this->engines->default()), 'only the jev driver exposes a models endpoint');
        }

        $endpoint = $engine->models();

        return $endpoint->interpret($this->send($endpoint->prepare(), $this->defaultOptions));
    }

    /**
     * Effective per-request options: client defaults overridden by the request's own.
     */
    public function options(DecisionRequest $request): RequestOptions
    {
        return $this->defaultOptions->merge($request->options);
    }

    /**
     * The active fake's engine, else the given instance, else the engine the request names.
     */
    private function resolveEngine(DecisionRequest $request, ?Engine $engine): Engine
    {
        return DecisionFake::active()?->engine() ?? $engine ?? $this->engines->engine($request->engine);
    }

    /**
     * Wait for retry backoff: a scheduler timer inside a task, the (replaceable) blocking sleeper otherwise.
     */
    private function sleep(float $seconds): void
    {
        $scheduler = Concurrency\FiberScheduler::current();

        if ($scheduler !== null && \Fiber::getCurrent() !== null) {
            $scheduler->sleep($seconds);

            return;
        }

        ($this->sleeper)($seconds);
    }

    /**
     * One attempt. Routes through the active Fiber scheduler when there is one.
     */
    private function dispatch(PreparedRequest $prepared, RequestOptions $options): HttpResponse
    {
        $scheduler = Concurrency\FiberScheduler::current();

        if ($scheduler !== null) {
            return $scheduler->await($prepared, $options, $this->transport);
        }

        return $this->transport->send($prepared, $options);
    }
}

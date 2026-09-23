<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Testing;

use Swis\DecisionEngine\Client;
use Swis\DecisionEngine\Engines\EngineManager;
use Swis\DecisionEngine\Outcome\Outcome;
use Swis\DecisionEngine\Request\DecisionRequest;
use Swis\DecisionEngine\Request\RequestOptions;
use Swis\DecisionEngine\Transport\HttpResponse;
use Swis\DecisionEngine\Transport\RetryPolicy;

/**
 * While installed, every decision is answered by a FakeEngine and recorded, mirroring Laravel's
 * `Http::fake()` conventions. That covers the static `Decision::…` entry points, the Laravel facade,
 * injected or hand-built `Client`s and every engine name or instance passed to `using()`. Nothing
 * is sent, and client hooks (so Laravel's `Decided` / `DecisionFailed` events) still run.
 *
 * ```php
 * Decision::fake(['department' => Fake::choice('billing', confidence: 0.95)]);
 * Decision::fake(fn (DecisionRequest $r) => ['urgent' => Fake::noul($r->state->isString() ? 0.9 : 0.1)]);
 *
 * Decision::assertDecided(fn (DecisionRequest $r) => $r->hasQuestion('department'));
 * Decision::assertDecidedCount(1);
 * Decision::assertNothingDecided();
 * Decision::restore();
 * ```
 */
final class DecisionFake
{
    private static ?self $active = null;

    private readonly Client $client;

    private readonly FakeEngine $engine;

    /**
     * @var list<DecisionRequest>
     */
    private array $recorded = [];

    /**
     * @var list<Outcome>
     */
    private array $outcomes = [];

    /**
     * @param  array<string, FakeAnswer|array<string, mixed>>|\Closure(DecisionRequest): (array<string, FakeAnswer|array<string, mixed>>|HttpResponse|\Throwable)|null  $answers
     */
    private function __construct(
        private readonly array|\Closure|null $answers,
        ?FakeEngine $engine = null,
    ) {
        $this->engine = $engine ?? new FakeEngine();

        $manager = new EngineManager([]);
        $manager->register(FakeEngine::NAME, $this->engine)->setDefault(FakeEngine::NAME);

        // Only reached once this fake is no longer installed: an active fake answers in Client::execute().
        $transport = new FakeTransport(resolver: static fn(): \Throwable => new \LogicException('This Decision::fake() is no longer active.'));

        $this->client = new Client($manager, $transport, new RequestOptions(), RetryPolicy::none());
    }

    /**
     * @param  array<string, FakeAnswer|array<string, mixed>>|\Closure|null  $answers
     */
    public static function install(array|\Closure|null $answers = null, ?FakeEngine $engine = null): self
    {
        return self::$active = new self($answers, $engine);
    }

    public static function uninstall(): void
    {
        self::$active = null;
    }

    public static function active(): ?self
    {
        return self::$active;
    }

    public static function require(): self
    {
        return self::$active ?? throw new \LogicException('Decision::fake() has not been called.');
    }

    /**
     * A client bound to this fake. Any client is faked while the fake is installed; this one is
     * handy where no real client can be built.
     */
    public function client(): Client
    {
        return $this->client;
    }

    public function engine(): FakeEngine
    {
        return $this->engine;
    }

    /**
     * @return list<DecisionRequest>
     */
    public function recorded(): array
    {
        return $this->recorded;
    }

    /**
     * @return list<Outcome>
     */
    public function outcomes(): array
    {
        return $this->outcomes;
    }

    /**
     * @param  (\Closure(DecisionRequest): bool)|null  $callback
     */
    public function assertDecided(?\Closure $callback = null, ?int $times = null): void
    {
        $matching = $callback === null ? $this->recorded : array_values(array_filter($this->recorded, $callback));
        $count = count($matching);

        if ($times === null) {
            self::assert($count > 0, 'Expected at least one matching decision, none was made.');
        } else {
            self::assert($count === $times, "Expected {$times} matching decision(s), {$count} made.");
        }
    }

    public function assertDecidedCount(int $count): void
    {
        $actual = count($this->recorded);
        self::assert($actual === $count, "Expected {$count} decision(s), {$actual} made.");
    }

    public function assertNothingDecided(): void
    {
        $actual = count($this->recorded);
        self::assert($actual === 0, "Expected no decisions, {$actual} made.");
    }

    /**
     * @param  (\Closure(DecisionRequest): bool)  $callback
     */
    public function assertNotDecided(\Closure $callback): void
    {
        $matching = array_filter($this->recorded, $callback);
        self::assert($matching === [], 'Expected no matching decision, ' . count($matching) . ' made.');
    }

    /**
     * Record the request and produce the fake's response for it. Called by Client::execute().
     *
     * @throws \Throwable when the resolver returns one
     */
    public function respond(DecisionRequest $request): HttpResponse
    {
        $this->recorded[] = $request;

        $answers = $this->answers instanceof \Closure ? ($this->answers)($request) : ($this->answers ?? []);

        if ($answers instanceof \Throwable) {
            throw $answers;
        }

        if ($answers instanceof HttpResponse) {
            return $answers;
        }

        return HttpResponse::jsonResponse(200, $this->engine->respond($request, $answers), ['x-typesafe-request-id' => 'fake_' . count($this->recorded)]);
    }

    /**
     * @internal called by Client::execute() for every successful faked decision
     */
    public function recordOutcome(Outcome $outcome): void
    {
        $this->outcomes[] = $outcome;
    }

    private static function assert(bool $condition, string $message): void
    {
        if (class_exists(\PHPUnit\Framework\Assert::class)) {
            \PHPUnit\Framework\Assert::assertTrue($condition, $message);

            return;
        }

        if (! $condition) {
            throw new \RuntimeException($message);
        }
    }
}

<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Swis\DecisionEngine\Client;
use Swis\DecisionEngine\Concurrency\BatchDecision;
use Swis\DecisionEngine\Decision as FluentDecision;
use Swis\DecisionEngine\Request\DecisionRequest;
use Swis\DecisionEngine\Testing\DecisionFake;
use Swis\DecisionEngine\Testing\FakeAnswer;

/**
 * Facade over the container's Client, with `Http::fake()`-style testing helpers.
 *
 * ```php
 * use Swis\DecisionEngine\Laravel\Facades\Decision;
 *
 * Decision::for($state)->noul('urgent', 'Is this urgent?')->decide();
 * Decision::models();
 *
 * Decision::fake(['urgent' => Fake::noul(0.9)]);
 * Decision::assertDecided(fn (DecisionRequest $r) => $r->hasQuestion('urgent'));
 * ```
 *
 * @method static FluentDecision for(mixed $state)
 * @method static \Swis\DecisionEngine\Outcome\Outcome execute(DecisionRequest $request, ?\Swis\DecisionEngine\Contracts\Engine $engine = null)
 * @method static \Swis\DecisionEngine\Request\PreparedRequest prepare(DecisionRequest $request, ?\Swis\DecisionEngine\Contracts\Engine $engine = null)
 * @method static list<\Swis\DecisionEngine\Engines\Jev\ModelCard> models(?string $engine = null)
 * @method static \Swis\DecisionEngine\Contracts\Engine engine(?string $name = null)
 * @method static \Swis\DecisionEngine\Engines\EngineManager engines()
 *
 * @see Client
 */
final class Decision extends Facade
{
    /**
     * @param  array<string, FakeAnswer|array<string, mixed>>|\Closure|null  $answers
     */
    public static function fake(array|\Closure|null $answers = null): DecisionFake
    {
        return FluentDecision::fake($answers);
    }

    public static function restore(): void
    {
        FluentDecision::restore();
    }

    /**
     * @param  (\Closure(DecisionRequest): bool)|null  $callback
     */
    public static function assertDecided(?\Closure $callback = null, ?int $times = null): void
    {
        DecisionFake::require()->assertDecided($callback, $times);
    }

    public static function assertDecidedCount(int $count): void
    {
        DecisionFake::require()->assertDecidedCount($count);
    }

    public static function assertNothingDecided(): void
    {
        DecisionFake::require()->assertNothingDecided();
    }

    /**
     * @param  \Closure(DecisionRequest): bool  $callback
     */
    public static function assertNotDecided(\Closure $callback): void
    {
        DecisionFake::require()->assertNotDecided($callback);
    }

    /**
     * @return list<DecisionRequest>
     */
    public static function recorded(): array
    {
        return DecisionFake::require()->recorded();
    }

    /**
     * @template K of array-key
     *
     * @param  iterable<K, FluentDecision|\Closure(): mixed>  $tasks
     * @return array<K, mixed>
     */
    public static function parallel(iterable $tasks, ?int $concurrency = null): array
    {
        return FluentDecision::parallel($tasks, $concurrency, self::client());
    }

    /**
     * @template K of array-key
     *
     * @param  iterable<K, FluentDecision|\Closure(): mixed>  $tasks
     * @return array<K, mixed>
     */
    public static function settle(iterable $tasks, ?int $concurrency = null): array
    {
        return FluentDecision::settle($tasks, $concurrency, self::client());
    }

    /**
     * @param  iterable<array-key, mixed>  $states
     * @param  (\Closure(mixed, array-key): mixed)|null  $toState
     */
    public static function forEach(iterable $states, ?\Closure $toState = null): BatchDecision
    {
        return FluentDecision::forEach($states, $toState)->withClient(self::client());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): FluentDecision
    {
        return FluentDecision::fromArray($data)->withClient(self::client());
    }

    public static function fromJson(string $json): FluentDecision
    {
        return FluentDecision::fromJson($json)->withClient(self::client());
    }

    /**
     * Route every proxied call through the fake-aware resolver: the container's Client normally,
     * the fake client after `Decision::fake()`.
     */
    public static function getFacadeRoot(): Client
    {
        return self::client();
    }

    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }

    private static function client(): Client
    {
        return FluentDecision::resolveClient();
    }
}

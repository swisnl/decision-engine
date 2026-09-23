<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Laravel;

use Illuminate\Http\Client\ConnectionException as LaravelConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Swis\DecisionEngine\Contracts\Transport;
use Swis\DecisionEngine\Exceptions\ConnectionException;
use Swis\DecisionEngine\Exceptions\TimeoutException;
use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Request\RequestOptions;
use Swis\DecisionEngine\Transport\HttpResponse;

/**
 * Sends through Laravel's HTTP client so `Http::fake()` intercepts decisions in feature tests.
 * Sequential only: `Decision::parallel()` falls back to one request at a time (with a warning)
 * when this transport is selected. Enable it with `DECISION_ENGINE_TRANSPORT=laravel`.
 */
final class LaravelHttpTransport implements Transport
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly float $timeout = 10.0,
        private readonly float $connectTimeout = 5.0,
    ) {}

    public function send(PreparedRequest $request, RequestOptions $options): HttpResponse
    {
        $timeout = $options->timeout ?? $this->timeout;

        $pending = $this->http
            ->withHeaders($request->headers)
            ->timeout((int) ceil($timeout))
            ->connectTimeout((int) ceil($options->connectTimeout ?? $this->connectTimeout))
            ->withoutRedirecting();

        if ($request->body !== '') {
            $pending = $pending->withBody($request->body, $request->header('Content-Type') ?? 'application/json');
        }

        $start = hrtime(true);

        try {
            $response = $pending->send($request->method, $request->url);
        } catch (LaravelConnectionException $e) {
            if (preg_match('/time(d)? ?out/i', $e->getMessage()) === 1) {
                throw new TimeoutException($e->getMessage(), $timeout, $request, $e);
            }

            throw new ConnectionException($e->getMessage(), $request, $e);
        }

        /** @var array<string, list<string>> $headers */
        $headers = $response->headers();

        return new HttpResponse($response->status(), $headers, $response->body(), (hrtime(true) - $start) / 1e6);
    }
}

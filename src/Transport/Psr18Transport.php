<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Transport;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Swis\DecisionEngine\Contracts\Transport;
use Swis\DecisionEngine\Exceptions\ConnectionException;
use Swis\DecisionEngine\Exceptions\TimeoutException;
use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Request\RequestOptions;

/**
 * Adapter for any PSR-18 client (Guzzle, Symfony HttpClient, …) so you can reuse your own
 * middleware stack. Sequential only. Per-request `timeout` options cannot be forwarded through
 * PSR-18; configure timeouts on the client itself, and make sure it does not throw on HTTP error
 * statuses (Guzzle: `['http_errors' => false]`).
 *
 * ```php
 * $transport = new Psr18Transport(new GuzzleHttp\Client(['http_errors' => false]), new HttpFactory(), new HttpFactory());
 * $client = new Client($engines, $transport);
 * ```
 */
final class Psr18Transport implements Transport
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    public function send(PreparedRequest $request, RequestOptions $options): HttpResponse
    {
        $psrRequest = $this->requestFactory->createRequest($request->method, $request->url);

        foreach ($request->headers as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }

        if ($request->body !== '') {
            $psrRequest = $psrRequest->withBody($this->streamFactory->createStream($request->body));
        }

        $start = hrtime(true);

        try {
            $psrResponse = $this->client->sendRequest($psrRequest);
        } catch (NetworkExceptionInterface $e) {
            if (preg_match('/time(d)? ?out/i', $e->getMessage()) === 1) {
                throw new TimeoutException($e->getMessage(), $options->timeout ?? 0.0, $request, $e);
            }

            throw new ConnectionException($e->getMessage(), $request, $e);
        } catch (ClientExceptionInterface $e) {
            throw new ConnectionException($e->getMessage(), $request, $e);
        }

        /** @var array<string, list<string>> $headers */
        $headers = $psrResponse->getHeaders();

        return new HttpResponse(
            $psrResponse->getStatusCode(),
            $headers,
            (string) $psrResponse->getBody(),
            (hrtime(true) - $start) / 1e6,
        );
    }
}

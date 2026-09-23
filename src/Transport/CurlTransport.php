<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Transport;

use Swis\DecisionEngine\Contracts\ConcurrentTransport;
use Swis\DecisionEngine\Contracts\MultiHandle;
use Swis\DecisionEngine\Exceptions\ConnectionException;
use Swis\DecisionEngine\Exceptions\TimeoutException;
use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Request\RequestOptions;

/**
 * The default transport: ext-curl, zero dependencies, blocking `send()` plus a `curl_multi`
 * handle for the Fiber scheduler.
 *
 * ```php
 * $transport = new CurlTransport(timeout: 10.0, connectTimeout: 5.0);
 * $response = $transport->send($prepared, new RequestOptions());
 * ```
 */
final class CurlTransport implements ConcurrentTransport
{
    public function __construct(
        private readonly float $timeout = 10.0,
        private readonly float $connectTimeout = 5.0,
        private readonly ?string $proxy = null,
        private readonly ?string $caBundle = null,
        private readonly ?string $userAgent = null,
    ) {}

    /**
     * @param  array<string, mixed>  $config  `{timeout, connect_timeout, proxy, ca_bundle, user_agent}`
     */
    public static function fromConfig(array $config): self
    {
        $float = static fn(string $key, float $default): float => is_int($config[$key] ?? null) || is_float($config[$key] ?? null) ? (float) $config[$key] : $default;
        $string = static fn(string $key): ?string => is_string($config[$key] ?? null) ? $config[$key] : null;

        return new self($float('timeout', 10.0), $float('connect_timeout', 5.0), $string('proxy'), $string('ca_bundle'), $string('user_agent'));
    }

    public function send(PreparedRequest $request, RequestOptions $options): HttpResponse
    {
        $handle = $this->handle($request, $options);
        $headers = [];
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, self::headerCollector($headers));

        $start = hrtime(true);
        $body = curl_exec($handle);
        $latencyMs = (hrtime(true) - $start) / 1e6;

        try {
            if ($body === false || $body === true) {
                throw self::exceptionFor(curl_errno($handle), curl_error($handle), $request, $this->effectiveTimeout($options));
            }

            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

            return new HttpResponse(is_int($status) ? $status : 0, $headers, $body, $latencyMs);
        } finally {
            curl_close($handle);
        }
    }

    public function multi(): MultiHandle
    {
        return new CurlMultiHandle($this);
    }

    public function effectiveTimeout(RequestOptions $options): float
    {
        return $options->timeout ?? $this->timeout;
    }

    /**
     * Build a configured easy handle. Public for the multi handle.
     */
    public function handle(PreparedRequest $request, RequestOptions $options): \CurlHandle
    {
        $handle = curl_init();

        if ($handle === false) {
            throw new ConnectionException('Could not initialise curl.', $request);
        }

        $url = $request->url;
        $method = $request->method;

        if ($url === '' || $method === '') {
            throw new ConnectionException('A prepared request needs a non-empty URL and method.', $request);
        }

        $headers = [];

        foreach ($request->headers as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }

        if ($request->header('User-Agent') === null && $this->userAgent !== null) {
            $headers[] = 'User-Agent: ' . $this->userAgent;
        }

        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT_MS => (int) round($this->effectiveTimeout($options) * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round(($options->connectTimeout ?? $this->connectTimeout) * 1000),
            CURLOPT_ENCODING => '',
        ];

        if ($request->body !== '') {
            $curlOptions[CURLOPT_POSTFIELDS] = $request->body;
        }

        if ($this->proxy !== null && $this->proxy !== '') {
            $curlOptions[CURLOPT_PROXY] = $this->proxy;
        }

        if ($this->caBundle !== null && $this->caBundle !== '') {
            $curlOptions[CURLOPT_CAINFO] = $this->caBundle;
        }

        curl_setopt_array($handle, $curlOptions);

        return $handle;
    }

    /**
     * @param  array<string, list<string>>  $headers
     * @return \Closure(\CurlHandle, string): int
     */
    public static function headerCollector(array &$headers): \Closure
    {
        return static function (\CurlHandle $handle, string $line) use (&$headers): int {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, 'HTTP/')) {
                if (str_starts_with($trimmed, 'HTTP/')) {
                    $headers = []; // a new status line (e.g. after 100 Continue) resets the header set
                }

                return strlen($line);
            }

            $parts = explode(':', $trimmed, 2);

            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))][] = trim($parts[1]);
            }

            return strlen($line);
        };
    }

    public static function exceptionFor(int $errno, string $error, PreparedRequest $request, float $timeout): ConnectionException|TimeoutException
    {
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            return new TimeoutException("Request to {$request->url} timed out after {$timeout}s: {$error}", $timeout, $request);
        }

        return new ConnectionException("Request to {$request->url} failed (curl error {$errno}): {$error}", $request);
    }
}

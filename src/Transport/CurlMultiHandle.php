<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Transport;

use Swis\DecisionEngine\Contracts\MultiHandle;
use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Request\RequestOptions;

/**
 * curl_multi wrapper used by the FiberScheduler. Not for direct use.
 */
final class CurlMultiHandle implements MultiHandle
{
    private \CurlMultiHandle $multi;

    private int $nextId = 1;

    /**
     * @var array<int, array{handle: \CurlHandle, request: PreparedRequest, options: RequestOptions, headers: array<string, list<string>>, start: float}>
     */
    private array $inFlight = [];

    public function __construct(private readonly CurlTransport $transport)
    {
        $this->multi = curl_multi_init();
    }

    public function add(PreparedRequest $request, RequestOptions $options): int
    {
        $id = $this->nextId++;
        $handle = $this->transport->handle($request, $options);
        $headers = [];

        $this->inFlight[$id] = ['handle' => $handle, 'request' => $request, 'options' => $options, 'headers' => $headers, 'start' => (float) hrtime(true)];

        curl_setopt($handle, CURLOPT_HEADERFUNCTION, CurlTransport::headerCollector($this->inFlight[$id]['headers']));
        curl_setopt($handle, CURLOPT_PRIVATE, (string) $id);
        curl_multi_add_handle($this->multi, $handle);

        return $id;
    }

    public function tick(float $timeout): array
    {
        if ($this->inFlight === []) {
            return [];
        }

        do {
            $status = curl_multi_exec($this->multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        if ($running > 0) {
            curl_multi_select($this->multi, max(0.0, $timeout));

            do {
                $status = curl_multi_exec($this->multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);
        }

        $completed = [];

        while (($info = curl_multi_info_read($this->multi)) !== false) {
            $handle = $info['handle'] ?? null;
            $result = $info['result'] ?? CURLE_OK;

            if (! $handle instanceof \CurlHandle || ! is_int($result)) {
                continue;
            }

            $private = curl_getinfo($handle, CURLINFO_PRIVATE);
            $id = is_string($private) ? (int) $private : 0;
            $entry = $this->inFlight[$id] ?? null;

            curl_multi_remove_handle($this->multi, $handle);

            if ($entry === null) {
                continue;
            }

            unset($this->inFlight[$id]);
            $latencyMs = ((float) hrtime(true) - $entry['start']) / 1e6;

            if ($result !== CURLE_OK) {
                $completed[] = [$id, CurlTransport::exceptionFor($result, curl_error($handle), $entry['request'], $this->transport->effectiveTimeout($entry['options']))];
            } else {
                $body = curl_multi_getcontent($handle);
                $code = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $completed[] = [$id, new HttpResponse(is_int($code) ? $code : 0, $entry['headers'], $body ?? '', $latencyMs)];
            }

            curl_close($handle);
        }

        return $completed;
    }

    public function inFlight(): int
    {
        return count($this->inFlight);
    }

    public function close(): void
    {
        foreach ($this->inFlight as $entry) {
            curl_multi_remove_handle($this->multi, $entry['handle']);
            curl_close($entry['handle']);
        }

        $this->inFlight = [];
        curl_multi_close($this->multi);
    }

    public function __destruct()
    {
        $this->close();
    }
}

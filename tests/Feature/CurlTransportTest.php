<?php

declare(strict_types=1);

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Swis\DecisionEngine\Exceptions\ConnectionException;
use Swis\DecisionEngine\Exceptions\TimeoutException;
use Swis\DecisionEngine\Request\PreparedRequest;
use Swis\DecisionEngine\Request\RequestOptions;
use Swis\DecisionEngine\Tests\Support\LocalServer;
use Swis\DecisionEngine\Transport\CurlTransport;
use Swis\DecisionEngine\Transport\Psr18Transport;

beforeAll(function (): void {
    $GLOBALS['__server'] = LocalServer::start();
});

afterAll(function (): void {
    $GLOBALS['__server']->stop();
});

function server(): LocalServer
{
    return $GLOBALS['__server'];
}

it('sends JSON with headers and reads status, headers, body and latency', function (): void {
    $transport = new CurlTransport(timeout: 5.0);
    $request = PreparedRequest::jsonRequest('POST', server()->baseUrl . '/?echo=1', ['Authorization' => 'Bearer k', 'X-Custom' => 'v'], ['a' => 1]);

    $response = $transport->send($request, new RequestOptions());
    $echo = $response->json();

    expect($response->status)->toBe(200)
        ->and($response->header('content-type'))->toBe('application/json')
        ->and($response->header('x-typesafe-request-id'))->toStartWith('req_')
        ->and($response->latencyMs)->toBeGreaterThan(0.0)
        ->and($echo['method'])->toBe('POST')
        ->and($echo['body'])->toBe(['a' => 1])
        ->and($echo['headers']['authorization'])->toBe('Bearer k')
        ->and($echo['headers']['x-custom'])->toBe('v')
        ->and($echo['headers']['content-type'])->toBe('application/json');
});

it('returns error statuses as responses, never as exceptions', function (): void {
    $response = (new CurlTransport())->send(new PreparedRequest('GET', server()->url(503, ['message' => 'down'], headers: ['Retry-After' => '2']), [], ''), new RequestOptions());

    expect($response->status)->toBe(503)
        ->and($response->json())->toBe(['message' => 'down'])
        ->and($response->header('retry-after'))->toBe('2');
});

it('throws TimeoutException when the per-attempt timeout elapses', function (): void {
    $transport = new CurlTransport(timeout: 5.0);

    expect(fn() => $transport->send(new PreparedRequest('GET', server()->url(200, [], delayMs: 600), [], ''), new RequestOptions(timeout: 0.2)))
        ->toThrow(TimeoutException::class);

    try {
        $transport->send(new PreparedRequest('GET', server()->url(200, [], delayMs: 600), [], ''), new RequestOptions(timeout: 0.2));
    } catch (TimeoutException $e) {
        expect($e->timeout)->toBe(0.2)->and($e->request?->url)->toContain('delay_ms=600');
    }
});

it('throws ConnectionException when nothing listens', function (): void {
    $transport = new CurlTransport(connectTimeout: 1.0);

    expect(fn() => $transport->send(new PreparedRequest('GET', 'http://127.0.0.1:9/', [], ''), new RequestOptions()))->toThrow(ConnectionException::class);
});

it('builds from config', function (): void {
    $transport = CurlTransport::fromConfig(['timeout' => 1, 'connect_timeout' => 0.5, 'user_agent' => 'x']);

    expect($transport->effectiveTimeout(new RequestOptions()))->toBe(1.0)
        ->and($transport->effectiveTimeout(new RequestOptions(timeout: 3.0)))->toBe(3.0);
});

describe('Psr18Transport', function (): void {
    function psr18(MockHandler $mock): Psr18Transport
    {
        $factory = new HttpFactory();

        return new Psr18Transport(new Guzzle(['handler' => HandlerStack::create($mock), 'http_errors' => false]), $factory, $factory);
    }

    it('adapts requests and responses', function (): void {
        $mock = new MockHandler([new GuzzleResponse(429, ['Retry-After' => '1', 'X-Multi' => ['a', 'b']], '{"ok":false}')]);
        $response = psr18($mock)->send(PreparedRequest::jsonRequest('POST', 'https://example.test/x', ['Authorization' => 'Bearer k'], ['q' => 1]), new RequestOptions());
        $sent = $mock->getLastRequest();

        expect($response->status)->toBe(429)
            ->and($response->header('retry-after'))->toBe('1')
            ->and($response->headerValues('x-multi'))->toBe(['a', 'b'])
            ->and($response->json())->toBe(['ok' => false])
            ->and($response->latencyMs)->toBeGreaterThanOrEqual(0.0)
            ->and($sent?->getMethod())->toBe('POST')
            ->and($sent?->getHeaderLine('Authorization'))->toBe('Bearer k')
            ->and($sent?->getHeaderLine('Content-Type'))->toBe('application/json')
            ->and((string) $sent?->getBody())->toBe('{"q":1}');
    });

    it('maps network exceptions to connection / timeout exceptions', function (): void {
        $timeout = psr18(new MockHandler([new ConnectException('cURL error 28: Operation timed out', new GuzzleRequest('GET', 'x'))]));
        $refused = psr18(new MockHandler([new ConnectException('Connection refused', new GuzzleRequest('GET', 'x'))]));
        $request = new PreparedRequest('GET', 'https://example.test/', [], '');

        expect(fn() => $timeout->send($request, new RequestOptions(timeout: 2.0)))->toThrow(TimeoutException::class)
            ->and(fn() => $refused->send($request, new RequestOptions()))->toThrow(ConnectionException::class);
    });
});

it('runs the actual curl multi handle against the local server', function (): void {
    $transport = new CurlTransport();
    $multi = $transport->multi();

    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $ids[] = $multi->add(new PreparedRequest('GET', server()->url(200, ['i' => $i], delayMs: 150), [], ''), new RequestOptions());
    }
    $ids[] = $multi->add(new PreparedRequest('GET', server()->url(200, [], delayMs: 2000), [], ''), new RequestOptions(timeout: 0.3));

    $start = microtime(true);
    $done = [];
    while ($multi->inFlight() > 0 && microtime(true) - $start < 5) {
        foreach ($multi->tick(0.05) as [$id, $result]) {
            $done[$id] = $result;
        }
    }
    $elapsed = microtime(true) - $start;

    expect(array_keys($done))->toEqualCanonicalizing($ids)
        ->and($done[$ids[0]]->json())->toBe(['i' => 0])
        ->and($done[$ids[5]])->toBeInstanceOf(TimeoutException::class)
        ->and($elapsed)->toBeLessThan(1.5); // 5 × 150 ms in parallel plus a 300 ms timeout, not 2.75 s sequential

    $multi->close();
});

it('drives the Fiber scheduler over real curl_multi: 20 × 200 ms at concurrency 10 ≈ 400 ms', function (): void {
    $transport = new CurlTransport();
    $engines = Swis\DecisionEngine\Engines\EngineManager::fromArray(['engines' => ['jev' => ['driver' => 'jev', 'api_key' => 'k', 'base_url' => server()->baseUrl]]]);
    $client = new Swis\DecisionEngine\Client($engines, $transport, new RequestOptions(), Swis\DecisionEngine\Transport\RetryPolicy::none());

    $body = ['model' => 'jev-1.13.0', 'answers' => ['urgent' => ['type' => 'noul', 'noul' => 0.7]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]];

    // The Jev engine posts to {base_url}/v1/systemone; the local router ignores the path and reads the query string,
    // so point base_url at a URL that already carries the canned response parameters.
    $engines->configure('local', ['driver' => 'jev', 'api_key' => 'k', 'base_url' => server()->url(200, $body, delayMs: 200) . '&path=']);

    $decisions = [];
    for ($i = 0; $i < 20; $i++) {
        $decisions[$i] = Swis\DecisionEngine\Decision::for("s{$i}")->withClient($client)->using('local')->noul('urgent', 'q');
    }

    $start = microtime(true);
    $outcomes = Swis\DecisionEngine\Decision::parallel($decisions, concurrency: 10, client: $client);
    $elapsed = microtime(true) - $start;

    expect($outcomes)->toHaveCount(20)
        ->and($outcomes[19]->urgent->noul)->toBe(0.7)
        ->and($outcomes[0]->meta->requestId)->toStartWith('req_')
        ->and($elapsed)->toBeGreaterThan(0.35)->toBeLessThan(1.5);
});

<?php

declare(strict_types=1);

namespace Swis\DecisionEngine\Tests\Support;

/**
 * Starts PHP's built-in web server on a free port with tests/Support/server.php as router.
 * The router understands query parameters: status, delay_ms, body (json), header_* .
 */
final class LocalServer
{
    /** @var resource|null */
    private $process = null;

    public readonly string $baseUrl;

    private function __construct(public readonly int $port)
    {
        $this->baseUrl = "http://127.0.0.1:{$port}";
    }

    public static function start(): self
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        if ($socket === false) {
            throw new \RuntimeException('Could not allocate a port');
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);

        $server = new self($port);
        $command = sprintf('PHP_CLI_SERVER_WORKERS=32 %s -S 127.0.0.1:%d -t %s %s', escapeshellarg(PHP_BINARY), $port, escapeshellarg(__DIR__), escapeshellarg(__DIR__ . '/server.php'));
        $server->process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes) ?: null;

        if ($server->process === null) {
            throw new \RuntimeException('Could not start the PHP built-in server');
        }

        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);

            if ($probe !== false) {
                fclose($probe);

                return $server;
            }

            usleep(20_000);
        }

        throw new \RuntimeException('PHP built-in server did not come up');
    }

    /**
     * URL that returns the given JSON body with status and optional delay/headers.
     *
     * @param  array<string, mixed>  $headers
     */
    public function url(int $status = 200, mixed $body = null, int $delayMs = 0, array $headers = []): string
    {
        $query = ['status' => $status, 'delay_ms' => $delayMs];

        if ($body !== null) {
            $query['body'] = json_encode($body, JSON_THROW_ON_ERROR);
        }

        foreach ($headers as $name => $value) {
            $query['header_' . $name] = $value;
        }

        return $this->baseUrl . '/?' . http_build_query($query);
    }

    public function stop(): void
    {
        if ($this->process !== null) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}

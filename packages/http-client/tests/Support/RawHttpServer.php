<?php

declare(strict_types=1);

namespace Waaseyaa\HttpClient\Tests\Support;

use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

/**
 * One-shot TCP HTTP server that writes an exact response and closes.
 */
final class RawHttpServer
{
    private const HOST = '127.0.0.1';

    private readonly Process $process;

    public readonly int $port;

    public function __construct(string $httpResponse, int $splitAt = 0, int $delayUs = 0)
    {
        $this->port = self::freePort();
        $this->process = new Process(
            [PHP_BINARY, __DIR__ . '/raw-http-server.php'],
            null,
            [
                'WAASEYAA_RAW_LISTEN' => self::HOST . ':' . $this->port,
                'WAASEYAA_RAW_HTTP' => $httpResponse,
                'WAASEYAA_RAW_SPLIT_AT' => (string) $splitAt,
                'WAASEYAA_RAW_DELAY_US' => (string) $delayUs,
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            ],
            null,
            null,
        );

        try {
            $this->process->start();
        } catch (ProcessStartFailedException $exception) {
            throw new \RuntimeException('Could not start raw HTTP server.', 0, $exception);
        }

        for ($i = 0; $i < 100; $i++) {
            if (str_contains($this->process->getErrorOutput(), 'ready')) {
                return;
            }
            usleep(50_000);
        }

        $this->stop();
        throw new \RuntimeException("Raw HTTP server never became ready on port {$this->port}.");
    }

    public function baseUrl(): string
    {
        return 'http://' . self::HOST . ':' . $this->port;
    }

    public function stop(): void
    {
        if ($this->process->isRunning()) {
            $this->process->stop();
        }
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://' . self::HOST . ':0', $errno, $errstr);
        if ($socket === false) {
            throw new \RuntimeException("Could not allocate a free port: {$errstr}");
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}

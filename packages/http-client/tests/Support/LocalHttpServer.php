<?php

declare(strict_types=1);

namespace Waaseyaa\HttpClient\Tests\Support;

use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

/**
 * A throwaway `php -S` HTTP server for transport-level tests.
 *
 * Each instance binds an ephemeral port, runs {@see server-router.php}, and
 * records every request it receives so a test can assert exactly which host saw
 * which headers (e.g. that a cross-host redirect target is never contacted).
 */
final class LocalHttpServer
{
    private const HOST = '127.0.0.1';

    private readonly Process $process;

    private readonly string $logFile;

    public readonly int $port;

    public function __construct()
    {
        $this->port = self::freePort();

        $logFile = tempnam(sys_get_temp_dir(), 'waaseyaa_http_srv_');
        if ($logFile === false) {
            throw new \RuntimeException('Could not create server request-log file.');
        }
        $this->logFile = $logFile;
        file_put_contents($this->logFile, '');

        // cwd null and timeout null match the previous proc_open call: the child
        // inherited the caller's cwd and was never time-bounded. The server is
        // long-lived, so Symfony's 60s default timeout must not apply.
        $this->process = new Process(
            [PHP_BINARY, '-S', self::HOST . ':' . $this->port, __DIR__ . '/server-router.php'],
            null,
            self::replacingEnv([
                'WAASEYAA_TEST_LOG' => $this->logFile,
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            ]),
            null,
            null,
        );

        try {
            $this->process->start();
        } catch (ProcessStartFailedException $e) {
            @unlink($this->logFile);
            throw new \RuntimeException('Could not start php -S server.', 0, $e);
        }

        $this->waitUntilReady();
    }

    public function baseUrl(): string
    {
        return 'http://' . self::HOST . ':' . $this->port;
    }

    /**
     * @return list<array{method: string, uri: string, authorization: string}>
     */
    public function requests(): array
    {
        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        return array_values(array_map(
            static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines,
        ));
    }

    public function stop(): void
    {
        if ($this->process->isRunning()) {
            $this->process->stop();
        }
        if (is_file($this->logFile)) {
            @unlink($this->logFile);
        }
    }

    /**
     * Reproduce proc_open's environment-REPLACEMENT semantics.
     *
     * proc_open handed an explicit env array gave this `php -S` child exactly
     * those two variables. Symfony Process instead MERGES the array onto the
     * inherited environment (`$env += $this->getDefaultEnv()` in
     * Process::start()), which would hand the router the whole suite
     * environment — APP_ENV, APP_DEBUG, WAASEYAA_DB and friends included.
     * Symfony drops any variable whose value is false, so every inherited name
     * the caller did not set is pinned to false.
     *
     * @param  array<string, string> $explicit
     * @return array<string, string|false>
     */
    private static function replacingEnv(array $explicit): array
    {
        $env = $explicit;
        foreach (array_keys($_ENV + getenv()) as $name) {
            if (!array_key_exists((string) $name, $env)) {
                $env[(string) $name] = false;
            }
        }

        return $env;
    }

    private function waitUntilReady(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $conn = @fsockopen(self::HOST, $this->port, $errno, $errstr, 0.1);
            if (is_resource($conn)) {
                fclose($conn);
                return;
            }
            usleep(50_000); // 50ms — up to ~5s total
        }

        $this->stop();
        throw new \RuntimeException("Local HTTP server never became ready on port {$this->port}.");
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

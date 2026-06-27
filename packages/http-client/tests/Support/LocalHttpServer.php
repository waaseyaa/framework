<?php

declare(strict_types=1);

namespace Waaseyaa\HttpClient\Tests\Support;

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

    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

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

        $process = proc_open(
            [PHP_BINARY, '-S', self::HOST . ':' . $this->port, __DIR__ . '/server-router.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes,
            null,
            ['WAASEYAA_TEST_LOG' => $this->logFile, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        );

        if (!is_resource($process)) {
            @unlink($this->logFile);
            throw new \RuntimeException('Could not start php -S server.');
        }
        $this->process = $process;
        foreach ([1, 2] as $i) {
            if (isset($this->pipes[$i]) && is_resource($this->pipes[$i])) {
                stream_set_blocking($this->pipes[$i], false);
            }
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
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->process);
        }
        if (is_file($this->logFile)) {
            @unlink($this->logFile);
        }
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

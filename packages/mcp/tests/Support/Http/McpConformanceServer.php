<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Support\Http;

use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

/**
 * A throwaway `php -S` server exposing the public MCP tier on a real socket.
 *
 * The harness half of the wire-conformance suite: {@see RawJsonRpcClient} is the
 * client half. Together they satisfy #2520's requirement that the anonymous
 * tier be driven "over real HTTP with a generic client rather than by direct
 * tool invocation" — every byte asserted has been through `json_encode`, a
 * socket, and a header parser.
 *
 * Environment contract:
 *  - Every precondition failure raises {@see McpServerUnavailable} naming what
 *    was missing, so callers skip rather than fail.
 *  - Every wait is bounded: port allocation retries a fixed number of times and
 *    readiness gives up after {@see READY_TIMEOUT_SECONDS}.
 *  - {@see stop()} is idempotent and safe when startup never succeeded.
 *
 * `WAASEYAA_MCP_CONFORMANCE_ROUTER` overrides the router script. That is how a
 * regression proof is run: point the harness at a bootstrap that defines a
 * pre-fix `McpEndpoint` before the autoloader can, and the same assertions
 * report on the old bytes.
 */
final class McpConformanceServer
{
    private const string HOST = '127.0.0.1';

    /** Bounded readiness wait: at most 100 attempts × 100ms, and never past the deadline. */
    private const int READY_ATTEMPTS = 100;
    private const int READY_INTERVAL_MICROSECONDS = 100_000;
    private const float READY_TIMEOUT_SECONDS = 10.0;

    /** A free port can be taken between allocation and bind; retry, then give up. */
    private const int PORT_ATTEMPTS = 3;

    private ?Process $process = null;

    private int $port = 0;

    private function __construct() {}

    /** @throws McpServerUnavailable when the environment cannot host the server. */
    public static function start(): self
    {
        $php = self::phpBinary();
        $router = self::routerScript();
        $root = self::repositoryRoot();

        $server = new self();
        $failures = [];

        for ($attempt = 0; $attempt < self::PORT_ATTEMPTS; $attempt++) {
            $server->port = self::freePort();
            $server->process = new Process(
                [$php, '-S', self::HOST . ':' . $server->port, $router],
                null,
                self::replacingEnv([
                    'PATH' => \getenv('PATH') === false ? '/usr/bin:/bin' : (string) \getenv('PATH'),
                    'WAASEYAA_MCP_CONFORMANCE_ROOT' => $root,
                ]),
                null,
                null,
            );

            try {
                $server->process->start();
            } catch (ProcessStartFailedException $e) {
                $server->stop();

                throw new McpServerUnavailable('could not start `php -S`: ' . $e->getMessage(), previous: $e);
            }

            try {
                $server->waitUntilReady();

                return $server;
            } catch (McpServerUnavailable $e) {
                $failures[] = $e->getMessage();
                $server->stop();

                // A broken bootstrap answers just as fast on the next port.
                // Only a lost port race earns another attempt.
                if (!$e->retryable) {
                    throw new McpServerUnavailable(\implode(' | ', $failures));
                }
            }
        }

        throw new McpServerUnavailable(\sprintf(
            'the MCP conformance server never became ready in %d attempts: %s',
            self::PORT_ATTEMPTS,
            \implode(' | ', $failures),
        ));
    }

    public function client(): RawJsonRpcClient
    {
        return new RawJsonRpcClient(self::HOST, $this->port);
    }

    /** Whatever the server wrote to stderr — the `php -S` request log and any fatal. */
    public function diagnostics(): string
    {
        if ($this->process === null) {
            return '';
        }

        return \substr($this->process->getErrorOutput() . $this->process->getOutput(), -2000);
    }

    /** Idempotent, and a no-op when startup never got as far as a process. */
    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }

        // Process::stop() sends SIGTERM, waits, then SIGKILLs — bounded either
        // way, and it is what keeps a failed test from leaving an orphan.
        if ($this->process->isRunning()) {
            $this->process->stop(1.0);
        }
        $this->process = null;
    }

    /** The destructor is the backstop for a test that dies before tearDown. */
    public function __destruct()
    {
        $this->stop();
    }

    private function waitUntilReady(): void
    {
        $deadline = \microtime(true) + self::READY_TIMEOUT_SECONDS;

        for ($attempt = 0; $attempt < self::READY_ATTEMPTS; $attempt++) {
            if (\microtime(true) >= $deadline) {
                break;
            }
            if ($this->process !== null && !$this->process->isRunning()) {
                // `php -S` exits at bind when the allocated port was taken
                // between allocation and start — the one retryable failure.
                throw new McpServerUnavailable(
                    'the server process exited during startup: ' . $this->diagnostics(),
                    retryable: true,
                );
            }

            $errno = 0;
            $error = '';
            $connection = @\fsockopen(self::HOST, $this->port, $errno, $error, 0.2);
            if (\is_resource($connection)) {
                \fclose($connection);
                $this->requireApplicationAnswers();

                return;
            }

            \usleep(self::READY_INTERVAL_MICROSECONDS);
        }

        throw new McpServerUnavailable(\sprintf(
            'port %d never accepted a connection within %.1fs %s',
            $this->port,
            self::READY_TIMEOUT_SECONDS,
            $this->diagnostics(),
        ));
    }

    /**
     * Readiness is "the application answers", not "the port is bound":
     * `/__ready` constructs the endpoint before answering 204, so a bound port
     * over a broken bootstrap never reads as ready. Once the port accepts, one
     * request settles it — a retry cannot un-break a bootstrap.
     */
    private function requireApplicationAnswers(): void
    {
        $response = new RawJsonRpcClient(self::HOST, $this->port, 2.0)->get('/__ready');
        if ($response->statusCode !== 204) {
            throw new McpServerUnavailable(\sprintf(
                'the readiness probe answered %d, not 204: %s %s',
                $response->statusCode,
                $response->rawBody,
                $this->diagnostics(),
            ));
        }
    }

    private static function phpBinary(): string
    {
        if (PHP_BINARY === '' || !\is_executable(PHP_BINARY)) {
            throw new McpServerUnavailable('no resolvable PHP binary (PHP_BINARY is not executable)');
        }

        return PHP_BINARY;
    }

    private static function routerScript(): string
    {
        $override = \getenv('WAASEYAA_MCP_CONFORMANCE_ROUTER');
        $router = \is_string($override) && $override !== ''
            ? $override
            : __DIR__ . '/mcp-conformance-router.php';

        if (!\is_file($router)) {
            throw new McpServerUnavailable('the router script is missing: ' . $router);
        }

        return $router;
    }

    private static function repositoryRoot(): string
    {
        $root = \dirname(__DIR__, 5);
        if (!\is_file($root . '/vendor/autoload.php')) {
            throw new McpServerUnavailable('no Composer autoloader under ' . $root);
        }

        return $root;
    }

    private static function freePort(): int
    {
        $errno = 0;
        $error = '';
        $socket = @\stream_socket_server('tcp://' . self::HOST . ':0', $errno, $error);
        if (!\is_resource($socket)) {
            throw new McpServerUnavailable(\sprintf('no free port on %s (%d %s)', self::HOST, $errno, $error));
        }

        $name = (string) \stream_socket_get_name($socket, false);
        \fclose($socket);

        return (int) \substr($name, (int) \strrpos($name, ':') + 1);
    }

    /**
     * proc_open's environment-REPLACEMENT semantics, which Symfony Process does
     * not have (it merges onto the inherited environment). Without this the
     * server would inherit the suite's APP_ENV / APP_DEBUG / WAASEYAA_DB and
     * stop being a clean-room. Symfony drops any variable whose value is false.
     *
     * @param  array<string, string> $explicit
     * @return array<string, string|false>
     */
    private static function replacingEnv(array $explicit): array
    {
        /** @var array<string, string|false> $env */
        $env = $explicit;
        foreach (\array_keys($_ENV + \getenv()) as $name) {
            if (!\array_key_exists((string) $name, $env)) {
                $env[(string) $name] = false;
            }
        }

        return $env;
    }
}

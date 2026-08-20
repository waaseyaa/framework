<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Support;

/**
 * A local TCP peer that reproduces the ways an upstream provider pins a worker:
 * accepting a connection and never finishing the handshake, answering with SSE
 * headers and then going silent mid-stream, and — for a provider with no stream
 * — promising a response body and then delivering only part of it.
 *
 * Deliberately not an HTTP server — `php -S` always answers, and the point of
 * these fixtures is a peer that does not.
 */
final class StallingTransportServer
{
    private const HOST = '127.0.0.1';

    /** Accept the connection and never write a byte. */
    public const MODE_SILENT = 'silent';

    /** Send SSE headers plus one text delta, then stop sending forever. */
    public const MODE_STALL = 'stall';

    /** Send a complete, well-formed SSE response and close. */
    public const MODE_SSE = 'sse';

    /** Send a complete non-streaming JSON response (Anthropic shape) and close. */
    public const MODE_JSON = 'json';

    /** Send a complete OpenAI chat-completion response and close. */
    public const MODE_CHAT = 'chat';

    /**
     * Send chat-completion headers promising a full body, deliver a prefix of
     * it, then stop sending forever. The non-streaming stall: no chunk callback
     * exists to notice, so only a transport bound can end it.
     */
    public const MODE_CHAT_STALL = 'chat-stall';

    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    public readonly int $port;

    public function __construct(string $mode, float $lifetimeSeconds = 40.0)
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/stalling-transport-peer.php', $mode, (string) $lifetimeSeconds],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes,
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start the stalling transport peer.');
        }
        $this->process = $process;
        stream_set_blocking($this->pipes[2], false);

        $this->port = $this->awaitAnnouncedPort();
    }

    /** Plain-HTTP base URL: the connection phase completes, the transfer stalls. */
    public function baseUrl(): string
    {
        return 'http://' . self::HOST . ':' . $this->port;
    }

    /** TLS base URL against a peer that never answers the handshake: the connection phase stalls. */
    public function tlsBaseUrl(): string
    {
        return 'https://' . self::HOST . ':' . $this->port;
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
    }

    /**
     * Block until the peer announces the port it bound. Reading the port back
     * from the child (rather than choosing one for it) is what makes the fixture
     * deterministic: the test can only ever talk to this peer.
     */
    private function awaitAnnouncedPort(): int
    {
        stream_set_blocking($this->pipes[1], false);
        $deadline = microtime(true) + 10.0;
        $announcement = '';

        while (microtime(true) < $deadline) {
            $announcement .= (string) fread($this->pipes[1], 64);
            if (preg_match('/^READY (\d+)\n/', $announcement, $matches) === 1) {
                return (int) $matches[1];
            }

            if (feof($this->pipes[1])) {
                break;
            }
            usleep(10_000);
        }

        $diagnostic = trim((string) stream_get_contents($this->pipes[2]));
        $this->stop();

        throw new \RuntimeException('Stalling transport peer never announced a port. ' . $diagnostic);
    }
}

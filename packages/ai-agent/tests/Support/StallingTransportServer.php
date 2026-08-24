<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Support;

use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

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

    private readonly Process $process;

    public readonly int $port;

    public function __construct(string $mode, float $lifetimeSeconds = 40.0)
    {
        // The previous proc_open call passed neither cwd nor env, so both are
        // null here; Symfony hands the child $_ENV plus getenv() filtered
        // through $_SERVER, and the peer reads no environment at all. timeout
        // null is required — there was no parent-side time bound before, and
        // Symfony's 60s default would abort the very stalls this fixture exists
        // to produce. The peer bounds itself via $lifetimeSeconds.
        $this->process = new Process(
            [PHP_BINARY, __DIR__ . '/stalling-transport-peer.php', $mode, (string) $lifetimeSeconds],
            null,
            null,
            null,
            null,
        );

        try {
            $this->process->start();
        } catch (ProcessStartFailedException $e) {
            throw new \RuntimeException('Could not start the stalling transport peer.', 0, $e);
        }

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
        if ($this->process->isRunning()) {
            $this->process->stop();
        }
    }

    /**
     * Block until the peer announces the port it bound. Reading the port back
     * from the child (rather than choosing one for it) is what makes the fixture
     * deterministic: the test can only ever talk to this peer.
     */
    private function awaitAnnouncedPort(): int
    {
        $deadline = microtime(true) + 10.0;
        $announcement = '';

        while (microtime(true) < $deadline) {
            $announcement .= $this->process->getIncrementalOutput();
            if (preg_match('/^READY (\d+)\n/', $announcement, $matches) === 1) {
                return (int) $matches[1];
            }

            if (!$this->process->isRunning()) {
                // The peer can announce and exit between the read above and this
                // liveness check; drain once more before giving up so a
                // fast-exiting child is not misreported as never having
                // announced. This replaces the previous feof() break.
                $announcement .= $this->process->getIncrementalOutput();
                if (preg_match('/^READY (\d+)\n/', $announcement, $matches) === 1) {
                    return (int) $matches[1];
                }

                break;
            }
            usleep(10_000);
        }

        $diagnostic = trim($this->process->getErrorOutput());
        $this->stop();

        throw new \RuntimeException('Stalling transport peer never announced a port. ' . $diagnostic);
    }
}

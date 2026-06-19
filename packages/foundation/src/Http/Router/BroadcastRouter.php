<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

final class BroadcastRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    /**
     * Hard cap on a single SSE connection's lifetime. When it elapses the
     * stream handler returns, releasing the worker; the browser's EventSource
     * auto-reconnects (resuming from `Last-Event-ID`, so no events are missed).
     * This is the durable guarantee that a worker is never held indefinitely —
     * even if the SAPI never reports the client disconnect (a known risk under
     * FrankenPHP worker mode, where the request loop sets ignore_user_abort).
     */
    public const int DEFAULT_MAX_DURATION_SEC = 30;

    /**
     * Keepalive cadence. Kept short so the loop attempts a write to the client
     * frequently — a failed write is what flips connection_aborted() to 1, so a
     * short cadence is what makes disconnect detection (and worker release)
     * prompt after the client navigates away.
     */
    public const int DEFAULT_KEEPALIVE_INTERVAL_SEC = 2;

    /** Pause between broadcast polls. */
    public const int DEFAULT_POLL_INTERVAL_US = 500_000;

    /**
     * @param (\Closure(): int)|null $clock       Override `time()` (seconds) — tests inject a fake clock.
     * @param (\Closure(): int)|null $abortSignal Override `connection_aborted()` — tests inject disconnect.
     */
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        private readonly ?string $subscribersJsonPath = null,
        private readonly int $maxDurationSec = self::DEFAULT_MAX_DURATION_SEC,
        private readonly int $keepaliveIntervalSec = self::DEFAULT_KEEPALIVE_INTERVAL_SEC,
        private readonly int $pollIntervalUs = self::DEFAULT_POLL_INTERVAL_US,
        private readonly ?\Closure $clock = null,
        private readonly ?\Closure $abortSignal = null,
    ) {}

    /**
     * Loop-continuation predicate for the SSE stream: keep streaming only while
     * the client is connected AND the per-connection time budget remains. Pure
     * and static so the bounded-exit contract is unit-testable without a live
     * socket. Exiting on either condition releases the worker; the client
     * reconnects automatically.
     */
    public static function streamShouldContinue(int $abortStatus, int $elapsedSec, int $maxDurationSec): bool
    {
        return $abortStatus === 0 && $elapsedSec < $maxDurationSec;
    }

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_controller', '') === 'broadcast';
    }

    public function handle(Request $request): Response
    {
        $ctx = WaaseyaaContext::fromRequest($request);
        $broadcastStorage = $ctx->broadcastStorage;
        // Strip any client-supplied private `session:*` channel and auto-subscribe
        // this connection to its OWN session channel (derived server-side). A
        // client can therefore never receive another session's private messages,
        // regardless of the channels it requests (Wayfinding NFR-001).
        $requested = self::parseChannels($ctx->query['channels'] ?? 'admin');
        $ownSessionChannel = self::ownSessionChannel($request);
        $channels = self::resolveSubscriberChannels($requested, $ownSessionChannel);
        $sessionToken = $ownSessionChannel === null
            ? null
            : substr($ownSessionChannel, strlen(SessionChannel::PREFIX));
        $logger = $this->logger ?? new NullLogger();

        $initialCursor = self::resolveInitialCursor($request, $broadcastStorage->maxId($channels));

        // Subscriber tracking (M5D WP01 — additive extension).
        // Build a stable, non-secret connectionId from timing + PID.
        $connectedSince = microtime(true);
        $connectionId = substr(hash('sha256', $connectedSince . ':' . getmypid()), 0, 16);

        // Resolve the account from the request attribute set by SessionMiddleware.
        // Falls back to accountId=0 (anonymous) when no account is present.
        $account = $request->attributes->get('_account');
        $accountId = 0;
        $accountLabel = null;
        if (is_object($account) && method_exists($account, 'id')) {
            $accountId = (int) $account->id();
        }
        if (is_object($account) && method_exists($account, 'label')) {
            $accountLabel = (string) $account->label();
            if ($accountLabel === '') {
                $accountLabel = null;
            }
        }

        $subscribersPath = $this->subscribersJsonPath;

        // Register this connection in subscribers.json (best-effort; never fatal).
        if ($subscribersPath !== null) {
            try {
                $this->appendSubscriber($subscribersPath, [
                    'accountId' => $accountId,
                    'accountLabel' => $accountLabel,
                    'channels' => $channels,
                    'connectedSince' => $connectedSince,
                    'lastHeartbeat' => $connectedSince,
                    'connectionId' => $connectionId,
                ]);
            } catch (\Throwable $e) {
                $logger->error(sprintf('BroadcastRouter: failed to register subscriber: %s', $e->getMessage()));
            }

            // On shutdown: remove this entry atomically.
            register_shutdown_function(function () use ($subscribersPath, $connectionId, $logger): void {
                try {
                    $this->removeSubscriber($subscribersPath, $connectionId);
                } catch (\Throwable $e) {
                    $logger->error(sprintf('BroadcastRouter: failed to remove subscriber on shutdown: %s', $e->getMessage()));
                }
            });
        }

        $clock = $this->clock ?? static fn(): int => time();
        $abort = $this->abortSignal ?? static fn(): int => connection_aborted();
        $maxDurationSec = $this->maxDurationSec;
        $keepaliveIntervalSec = $this->keepaliveIntervalSec;
        $pollIntervalUs = $this->pollIntervalUs;

        return new StreamedResponse(function () use (
            $broadcastStorage,
            $channels,
            $sessionToken,
            $logger,
            $initialCursor,
            $subscribersPath,
            $connectionId,
            $clock,
            $abort,
            $maxDurationSec,
            $keepaliveIntervalSec,
            $pollIntervalUs,
        ): void {
            // sessionToken lets the client (or a paired presenter) address this
            // connection's private channel without ever learning the raw session id.
            echo "event: connected\ndata: " . json_encode(['channels' => $channels, 'sessionToken' => $sessionToken], JSON_THROW_ON_ERROR) . "\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();

            $cursor = $initialCursor;
            $start = $clock();
            $lastKeepalive = $start;

            // Bounded loop: exit on client disconnect OR when the per-connection
            // time budget elapses. Either exit returns the worker; the client's
            // EventSource reconnects (resuming via Last-Event-ID). A never-ending
            // loop here is what starved the worker pool (admin SSE pinning a
            // worker per tab) — see docs/specs/broadcasting.md.
            while (self::streamShouldContinue($abort(), $clock() - $start, $maxDurationSec)) {
                try {
                    $messages = $broadcastStorage->poll($cursor, $channels);
                } catch (\Throwable $e) {
                    $logger->error(sprintf('SSE poll error: %s', $e->getMessage()));
                    echo "event: error\ndata: " . json_encode(['message' => 'Broadcast poll failed'], JSON_THROW_ON_ERROR) . "\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                    if ($pollIntervalUs > 0) {
                        usleep($pollIntervalUs * 2);
                    }
                    continue;
                }

                foreach ($messages as $msg) {
                    $cursor = $msg['id'];
                    try {
                        // Emit `id:` so EventSource sends Last-Event-ID on reconnect,
                        // letting the resume path above pick up from this exact point.
                        $frame = sprintf(
                            "id: %d\nevent: %s\ndata: %s\n\n",
                            $msg['id'],
                            $msg['event'],
                            json_encode($msg, JSON_THROW_ON_ERROR),
                        );
                        echo $frame;
                    } catch (\JsonException $e) {
                        $logger->error(sprintf('SSE json_encode error for event %s: %s', $msg['event'], $e->getMessage()));
                    }
                }

                if ($messages !== []) {
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }

                if (($clock() - $lastKeepalive) >= $keepaliveIntervalSec) {
                    echo ": keepalive\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                    $lastKeepalive = $clock();

                    // A keepalive write is also our disconnect probe: if the client
                    // is gone the next streamShouldContinue() sees abort and exits.

                    // Update heartbeat in subscribers.json (best-effort).
                    if ($subscribersPath !== null) {
                        try {
                            $this->updateHeartbeat($subscribersPath, $connectionId);
                        } catch (\Throwable $e) {
                            $logger->error(sprintf('BroadcastRouter: failed to update subscriber heartbeat: %s', $e->getMessage()));
                        }
                    }
                }

                if ($pollIntervalUs > 0) {
                    usleep($pollIntervalUs);
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param array{accountId: int, accountLabel: string|null, channels: list<string>, connectedSince: float, lastHeartbeat: float, connectionId: string} $entry
     */
    private function appendSubscriber(string $jsonPath, array $entry): void
    {
        $this->rewriteSubscribers($jsonPath, static function (array $rows) use ($entry): array {
            $rows[] = $entry;
            return $rows;
        });
    }

    private function updateHeartbeat(string $jsonPath, string $connectionId): void
    {
        $now = microtime(true);
        $this->rewriteSubscribers($jsonPath, static function (array $rows) use ($connectionId, $now): array {
            foreach ($rows as &$row) {
                if (isset($row['connectionId']) && $row['connectionId'] === $connectionId) {
                    $row['lastHeartbeat'] = $now;
                }
            }
            unset($row);
            return $rows;
        });
    }

    private function removeSubscriber(string $jsonPath, string $connectionId): void
    {
        $this->rewriteSubscribers($jsonPath, static function (array $rows) use ($connectionId): array {
            return array_values(array_filter(
                $rows,
                static fn(array $r): bool => !isset($r['connectionId']) || $r['connectionId'] !== $connectionId,
            ));
        });
    }

    /**
     * Atomic read-modify-write on subscribers.json.
     *
     * Uses write-to-temp-then-rename per CLAUDE.md atomic-file-write rule.
     *
     * @param callable(array<int, array<string, mixed>>): array<int, array<string, mixed>> $mutate
     */
    private function rewriteSubscribers(string $jsonPath, callable $mutate): void
    {
        $dir = dirname($jsonPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $existing = [];
        if (is_file($jsonPath)) {
            try {
                $raw = file_get_contents($jsonPath);
                if ($raw !== false && $raw !== '') {
                    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $existing = $decoded;
                    }
                }
            } catch (\JsonException) {
                // Malformed file — start fresh
            }
        }

        $updated = $mutate($existing);

        $tmp = $jsonPath . '.tmp.' . getmypid();
        file_put_contents($tmp, json_encode(array_values($updated), JSON_THROW_ON_ERROR));
        rename($tmp, $jsonPath);
    }

    /**
     * Resolve the starting cursor for a new SSE connection.
     *
     * Resume from the EventSource `Last-Event-ID` header when the client sent
     * one (auto-reconnect path) so no events are missed. Otherwise begin at
     * the supplied high-water mark — new connections do NOT receive history.
     */
    public static function resolveInitialCursor(Request $request, int $highWaterMark): int
    {
        $lastEventId = $request->headers->get('Last-Event-ID');
        if ($lastEventId !== null && ctype_digit($lastEventId)) {
            return (int) $lastEventId;
        }

        return $highWaterMark;
    }

    /**
     * @return list<string>
     */
    private static function parseChannels(string $channelsParam): array
    {
        if ($channelsParam === '') {
            return [];
        }

        $channels = array_map('trim', explode(',', $channelsParam));

        return array_values(array_filter($channels, static fn(string $ch): bool => $ch !== ''));
    }

    /**
     * Resolve the channel set a connection actually subscribes to.
     *
     * Client-supplied private channels (the reserved `session:` namespace) are
     * DROPPED — a client may not name another session's private channel. Public
     * channels are kept (defaulting to `admin` when none remain), and the
     * connection's OWN server-derived session channel is appended. This is the
     * server-side enforcement of session isolation (Wayfinding NFR-001). Pure and
     * static so the isolation contract is unit-testable without a live socket.
     *
     * @param list<string> $requested        channels the client asked for
     * @param string|null  $ownSessionChannel this connection's own private channel, or null when no session
     * @return list<string>
     */
    public static function resolveSubscriberChannels(array $requested, ?string $ownSessionChannel): array
    {
        $public = array_values(array_filter(
            $requested,
            static fn(string $ch): bool => !SessionChannel::isReserved($ch),
        ));

        if ($public === []) {
            $public = ['admin'];
        }

        if ($ownSessionChannel !== null && !in_array($ownSessionChannel, $public, true)) {
            $public[] = $ownSessionChannel;
        }

        return array_values(array_unique($public));
    }

    /**
     * This connection's own private session channel, derived server-side from the
     * PHP session id (started by SessionMiddleware). Null when there is no session.
     */
    private static function ownSessionChannel(Request $request): ?string
    {
        $sessionId = $request->hasSession() ? $request->getSession()->getId() : (string) session_id();
        if ($sessionId === '') {
            return null;
        }

        return SessionChannel::forSessionId($sessionId);
    }
}

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
     * auto-reconnects (resuming from `Last-Event-ID`, AND re-receiving any
     * retained messages via the replay-on-connect path, so no live state is
     * dropped). This is the durable guarantee that a worker is never held
     * indefinitely — even if the SAPI never reports the client disconnect (a
     * known risk under FrankenPHP worker mode, where the request loop sets
     * ignore_user_abort).
     *
     * Kept at 30s deliberately. A long-lived SSE pins one slot of the browser's
     * ~6-per-origin HTTP/1.1 connection pool for its whole lifetime; lengthening
     * this (e.g. to 5 min) let a single stream starve the admin SPA's own API
     * fetches under FrankenPHP classic `php-server` (the list hung at "Loading…").
     * Reconnect churn is NOT addressed by a longer lifetime — the thrash came
     * from MANY connections (one EventSource per consumer), now fixed by sharing
     * a single connection per channel set (see admin `useRealtime`). With one
     * shared connection, a 30s recycle is a single, lossless reconnect (retained
     * replay re-syncs live state), not a storm.
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
     * Max concurrent SSE streams admitted per account before a new connection is
     * refused with 503 + Retry-After (#1704 residual 503). The admin SPA shares
     * ONE connection per channel set, so a healthy client holds 1; the headroom
     * absorbs reload overlap (an old stream not yet released + the reconnect)
     * while capping the runaway accumulation that saturates the FrankenPHP worker
     * pool under a rapid-reload reconnect storm. 0 disables the cap.
     */
    public const int DEFAULT_MAX_CONCURRENT_STREAMS = 6;

    /** Retry-After (seconds) sent with the 503 when the per-account cap is hit. */
    public const int DEFAULT_RETRY_AFTER_SEC = 5;

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
        private readonly int $maxConcurrentStreams = self::DEFAULT_MAX_CONCURRENT_STREAMS,
        private readonly int $retryAfterSec = self::DEFAULT_RETRY_AFTER_SEC,
    ) {}

    /** Channels that require privileged (admin) authorization to subscribe to. */
    private const array PRIVILEGED_CHANNELS = ['admin'];

    /** Whether a channel requires privileged authorization to subscribe to. */
    public static function isPrivilegedChannel(string $channel): bool
    {
        return in_array($channel, self::PRIVILEGED_CHANNELS, true);
    }

    /**
     * Whether the subscribing account may receive privileged channels (e.g. the
     * site-wide `admin` entity-lifecycle feed). Duck-typed against the account
     * object set on the request as `_account` so this Layer-0 router does not
     * import the Layer-1 AccountInterface. An admin is recognised by the canonical
     * site-admin permission OR the canonical admin role (`administrator`, with
     * `admin` accepted too for route-option parity).
     */
    private static function accountMayAccessPrivilegedChannels(mixed $account): bool
    {
        if (!is_object($account)) {
            return false;
        }
        if (method_exists($account, 'isAuthenticated') && $account->isAuthenticated() !== true) {
            return false;
        }
        if (method_exists($account, 'hasPermission') && $account->hasPermission('administer site') === true) {
            return true;
        }
        if (method_exists($account, 'getRoles')) {
            $roles = $account->getRoles();
            if (is_array($roles) && (in_array('administrator', $roles, true) || in_array('admin', $roles, true))) {
                return true;
            }
        }
        return false;
    }

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

        // Use only the immutable claims snapshot established by authorization
        // middleware; the live User entity has sealed role/permission fields.
        $mayAccessPrivileged = self::accountMayAccessPrivilegedChannels($ctx->principal);

        // Strip any client-supplied private `session:*` channel and auto-subscribe
        // this connection to its OWN session channel (derived server-side). A
        // client can therefore never receive another session's private messages,
        // regardless of the channels it requests (Wayfinding NFR-001).
        // Privileged channels (e.g. `admin`) are also filtered here: accounts that
        // do not satisfy the admin predicate have them stripped and will not be
        // defaulted onto them.
        $requested = self::parseChannels($ctx->query['channels'] ?? 'admin');
        $ownSessionChannel = self::ownSessionChannel($request);
        $channels = self::resolveSubscriberChannels($requested, $ownSessionChannel, $mayAccessPrivileged);
        $sessionToken = $ownSessionChannel === null
            ? null
            : substr($ownSessionChannel, strlen(SessionChannel::PREFIX));
        $logger = $this->logger ?? new NullLogger();

        $initialCursor = self::resolveInitialCursor($request, $broadcastStorage->maxId($channels));

        // Subscriber tracking (M5D WP01 — additive extension).
        // Build a stable, non-secret connectionId from timing + PID.
        $connectedSince = microtime(true);
        $connectionId = substr(hash('sha256', $connectedSince . ':' . getmypid()), 0, 16);

        $accountId = (int) $ctx->principal->id();
        $accountLabel = null;

        $subscribersPath = $this->subscribersJsonPath;

        // Per-account concurrent-stream cap (#1704 residual 503). subscribers.json
        // is process-shared, so the count spans the whole FrankenPHP worker pool.
        // When an account already holds the cap, refuse the NEW connection with
        // 503 + Retry-After before building the long-lived stream — this applies
        // backpressure and frees the worker immediately, instead of letting a
        // rapid-reload reconnect storm accumulate streams until the pool starves.
        // Stale rows (no heartbeat within the max stream lifetime — e.g. a worker
        // that died before its shutdown cleanup) are excluded so a leftover row
        // can never wedge an account out permanently. The count→admit window is
        // not locked; an exact ceiling is unnecessary for a coarse safety cap.
        if ($subscribersPath !== null && $this->maxConcurrentStreams > 0) {
            $active = self::countActiveStreamsForAccount(
                $this->readSubscribers($subscribersPath),
                $accountId,
                microtime(true),
                (float) $this->maxDurationSec,
            );
            if ($active >= $this->maxConcurrentStreams) {
                $logger->warning(sprintf(
                    'BroadcastRouter: account %d at concurrent-stream cap (%d active >= %d); refusing with 503.',
                    $accountId,
                    $active,
                    $this->maxConcurrentStreams,
                ));

                return new Response(
                    json_encode([
                        'error' => 'too_many_concurrent_streams',
                        'retryAfter' => $this->retryAfterSec,
                    ], JSON_THROW_ON_ERROR),
                    Response::HTTP_SERVICE_UNAVAILABLE,
                    [
                        'Content-Type' => 'application/json',
                        'Retry-After' => (string) $this->retryAfterSec,
                        'Cache-Control' => 'no-cache',
                    ],
                );
            }
        }

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
            // CRITICAL — release the PHP session lock before the long-lived stream.
            // SessionMiddleware opened the native session (session_start) and PHP
            // holds its PHPSESSID file lock until the script ends — for this
            // StreamedResponse that is the full stream lifetime (up to the 30s cap).
            // While the lock is held, EVERY other request carrying the same
            // PHPSESSID blocks in session_start() until this stream ends: the SPA's
            // own document reloads, its /api/* fetches, and a second admin tab all
            // serialize behind the SSE — THIS is the 15-25s admin "blank" under
            // reloads / multiple tabs. All session data this stream needs
            // ($channels, $sessionToken) was read in handle() before this closure,
            // and the stream never writes the session, so closing now is safe; the
            // session cookie (sent at session_start) is unaffected.
            if (function_exists('session_write_close') && session_status() === \PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            // Defensive: under FrankenPHP (and php-fpm) the request bootstrap sets
            // ignore_user_abort(true), which can suppress connection_aborted() from
            // flipping when a write lands on a dead socket. Clearing it for this
            // stream lets a failed keepalive write surface as an abort so the bounded
            // loop exits within one keepalive after a client navigates away, instead
            // of waiting out the time-budget cap. (Measured on FrankenPHP 1.12.4 the
            // abort already flips on the keepalive write regardless, so this is
            // hardening, not the primary fix; the 30s cap is the durable backstop.)
            if (function_exists('ignore_user_abort')) {
                ignore_user_abort(false);
            }

            // sessionToken lets the client (or a paired presenter) address this
            // connection's private channel without ever learning the raw session id.
            echo "event: connected\ndata: " . json_encode(['channels' => $channels, 'sessionToken' => $sessionToken], JSON_THROW_ON_ERROR) . "\n\n";

            // Replay still-active retained messages for the subscribed channels
            // immediately after `connected`, so a reconnect (or a fresh page
            // load) re-receives live state that predates this connection — e.g.
            // a Wayfinding beacon emitted during the hydration reconnect window,
            // which the cursor stream alone would drop (the showcase blocker).
            // Replay frames carry the original broadcast id INSIDE the JSON
            // envelope (clients de-dupe by it) but deliberately emit NO SSE
            // `id:` line, so they never rewind the connection's Last-Event-ID
            // cursor — only genuinely-new live messages below advance it.
            try {
                foreach ($broadcastStorage->retainedFor($channels) as $msg) {
                    echo 'event: ' . $msg['event'] . "\ndata: " . json_encode($msg, JSON_THROW_ON_ERROR) . "\n\n";
                }
            } catch (\Throwable $e) {
                $logger->error(sprintf('SSE retained replay error: %s', $e->getMessage()));
            }

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

                    // The write above doubles as a disconnect probe: if the client
                    // is gone, break now instead of polling another cycle.
                    if ($abort() !== 0) {
                        break;
                    }
                }

                if (($clock() - $lastKeepalive) >= $keepaliveIntervalSec) {
                    echo ": keepalive\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                    $lastKeepalive = $clock();

                    // The keepalive write above doubles as our disconnect probe.
                    // Re-read the abort state now instead of deferring to the next
                    // streamShouldContinue(): a client that navigated away then
                    // releases the worker within this keepalive (~2s) rather than
                    // after another poll cycle. ignore_user_abort(false) at the top
                    // of the stream is what lets the failed write flip this.
                    if ($abort() !== 0) {
                        break;
                    }

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
     * Read subscribers.json into rows (empty on missing/malformed). Extracted so
     * the concurrency cap can count without the read-modify-write cycle.
     *
     * @return array<int, array<string, mixed>>
     */
    private function readSubscribers(string $jsonPath): array
    {
        if (!is_file($jsonPath)) {
            return [];
        }

        try {
            $raw = file_get_contents($jsonPath);
            if ($raw === false || $raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            // Malformed file — treat as empty.
            return [];
        }
    }

    /**
     * Count an account's currently-active SSE streams in the subscribers table.
     * Pure + static so the concurrency-cap decision is unit-testable without a
     * live socket or a real file. A row counts when its last heartbeat (falling
     * back to connect time) is within $staleAfterSec; rows older than the max
     * stream lifetime are treated as dead (a worker that exited without running
     * its shutdown cleanup) and excluded, so a stale row cannot permanently wedge
     * an account out of new connections.
     *
     * @param array<int, mixed> $rows
     */
    public static function countActiveStreamsForAccount(array $rows, int $accountId, float $now, float $staleAfterSec): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((int) ($row['accountId'] ?? -1) !== $accountId) {
                continue;
            }
            $last = (float) ($row['lastHeartbeat'] ?? $row['connectedSince'] ?? 0.0);
            if ($staleAfterSec > 0.0 && ($now - $last) > $staleAfterSec) {
                continue;
            }
            ++$count;
        }

        return $count;
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

        $existing = $this->readSubscribers($jsonPath);

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
     * DROPPED — a client may not name another session's private channel. Privileged
     * channels (e.g. `admin`) are also filtered: they are kept only when
     * `$mayAccessPrivileged` is true. The `admin` default is applied only when the
     * account may access privileged channels, so an unauthorized caller is never
     * silently placed onto a privileged channel. Non-privileged public channels are
     * kept for everyone. The connection's OWN server-derived session channel is
     * appended at the end. This is the server-side enforcement of session isolation
     * (Wayfinding NFR-001) and per-channel ACL. Pure and static so both contracts
     * are unit-testable without a live socket.
     *
     * @param list<string> $requested          channels the client asked for
     * @param string|null  $ownSessionChannel  this connection's own private channel, or null when no session
     * @param bool         $mayAccessPrivileged whether the account may receive privileged channels
     * @return list<string>
     */
    public static function resolveSubscriberChannels(array $requested, ?string $ownSessionChannel, bool $mayAccessPrivileged = false): array
    {
        $public = array_values(array_filter(
            $requested,
            static fn(string $ch): bool =>
                !SessionChannel::isReserved($ch)
                && ($mayAccessPrivileged || !self::isPrivilegedChannel($ch)),
        ));

        // Default the admin SPA's primary feed only for accounts allowed to see it;
        // an unauthorized caller must never be defaulted onto a privileged channel.
        if ($public === [] && $mayAccessPrivileged) {
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

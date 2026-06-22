<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Api\MercureMonitor\ChannelInspectorInterface;
use Waaseyaa\Api\MercureMonitor\ChannelInspectorRow;
use Waaseyaa\Api\MercureMonitor\EventStreamFilter;
use Waaseyaa\Api\MercureMonitor\EventStreamReadModelInterface;
use Waaseyaa\Api\MercureMonitor\SubscriberObserverInterface;
use Waaseyaa\Api\MercureMonitor\SubscriberRow;

/**
 * Admin-only controller for the Mercure broadcast monitor dashboard (M5D WP01).
 *
 * Three actions back the monitor page:
 *   - `channels`    — 24h channel statistics (JSON)
 *   - `events`      — live SSE stream of recent broadcast events
 *   - `subscribers` — current connected SSE subscribers (JSON)
 *
 * All three deps are nullable: when any adapter is absent (slimmed-down
 * install without the foundation monitor SP binding) the controller returns an
 * empty-shape response rather than crashing kernel boot (FR-006).
 *
 * Access control: enforced by `_role: admin` route option. The controller does
 * NOT re-check the role (NFR-001 / DIR-004).
 *
 * SSE shape mirrors `BroadcastRouter::handle()` exactly: `event:`/`data:`/
 * `\n\n` frames, `: keepalive\n\n` every 15s, terminate on
 * `connection_aborted()`. The stream dep being null emits a single
 * `event: disabled` frame and closes.
 *
 * camelCase JSON keys match the WP02 frontend contract (FR-006).
 *
 * @api
 */
final class MercureMonitorController
{
    /**
     * Per-connection time budget. Mirrors `BroadcastRouter::DEFAULT_MAX_DURATION_SEC`
     * — the durable backstop that returns the worker even if the SAPI never
     * reports the client disconnect (CL-12). Was previously absent here: the
     * stream looped on `connection_aborted()` alone with no time cap.
     */
    public const int DEFAULT_MAX_DURATION_SEC = 30;

    /** Keepalive cadence (seconds). A write doubles as the disconnect probe. */
    public const int DEFAULT_KEEPALIVE_INTERVAL_SEC = 15;

    /** Pause between event-stream polls. */
    public const int DEFAULT_POLL_INTERVAL_US = 500_000;

    /**
     * @param (\Closure(): int)|null $clock       Override `time()` (seconds) — tests inject a fake clock.
     * @param (\Closure(): int)|null $abortSignal Override `connection_aborted()` — tests inject disconnect.
     */
    public function __construct(
        private readonly ?ChannelInspectorInterface $inspector = null,
        private readonly ?EventStreamReadModelInterface $stream = null,
        private readonly ?SubscriberObserverInterface $observer = null,
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
     * socket. Mirrors `BroadcastRouter::streamShouldContinue` (CL-12).
     */
    public static function streamShouldContinue(int $abortStatus, int $elapsedSec, int $maxDurationSec): bool
    {
        return $abortStatus === 0 && $elapsedSec < $maxDurationSec;
    }

    /**
     * `GET /api/mercure/channels` — 24h channel statistics.
     *
     * @return array{data: array{rows: list<array{channel: string, eventCount24h: int, lastEventAt: float|null, lastEventName: string|null}>}}
     */
    public function channels(Request $request): array
    {
        if ($this->inspector === null) {
            return ['data' => ['rows' => []]];
        }

        $rows = array_map(
            static fn(ChannelInspectorRow $r): array => [
                'channel' => $r->channel,
                'eventCount24h' => $r->eventCount24h,
                'lastEventAt' => $r->lastEventAt,
                'lastEventName' => $r->lastEventName,
            ],
            $this->inspector->listChannels(),
        );

        return ['data' => ['rows' => $rows]];
    }

    /**
     * `GET /api/mercure/events` — live SSE stream.
     *
     * Query params: `channels` (CSV), `event` (name), `since` (ISO 8601).
     *
     * Mirrors `BroadcastRouter::handle()`: 500ms poll, keepalive every 15s,
     * terminates on `connection_aborted()`.
     */
    public function events(Request $request): StreamedResponse
    {
        $filter = EventStreamFilter::fromQuery($request->query->all());
        $stream = $this->stream;
        $clock = $this->clock ?? static fn(): int => time();
        $abort = $this->abortSignal ?? static fn(): int => connection_aborted();
        $maxDurationSec = $this->maxDurationSec;
        $keepaliveIntervalSec = $this->keepaliveIntervalSec;
        $pollIntervalUs = $this->pollIntervalUs;

        return new StreamedResponse(
            function () use ($filter, $stream, $clock, $abort, $maxDurationSec, $keepaliveIntervalSec, $pollIntervalUs): void {
                // Release the PHP session lock before the long-lived stream so
                // concurrent same-session requests don't serialize behind it
                // (the admin "blank" root cause BroadcastRouter already fixes).
                // Safe: this stream reads no session state past here and never
                // writes the session.
                if (function_exists('session_write_close') && session_status() === \PHP_SESSION_ACTIVE) {
                    session_write_close();
                }

                // Undo the FrankenPHP/php-fpm bootstrap default (ignore_user_abort
                // true) so a failed write to a dead socket flips
                // connection_aborted() and the bounded loop exits within one
                // keepalive instead of riding out the full time budget (CL-12).
                if (function_exists('ignore_user_abort')) {
                    ignore_user_abort(false);
                }

                if ($stream === null) {
                    echo "event: disabled\ndata: {}\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                    return;
                }

                // Emit initial connection frame
                echo "event: connected\ndata: " . json_encode(
                    ['channels' => $filter->channels],
                    JSON_THROW_ON_ERROR,
                ) . "\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                // Track the highest ID we've seen so we only stream new rows
                $cursor = 0;
                $start = $clock();
                $lastKeepalive = $start;

                // Bounded loop (CL-12): exit on client disconnect OR when the
                // per-connection time budget elapses — whichever comes first.
                // Previously `while (connection_aborted() === 0)` with NO time
                // cap, so a missed disconnect (FrankenPHP worker mode) pinned the
                // worker indefinitely — the same class BroadcastRouter fixed.
                while (self::streamShouldContinue($abort(), $clock() - $start, $maxDurationSec)) {
                    $rows = $stream->recentEvents($filter, 100);

                    $emitted = false;
                    foreach ($rows as $row) {
                        if ($row->id <= $cursor) {
                            continue;
                        }
                        $cursor = $row->id;

                        try {
                            $frame = sprintf(
                                "id: %d\nevent: %s\ndata: %s\n\n",
                                $row->id,
                                $row->event,
                                json_encode([
                                    'id' => $row->id,
                                    'channel' => $row->channel,
                                    'event' => $row->event,
                                    'data' => $row->data,
                                    'createdAt' => $row->createdAt,
                                ], JSON_THROW_ON_ERROR),
                            );
                            echo $frame;
                            $emitted = true;
                        } catch (\JsonException) {
                            // Skip malformed rows
                        }
                    }

                    if ($emitted) {
                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
                        // The write doubles as a disconnect probe; bail now
                        // rather than polling another cycle.
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

                        // Re-probe immediately after the keepalive write so a
                        // navigated-away client releases the worker within this
                        // keepalive rather than after another poll cycle.
                        if ($abort() !== 0) {
                            break;
                        }
                    }

                    if ($pollIntervalUs > 0) {
                        usleep($pollIntervalUs);
                    }
                }
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    /**
     * `GET /api/mercure/subscribers` — active connected subscribers.
     *
     * @return array{data: array{rows: list<array{accountId: int, accountLabel: string|null, channels: list<string>, connectedSince: float}>}}
     */
    public function subscribers(Request $request): array
    {
        if ($this->observer === null) {
            return ['data' => ['rows' => []]];
        }

        $rows = array_map(
            static fn(SubscriberRow $r): array => [
                'accountId' => $r->accountId,
                'accountLabel' => $r->accountLabel,
                'channels' => $r->channels,
                'connectedSince' => $r->connectedSince,
            ],
            $this->observer->currentSubscribers(),
        );

        return ['data' => ['rows' => $rows]];
    }
}

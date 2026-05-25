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
    public function __construct(
        private readonly ?ChannelInspectorInterface $inspector = null,
        private readonly ?EventStreamReadModelInterface $stream = null,
        private readonly ?SubscriberObserverInterface $observer = null,
    ) {}

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

        return new StreamedResponse(
            function () use ($filter, $stream): void {
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
                $lastKeepalive = time();

                while (connection_aborted() === 0) {
                    $rows = $stream->recentEvents($filter, 100);

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
                        } catch (\JsonException) {
                            // Skip malformed rows
                        }
                    }

                    if ($rows !== []) {
                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
                    }

                    if ((time() - $lastKeepalive) >= 15) {
                        echo ": keepalive\n\n";
                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
                        $lastKeepalive = time();
                    }

                    usleep(500_000);
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

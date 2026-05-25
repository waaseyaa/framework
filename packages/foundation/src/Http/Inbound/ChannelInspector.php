<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Inbound;

use Waaseyaa\Api\MercureMonitor\ChannelInspectorInterface;
use Waaseyaa\Api\MercureMonitor\ChannelInspectorRow;
use Waaseyaa\Database\DatabaseInterface;

/**
 * Foundation adapter for `ChannelInspectorInterface`.
 *
 * Reads 24h per-channel statistics from `_broadcast_log` via GROUP BY.
 * Empty table → empty array, never 500 (FR-002).
 *
 * C-002: does not expose any `Waaseyaa\Foundation\*` type across the
 * api-layer boundary — the interface and DTO live in `packages/api`.
 */
final class ChannelInspector implements ChannelInspectorInterface
{
    public function __construct(
        private readonly DatabaseInterface $database,
    ) {}

    /**
     * @return list<ChannelInspectorRow>
     */
    public function listChannels(): array
    {
        $window = microtime(true) - 86400.0;

        try {
            $sql = 'SELECT channel, COUNT(*) AS event_count, MAX(created_at) AS last_event_at, MAX(event) AS last_event_name'
                . ' FROM _broadcast_log'
                . ' WHERE created_at >= ?'
                . ' GROUP BY channel'
                . ' ORDER BY last_event_at DESC';

            $rows = iterator_to_array($this->database->query($sql, [$window]), false);
        } catch (\Throwable) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $result[] = new ChannelInspectorRow(
                channel: (string) $row['channel'],
                eventCount24h: (int) $row['event_count'],
                lastEventAt: $row['last_event_at'] !== null ? (float) $row['last_event_at'] : null,
                lastEventName: $row['last_event_name'] !== null ? (string) $row['last_event_name'] : null,
            );
        }

        return $result;
    }
}

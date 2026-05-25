<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Inbound;

use Waaseyaa\Api\MercureMonitor\BroadcastEventRow;
use Waaseyaa\Api\MercureMonitor\EventStreamFilter;
use Waaseyaa\Api\MercureMonitor\EventStreamReadModelInterface;
use Waaseyaa\Database\DatabaseInterface;

/**
 * Foundation adapter for `EventStreamReadModelInterface`.
 *
 * Reads `_broadcast_log` rows matching `EventStreamFilter`.
 *
 * - Malformed JSON `data` column → empty array, never fatal (FR-003).
 * - Enforces `limit <= 1000` (FR-003).
 * - Orders by `id DESC` (FR-003).
 */
final class EventStreamReadModel implements EventStreamReadModelInterface
{
    private const MAX_LIMIT = 1000;

    public function __construct(
        private readonly DatabaseInterface $database,
    ) {}

    /**
     * @return list<BroadcastEventRow>
     */
    public function recentEvents(EventStreamFilter $filter, int $limit = 100): array
    {
        $limit = min($limit, self::MAX_LIMIT);

        try {
            $sql = 'SELECT id, channel, event, data, created_at FROM _broadcast_log WHERE 1=1';
            $params = [];

            if ($filter->channels !== []) {
                $placeholders = implode(', ', array_fill(0, count($filter->channels), '?'));
                $sql .= " AND channel IN ({$placeholders})";
                foreach ($filter->channels as $ch) {
                    $params[] = $ch;
                }
            }

            if ($filter->event !== null) {
                $sql .= ' AND event = ?';
                $params[] = $filter->event;
            }

            if ($filter->since !== null) {
                $sql .= ' AND created_at >= ?';
                $params[] = $filter->since;
            }

            $sql .= ' ORDER BY id DESC LIMIT ?';
            $params[] = $limit;

            $rows = iterator_to_array($this->database->query($sql, $params), false);
        } catch (\Throwable) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $data = [];
            if (isset($row['data']) && is_string($row['data']) && $row['data'] !== '') {
                try {
                    $decoded = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $data = $decoded;
                    }
                } catch (\JsonException) {
                    // Malformed row → empty data, never fatal (FR-003)
                }
            }

            $result[] = new BroadcastEventRow(
                id: (int) $row['id'],
                channel: (string) $row['channel'],
                event: (string) $row['event'],
                data: $data,
                createdAt: (float) $row['created_at'],
            );
        }

        return $result;
    }
}

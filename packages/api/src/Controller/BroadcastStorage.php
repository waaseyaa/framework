<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Waaseyaa\Database\PdoDatabase;

/**
 * PDO-backed message queue for SSE broadcasting.
 *
 * Provides a durable store that decouples the HTTP request that triggers an
 * entity event from the long-lived SSE connection that delivers it. The SSE
 * loop polls this table for new rows since its last cursor.
 */
final class BroadcastStorage
{
    public function __construct(private readonly PdoDatabase $database)
    {
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->database->getPdo()->exec(
            'CREATE TABLE IF NOT EXISTS _broadcast_log ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'channel TEXT NOT NULL,'
            . 'event TEXT NOT NULL,'
            . 'data TEXT NOT NULL DEFAULT \'{}\','
            . 'created_at REAL NOT NULL'
            . ')'
        );
    }

    /**
     * Push a message into the broadcast log.
     */
    public function push(string $channel, string $event, array $data): void
    {
        $stmt = $this->database->getPdo()->prepare(
            'INSERT INTO _broadcast_log (channel, event, data, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$channel, $event, json_encode($data, JSON_THROW_ON_ERROR), microtime(true)]);
    }

    /**
     * Poll for messages newer than the given cursor (last seen row ID).
     *
     * @param int $afterId Return messages with id > $afterId. Pass 0 for all.
     * @param list<string> $channels Filter to specific channels. Empty = all.
     * @return list<array{id: int, channel: string, event: string, data: array, created_at: float}>
     */
    public function poll(int $afterId, array $channels = []): array
    {
        $sql = 'SELECT id, channel, event, data, created_at FROM _broadcast_log WHERE id > ?';
        $params = [$afterId];

        if ($channels !== []) {
            $placeholders = implode(', ', array_fill(0, count($channels), '?'));
            $sql .= " AND channel IN ({$placeholders})";
            $params = array_merge($params, $channels);
        }

        $sql .= ' ORDER BY id ASC LIMIT 100';

        $stmt = $this->database->getPdo()->prepare($sql);
        $stmt->execute($params);

        $messages = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $messages[] = [
                'id' => (int) $row['id'],
                'channel' => $row['channel'],
                'event' => $row['event'],
                'data' => json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR),
                'created_at' => (float) $row['created_at'],
            ];
        }

        return $messages;
    }

    /**
     * Remove messages older than $maxAgeSeconds.
     */
    public function prune(int $maxAgeSeconds = 300): void
    {
        $cutoff = microtime(true) - $maxAgeSeconds;
        $stmt = $this->database->getPdo()->prepare(
            'DELETE FROM _broadcast_log WHERE created_at < ?'
        );
        $stmt->execute([$cutoff]);
    }
}

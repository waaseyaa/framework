<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Controller;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;

/**
 * PDO-backed message queue for SSE broadcasting.
 *
 * Provides a durable store that decouples the HTTP request that triggers an
 * entity event from the long-lived SSE connection that delivers it. The SSE
 * loop polls this table for new rows since its last cursor.
 *
 * Uses raw PDO escape hatch via DBALDatabase::getConnection()->getNativeConnection().
 * This will be migrated to DBAL Connection API in a future PR.
 */
final class BroadcastStorage
{
    private readonly \PDO $pdo;

    public function __construct(DatabaseInterface $database)
    {
        assert($database instanceof DBALDatabase);
        $nativeConn = $database->getConnection()->getNativeConnection();
        assert($nativeConn instanceof \PDO);
        $this->pdo = $nativeConn;
        $this->ensureTable();
        $this->ensureRetainedTable();
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS _broadcast_log ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . 'channel TEXT NOT NULL,'
            . 'event TEXT NOT NULL,'
            . 'data TEXT NOT NULL DEFAULT \'{}\','
            . 'created_at REAL NOT NULL'
            . ')',
        );
    }

    /**
     * Retained-message table: the still-active state for a (channel, retain_key)
     * pair, last-write-wins, replayed to every NEW subscriber on connect.
     *
     * The plain `_broadcast_log` is a fire-and-forget cursor stream — a new
     * connection starts at "now" and never sees history, so an event pushed
     * before the connection existed is lost (the Wayfinding beacon-reconnect
     * race: a beacon emitted during the hydration reconnect window vanished).
     * A retained message is the durable counterpart: it survives reconnects and
     * fresh page loads until it is superseded, dropped, or its TTL expires.
     */
    private function ensureRetainedTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS _broadcast_retained ('
            . 'channel TEXT NOT NULL,'
            . 'retain_key TEXT NOT NULL,'
            . 'msg_id INTEGER NOT NULL,'
            . 'event TEXT NOT NULL,'
            . 'data TEXT NOT NULL DEFAULT \'{}\','
            . 'created_at REAL NOT NULL,'
            . 'expires_at REAL,'
            . 'PRIMARY KEY (channel, retain_key)'
            . ')',
        );
    }

    /**
     * Push a message into the broadcast log.
     */
    public function push(string $channel, string $event, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO _broadcast_log (channel, event, data, created_at) VALUES (?, ?, ?, ?)',
        );
        $stmt->execute([$channel, $event, json_encode($data, JSON_THROW_ON_ERROR), microtime(true)]);
    }

    /**
     * Push a message AND retain it as the live state for (channel, $retainKey).
     *
     * Does both jobs in one call: the message is pushed to `_broadcast_log` for
     * live delivery to currently-connected subscribers (via {@see poll()}), and
     * recorded in `_broadcast_retained` so a NEW subscriber re-receives it on
     * connect (via {@see retainedFor()}). Last-write-wins per key — re-emitting
     * the same key supersedes the prior value. An optional TTL expires it.
     *
     * The returned broadcast-log id is also stored on the retained row, so a
     * replay frame can carry the SAME id the live push had — a client that
     * already ingested the live message de-dupes the replay by that id.
     */
    public function pushRetained(
        string $channel,
        string $event,
        array $data,
        string $retainKey,
        ?float $ttlSeconds = null,
    ): int {
        $now = microtime(true);
        $payload = json_encode($data, JSON_THROW_ON_ERROR);

        $insert = $this->pdo->prepare(
            'INSERT INTO _broadcast_log (channel, event, data, created_at) VALUES (?, ?, ?, ?)',
        );
        $insert->execute([$channel, $event, $payload, $now]);
        $msgId = (int) $this->pdo->lastInsertId();

        $expiresAt = $ttlSeconds !== null ? $now + $ttlSeconds : null;
        // Portable upsert (SQLite + MySQL): delete-then-insert. The retained set
        // is written by a single presenter emitting sequentially, so there is no
        // same-key write race to guard against.
        $this->pdo->prepare('DELETE FROM _broadcast_retained WHERE channel = ? AND retain_key = ?')
            ->execute([$channel, $retainKey]);
        $this->pdo->prepare(
            'INSERT INTO _broadcast_retained (channel, retain_key, msg_id, event, data, created_at, expires_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
        )->execute([$channel, $retainKey, $msgId, $event, $payload, $now, $expiresAt]);

        return $msgId;
    }

    /**
     * Still-active (non-expired) retained messages for the given channels,
     * oldest first. Replayed to a new SSE subscriber on connect so live state
     * survives reconnects/reloads. Expired rows are pruned opportunistically.
     *
     * The returned envelope is byte-identical in shape to {@see poll()} so the
     * SSE handler emits replay frames the same way it emits live ones.
     *
     * @param list<string> $channels
     * @return list<array{id: int, channel: string, event: string, data: array, created_at: float}>
     */
    public function retainedFor(array $channels): array
    {
        if ($channels === []) {
            return [];
        }

        // Read-only: expired rows are filtered out here and physically deleted
        // by pruneRetained() on the scheduled prune, NOT on this path. This runs
        // on the SSE connect hot path (every (re)connect), so it must take no
        // write lock — a DELETE here serialized concurrent page-load reads under
        // SQLite.
        $placeholders = implode(', ', array_fill(0, count($channels), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT msg_id, channel, event, data, created_at FROM _broadcast_retained '
            . "WHERE channel IN ({$placeholders}) AND (expires_at IS NULL OR expires_at >= ?) "
            . 'ORDER BY msg_id ASC',
        );
        $stmt->execute([...$channels, microtime(true)]);

        $messages = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $messages[] = [
                'id' => (int) $row['msg_id'],
                'channel' => $row['channel'],
                'event' => $row['event'],
                'data' => json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR),
                'created_at' => (float) $row['created_at'],
            ];
        }

        return $messages;
    }

    /**
     * Drop retained state. With a $retainKey, drops that one entry; without,
     * drops every retained message on the channel (e.g. a viewer dismissing the
     * whole Wayfinding trail clears its own session's retained beacons so they
     * do not replay on the next reload).
     */
    public function dropRetained(string $channel, ?string $retainKey = null): void
    {
        if ($retainKey === null) {
            $this->pdo->prepare('DELETE FROM _broadcast_retained WHERE channel = ?')
                ->execute([$channel]);

            return;
        }

        $this->pdo->prepare('DELETE FROM _broadcast_retained WHERE channel = ? AND retain_key = ?')
            ->execute([$channel, $retainKey]);
    }

    /** Delete expired retained messages (TTL elapsed). */
    public function pruneRetained(): void
    {
        $this->pdo->prepare('DELETE FROM _broadcast_retained WHERE expires_at IS NOT NULL AND expires_at < ?')
            ->execute([microtime(true)]);
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

        $stmt = $this->pdo->prepare($sql);
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
     * Return the highest existing row id, optionally filtered by channels.
     *
     * Returns 0 when the log is empty. Used by SSE handlers to start new
     * connections at "now" instead of replaying history on every connect.
     *
     * @param list<string> $channels Filter to specific channels. Empty = all.
     */
    public function maxId(array $channels = []): int
    {
        $sql = 'SELECT MAX(id) AS max_id FROM _broadcast_log';
        $params = [];

        if ($channels !== []) {
            $placeholders = implode(', ', array_fill(0, count($channels), '?'));
            $sql .= " WHERE channel IN ({$placeholders})";
            $params = $channels;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return (int) ($row['max_id'] ?? 0);
    }

    /**
     * Remove messages older than $retentionDays days.
     *
     * @param int $retentionDays Number of days to retain. Rows with created_at
     *                           older than this many days are deleted.
     *                           Default: 7 days (matches BroadcastStorageScheduleEntries).
     */
    public function prune(int $retentionDays = 7): void
    {
        $cutoff = microtime(true) - ($retentionDays * 86400);
        $stmt = $this->pdo->prepare(
            'DELETE FROM _broadcast_log WHERE created_at < ?',
        );
        $stmt->execute([$cutoff]);

        // Retained messages expire on their own TTL, not the log retention
        // window; sweep any that have elapsed while we are here.
        $this->pruneRetained();
    }
}

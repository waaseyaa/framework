<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\CodifiedContext\Storage;

use Waaseyaa\Telescope\CodifiedContext\CodifiedContextEntry;
use Waaseyaa\Telescope\TelescopeEntry;

/**
 * SQLite-based storage backend for codified context entries.
 */
final class SqliteCodifiedContextStore implements CodifiedContextStoreInterface
{
    private bool $tableEnsured = false;

    public function __construct(
        private readonly \PDO $pdo,
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    }

    public static function createInMemory(): self
    {
        return new self(new \PDO('sqlite::memory:'));
    }

    public static function createFromPath(string $path): self
    {
        return new self(new \PDO('sqlite:' . $path));
    }

    public function store(string $type, array $data): void
    {
        $this->ensureTable();

        $id = bin2hex(random_bytes(16));
        $sessionId = $data['session_id'] ?? '';
        $createdAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');

        $stmt = $this->pdo->prepare(
            'INSERT INTO telescope_cc_entries (id, type, session_id, data, created_at) VALUES (:id, :type, :session_id, :data, :created_at)',
        );
        $stmt->execute([
            'id' => $id,
            'type' => $type,
            'session_id' => $sessionId,
            'data' => json_encode($data, JSON_THROW_ON_ERROR),
            'created_at' => $createdAt,
        ]);
    }

    public function query(string $type, int $limit = 50, int $offset = 0): array
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare(
            'SELECT id, type, session_id, data, created_at FROM telescope_cc_entries WHERE type = :type ORDER BY created_at DESC LIMIT :limit OFFSET :offset',
        );
        $stmt->bindValue('type', $type, \PDO::PARAM_STR);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $entries = [];
        while ($row = $stmt->fetch()) {
            $entries[] = new TelescopeEntry(
                type: $row['type'],
                data: json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR),
                id: $row['id'],
                createdAt: new \DateTimeImmutable($row['created_at']),
            );
        }

        return $entries;
    }

    public function queryBySession(string $sessionId, int $limit = 100, int $offset = 0): array
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare(
            'SELECT id, type, session_id, data, created_at FROM telescope_cc_entries WHERE session_id = :session_id ORDER BY created_at DESC LIMIT :limit OFFSET :offset',
        );
        $stmt->bindValue('session_id', $sessionId, \PDO::PARAM_STR);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $this->fetchAsCodifiedContextEntries($stmt);
    }

    public function queryByEventType(string $eventType, int $limit = 50, int $offset = 0): array
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare(
            "SELECT id, type, session_id, data, created_at FROM telescope_cc_entries WHERE json_extract(data, '$.event_type') = :event_type ORDER BY created_at DESC LIMIT :limit OFFSET :offset",
        );
        $stmt->bindValue('event_type', $eventType, \PDO::PARAM_STR);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $this->fetchAsCodifiedContextEntries($stmt);
    }

    public function queryByTimeRange(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 100): array
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare(
            'SELECT id, type, session_id, data, created_at FROM telescope_cc_entries WHERE created_at BETWEEN :from AND :to ORDER BY created_at DESC LIMIT :limit',
        );
        $stmt->bindValue('from', $from->format('Y-m-d H:i:s.u'), \PDO::PARAM_STR);
        $stmt->bindValue('to', $to->format('Y-m-d H:i:s.u'), \PDO::PARAM_STR);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $this->fetchAsCodifiedContextEntries($stmt);
    }

    public function queryByDriftSeverity(string $severity, int $limit = 50, int $offset = 0): array
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare(
            "SELECT id, type, session_id, data, created_at FROM telescope_cc_entries WHERE json_extract(data, '$.severity') = :severity ORDER BY created_at DESC LIMIT :limit OFFSET :offset",
        );
        $stmt->bindValue('severity', $severity, \PDO::PARAM_STR);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $this->fetchAsCodifiedContextEntries($stmt);
    }

    public function prune(\DateTimeInterface $before): int
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare(
            'DELETE FROM telescope_cc_entries WHERE created_at < :before',
        );
        $stmt->execute([
            'before' => $before->format('Y-m-d H:i:s.u'),
        ]);

        return $stmt->rowCount();
    }

    public function clear(): void
    {
        $this->ensureTable();

        $this->pdo->prepare('DELETE FROM telescope_cc_entries')->execute();
    }

    /**
     * @return CodifiedContextEntry[]
     */
    private function fetchAsCodifiedContextEntries(\PDOStatement $stmt): array
    {
        $entries = [];
        while ($row = $stmt->fetch()) {
            $entries[] = CodifiedContextEntry::fromArray([
                'id' => $row['id'],
                'type' => $row['type'],
                'session_id' => $row['session_id'],
                'data' => $row['data'],
                'created_at' => $row['created_at'],
            ]);
        }

        return $entries;
    }

    private function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        $this->pdo->prepare(
            'CREATE TABLE IF NOT EXISTS telescope_cc_entries (
                id TEXT PRIMARY KEY,
                type TEXT NOT NULL,
                session_id TEXT NOT NULL DEFAULT \'\',
                data TEXT NOT NULL,
                created_at TEXT NOT NULL
            )',
        )->execute();

        $this->pdo->prepare(
            'CREATE INDEX IF NOT EXISTS idx_cc_type_created ON telescope_cc_entries (type, created_at)',
        )->execute();

        $this->pdo->prepare(
            'CREATE INDEX IF NOT EXISTS idx_cc_session_id ON telescope_cc_entries (session_id)',
        )->execute();

        $this->tableEnsured = true;
    }
}

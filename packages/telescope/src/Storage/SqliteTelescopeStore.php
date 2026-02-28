<?php

declare(strict_types=1);

namespace Aurora\Telescope\Storage;

use Aurora\Telescope\TelescopeEntry;

/**
 * SQLite-based storage backend for telescope entries.
 */
final class SqliteTelescopeStore implements TelescopeStoreInterface
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

        $entry = new TelescopeEntry(type: $type, data: $data);

        $stmt = $this->pdo->prepare(
            'INSERT INTO telescope_entries (id, type, data, created_at) VALUES (:id, :type, :data, :created_at)'
        );
        $stmt->execute([
            'id' => $entry->id,
            'type' => $entry->type,
            'data' => json_encode($entry->data, JSON_THROW_ON_ERROR),
            'created_at' => $entry->createdAt->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function query(string $type, int $limit = 50, int $offset = 0): array
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare(
            'SELECT id, type, data, created_at FROM telescope_entries WHERE type = :type ORDER BY created_at DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('type', $type, \PDO::PARAM_STR);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $entries = [];
        while ($row = $stmt->fetch()) {
            $entries[] = TelescopeEntry::fromArray($row);
        }

        return $entries;
    }

    public function prune(\DateTimeInterface $before): int
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare(
            'DELETE FROM telescope_entries WHERE created_at < :before'
        );
        $stmt->execute([
            'before' => $before->format('Y-m-d H:i:s.u'),
        ]);

        return $stmt->rowCount();
    }

    public function clear(): void
    {
        $this->ensureTable();

        $this->pdo->prepare('DELETE FROM telescope_entries')->execute();
    }

    private function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        $this->pdo->prepare(
            'CREATE TABLE IF NOT EXISTS telescope_entries (
                id TEXT PRIMARY KEY,
                type TEXT NOT NULL,
                data TEXT NOT NULL,
                created_at TEXT NOT NULL
            )'
        )->execute();

        $this->pdo->prepare(
            'CREATE INDEX IF NOT EXISTS idx_telescope_type_created ON telescope_entries (type, created_at)'
        )->execute();

        $this->tableEnsured = true;
    }
}

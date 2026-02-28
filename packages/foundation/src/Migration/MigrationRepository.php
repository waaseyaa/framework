<?php

declare(strict_types=1);

namespace Aurora\Foundation\Migration;

use Doctrine\DBAL\Connection;

final class MigrationRepository
{
    private const TABLE = 'aurora_migrations';

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function createTable(): void
    {
        $this->connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration VARCHAR(255) NOT NULL,
                package VARCHAR(128) NOT NULL,
                batch INTEGER NOT NULL,
                ran_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
        );
    }

    public function hasRun(string $migration): bool
    {
        $result = $this->connection->executeQuery(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE migration = ?',
            [$migration],
        );

        return (int) $result->fetchOne() > 0;
    }

    public function getLastBatchNumber(): int
    {
        $result = $this->connection->executeQuery(
            'SELECT MAX(batch) FROM ' . self::TABLE,
        );
        return (int) $result->fetchOne();
    }

    public function record(string $migration, string $package, int $batch): void
    {
        $this->connection->insert(self::TABLE, [
            'migration' => $migration,
            'package' => $package,
            'batch' => $batch,
        ]);
    }

    public function remove(string $migration): void
    {
        $this->connection->executeStatement(
            'DELETE FROM ' . self::TABLE . ' WHERE migration = ?',
            [$migration],
        );
    }

    /** @return list<array{migration: string, package: string, batch: int}> */
    public function getByBatch(int $batch): array
    {
        $result = $this->connection->executeQuery(
            'SELECT migration, package, batch FROM ' . self::TABLE . ' WHERE batch = ? ORDER BY id DESC',
            [$batch],
        );
        return $result->fetchAllAssociative();
    }

    /** @return list<string> */
    public function getCompleted(): array
    {
        $result = $this->connection->executeQuery(
            'SELECT migration FROM ' . self::TABLE . ' ORDER BY id',
        );
        return $result->fetchFirstColumn();
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration;

use Doctrine\DBAL\Connection;

/**
 * Read/write access to the `waaseyaa_migrations` ledger.
 *
 * **Post-WP09 schema (per spec §6 / §15 Q1 / Q2):**
 *
 * | Column      | Type            | Notes                                   |
 * |-------------|-----------------|------------------------------------------|
 * | id          | INTEGER PK      | Auto-increment surrogate.               |
 * | migration   | VARCHAR(255)    | Canonical ledger key (Q1).              |
 * | package     | VARCHAR(128)    | Composer package name.                  |
 * | batch       | INTEGER         | Apply batch grouping.                   |
 * | ran_at      | TIMESTAMP       | When the apply succeeded.               |
 * | checksum    | VARCHAR(64) NULL| SHA-256 over canonical SchemaDiff JSON. |
 * | diff_hash   | VARCHAR(64) NULL| SHA-256 over canonical compiled-plan JSON. |
 *
 * `checksum` / `diff_hash` are null for legacy migrations (no canonical
 * form) and for pre-WP09 rows. Verify mode (WP10) treats null as "trust
 * but log"; see `docs/adr/008-ledger-checksum-backfill.md`.
 *
 * `migration` remains the sole canonical key — Q1 ratified that there
 * is no parallel `migration_id` column.
 * @api
 */
final class MigrationRepository
{
    private const TABLE = 'waaseyaa_migrations';

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * Idempotently bring the ledger table up to the current schema.
     *
     * - Creates the table if it doesn't exist (full post-WP09 shape).
     * - Adds any missing columns to an existing table (handles upgrade
     *   from pre-WP09 installs).
     *
     * Bypasses the Migrator entirely — the ledger schema cannot route
     * through the unified DAG because writing the apply row needs the
     * new columns to exist first. Bootstrap calls this at startup.
     */
    public function createTable(): void
    {
        $this->connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration VARCHAR(255) NOT NULL,
                package VARCHAR(128) NOT NULL,
                batch INTEGER NOT NULL,
                ran_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                checksum VARCHAR(64) NULL,
                diff_hash VARCHAR(64) NULL
            )',
        );

        $this->ensureCurrentSchema();
        $this->ensureUniqueMigrationIdentity();
    }

    /**
     * Acquire SQLite writer ownership for one schema transition.
     *
     * This is deliberately the first operation inside the coordinator's outer
     * transaction. Creating/updating the singleton authority row upgrades the
     * deferred SQLite transaction to a writer before any ledger or plan read.
     */
    public function acquireSchemaAuthority(): void
    {
        $this->connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS waaseyaa_schema_authority (
                authority_id INTEGER PRIMARY KEY CHECK (authority_id = 1),
                generation INTEGER NOT NULL
            )',
        );
        $this->connection->executeStatement(
            'INSERT OR IGNORE INTO waaseyaa_schema_authority (authority_id, generation) VALUES (1, 0)',
        );
        $affected = $this->connection->executeStatement(
            'UPDATE waaseyaa_schema_authority SET generation = generation + 1 WHERE authority_id = 1',
        );
        if ($affected !== 1) {
            throw new \RuntimeException('[S1-DB101] Failed to acquire the schema mutation authority.');
        }
    }

    /**
     * Add any missing columns to an existing ledger table. Idempotent.
     *
     * Used to upgrade pre-WP09 installs whose `waaseyaa_migrations`
     * table predates the `checksum` / `diff_hash` additions. Safe to
     * call on every boot.
     */
    public function ensureCurrentSchema(): void
    {
        $existingColumns = array_column(
            $this->connection->executeQuery('PRAGMA table_info(' . self::TABLE . ')')->fetchAllAssociative(),
            'name',
        );

        if (! in_array('checksum', $existingColumns, true)) {
            $this->connection->executeStatement(
                'ALTER TABLE ' . self::TABLE . ' ADD COLUMN checksum VARCHAR(64) NULL',
            );
        }
        if (! in_array('diff_hash', $existingColumns, true)) {
            $this->connection->executeStatement(
                'ALTER TABLE ' . self::TABLE . ' ADD COLUMN diff_hash VARCHAR(64) NULL',
            );
        }
    }

    private function ensureUniqueMigrationIdentity(): void
    {
        $duplicate = $this->connection->executeQuery(
            'SELECT migration FROM ' . self::TABLE . ' GROUP BY migration HAVING COUNT(*) > 1 ORDER BY migration LIMIT 1',
        )->fetchOne();
        if (is_string($duplicate) && $duplicate !== '') {
            throw new \RuntimeException(sprintf(
                '[S1-DB102] Duplicate migration identity "%s" requires operator reconciliation before ledger upgrade.',
                $duplicate,
            ));
        }

        $this->connection->executeStatement(
            'CREATE UNIQUE INDEX IF NOT EXISTS waaseyaa_migrations_migration_unique ON '
            . self::TABLE . ' (migration)',
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

    /**
     * Record a successful apply.
     *
     * Pass `$checksum` and `$diffHash` for v2 migrations; legacy callers
     * omit them (or pass null). Both columns are nullable in the ledger
     * — only v2 produces canonical hashes.
     */
    public function record(
        string $migration,
        string $package,
        int $batch,
        ?string $checksum = null,
        ?string $diffHash = null,
    ): void {
        $row = [
            'migration' => $migration,
            'package' => $package,
            'batch' => $batch,
        ];
        if ($checksum !== null) {
            $row['checksum'] = $checksum;
        }
        if ($diffHash !== null) {
            $row['diff_hash'] = $diffHash;
        }

        $this->connection->insert(self::TABLE, $row);
    }

    public function remove(string $migration): void
    {
        $this->connection->executeStatement(
            'DELETE FROM ' . self::TABLE . ' WHERE migration = ?',
            [$migration],
        );
    }

    /**
     * Return the stored `checksum` for a migration, or null if no row
     * exists OR the row has a null checksum (pre-WP09 / legacy).
     *
     * Callers must distinguish "no row" from "row with null checksum"
     * via {@see hasRun()} when the difference matters.
     */
    public function getStoredChecksum(string $migration): ?string
    {
        $result = $this->connection->executeQuery(
            'SELECT checksum FROM ' . self::TABLE . ' WHERE migration = ?',
            [$migration],
        );

        $value = $result->fetchOne();

        return is_string($value) ? $value : null;
    }

    /**
     * Compare the stored checksum for a migration against an expected
     * value, returning a structured outcome for the verify command.
     */
    public function verifyChecksum(string $migration, string $expected): VerifyResult
    {
        if (! $this->hasRun($migration)) {
            return VerifyResult::Missing;
        }

        $stored = $this->getStoredChecksum($migration);
        if ($stored === null) {
            return VerifyResult::Unknown;
        }

        return $stored === $expected ? VerifyResult::Match : VerifyResult::Mismatch;
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

    /** @return list<array{migration: string, package: string, batch: int}> */
    public function getCompletedWithDetails(): array
    {
        $result = $this->connection->executeQuery(
            'SELECT migration, package, batch FROM ' . self::TABLE . ' ORDER BY id',
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

    /**
     * Iterate every ledger row with its hash columns for the verify CLI.
     *
     * @return list<LedgerRow>
     */
    public function allWithChecksums(): array
    {
        $rows = $this->connection->executeQuery(
            'SELECT migration, package, batch, checksum, diff_hash FROM ' . self::TABLE . ' ORDER BY id',
        )->fetchAllAssociative();

        $ledger = [];
        foreach ($rows as $row) {
            $ledger[] = new LedgerRow(
                migration: (string) $row['migration'],
                package: (string) $row['package'],
                batch: (int) $row['batch'],
                checksum: isset($row['checksum']) && is_string($row['checksum']) ? $row['checksum'] : null,
                diffHash: isset($row['diff_hash']) && is_string($row['diff_hash']) ? $row['diff_hash'] : null,
            );
        }

        return $ledger;
    }
}

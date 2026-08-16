<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Migration;

use Doctrine\DBAL\Connection;
use Waaseyaa\Foundation\Schema\Diff\CanonicalJson;

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
 * New legacy applies record an exact executable-source checksum and a
 * domain-separated procedural-plan hash. Null remains the honest sentinel for
 * historical pre-WP09 rows and is a strict verification failure under S1.
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
     * This is a schema mutation and may only be called by an explicit
     * initialization command or by the Migrator after it has acquired the
     * schema-mutation authority. Ordinary kernel boot and inspection paths
     * must use the read methods below, which never install or upgrade schema.
     */
    public function installOrUpgradeLedger(): void
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
     * Backward-compatible test/setup alias.
     *
     * Production composition must call {@see installOrUpgradeLedger()} only
     * from an explicitly mutation-authorized boundary.
     *
     * @deprecated Test/setup compatibility only; production code must use the
     *             named mutation boundary.
     */
    public function createTable(): void
    {
        $this->installOrUpgradeLedger();
    }

    /** Whether the ledger exists, without creating or upgrading anything. */
    public function ledgerExists(): bool
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?",
            [self::TABLE],
        ) === 1;
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
                generation INTEGER NOT NULL,
                schema_fingerprint VARCHAR(64) NULL,
                ledger_fingerprint VARCHAR(64) NULL,
                source_catalog_fingerprint VARCHAR(64) NULL
            )',
        );
        $this->ensureSchemaAuthorityManifestColumns();
        $this->connection->executeStatement(
            'INSERT OR IGNORE INTO waaseyaa_schema_authority (
                authority_id, generation, schema_fingerprint, ledger_fingerprint, source_catalog_fingerprint
            ) VALUES (1, 0, NULL, NULL, NULL)',
        );
        $affected = $this->connection->executeStatement(
            'UPDATE waaseyaa_schema_authority SET generation = generation + 1 WHERE authority_id = 1',
        );
        if ($affected !== 1) {
            throw new \RuntimeException('[S1-DB101] Failed to acquire the schema mutation authority.');
        }
    }

    /** Record the exact loaded migration catalogue inside the active transition. */
    public function recordSourceCatalogFingerprint(string $fingerprint): void
    {
        $this->assertSha256($fingerprint, 'source catalogue');
        $affected = $this->connection->executeStatement(
            'UPDATE waaseyaa_schema_authority SET source_catalog_fingerprint = ? WHERE authority_id = 1',
            [$fingerprint],
        );
        if ($affected !== 1) {
            throw new \RuntimeException('[S1-DB101] Schema authority source catalogue could not be recorded.');
        }
    }

    /** Record schema and ledger truth before the coordinator transaction commits. */
    public function recordSchemaManifest(string $schemaFingerprint): void
    {
        $this->assertSha256($schemaFingerprint, 'logical schema');
        $ledgerFingerprint = $this->ledgerFingerprint();
        $affected = $this->connection->executeStatement(
            'UPDATE waaseyaa_schema_authority
             SET schema_fingerprint = ?, ledger_fingerprint = ?
             WHERE authority_id = 1',
            [$schemaFingerprint, $ledgerFingerprint],
        );
        if ($affected !== 1) {
            throw new \RuntimeException('[S1-DB101] Schema authority manifest could not be recorded.');
        }
    }

    /** Read the authority manifest without installing or upgrading it. */
    public function schemaAuthorityManifest(): ?SchemaAuthorityManifest
    {
        $exists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'waaseyaa_schema_authority'",
        ) === 1;
        if (!$exists) {
            return null;
        }

        $columns = array_column(
            $this->connection->executeQuery('PRAGMA table_info(waaseyaa_schema_authority)')->fetchAllAssociative(),
            'name',
        );
        $required = ['schema_fingerprint', 'ledger_fingerprint', 'source_catalog_fingerprint'];
        if (array_diff($required, $columns) !== []) {
            throw new \RuntimeException('[S1-DB105] Schema authority manifest requires coordinator upgrade.');
        }

        $row = $this->connection->fetchAssociative(
            'SELECT schema_fingerprint, ledger_fingerprint, source_catalog_fingerprint
             FROM waaseyaa_schema_authority WHERE authority_id = 1',
        );
        if ($row === false) {
            return null;
        }

        return new SchemaAuthorityManifest(
            schemaFingerprint: is_string($row['schema_fingerprint']) ? $row['schema_fingerprint'] : null,
            ledgerFingerprint: is_string($row['ledger_fingerprint']) ? $row['ledger_fingerprint'] : null,
            sourceCatalogFingerprint: is_string($row['source_catalog_fingerprint'])
                ? $row['source_catalog_fingerprint']
                : null,
        );
    }

    public function currentLogicalSchemaFingerprint(): string
    {
        return LogicalSchemaFingerprint::capture($this->connection);
    }

    public function currentLedgerFingerprint(): string
    {
        if (!$this->ledgerExists()) {
            return hash('sha256', CanonicalJson::encode([
                'domain' => 'waaseyaa.migration-ledger.v1',
                'state' => 'absent',
            ]));
        }

        return $this->ledgerFingerprint();
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

    private function ensureSchemaAuthorityManifestColumns(): void
    {
        $columns = array_column(
            $this->connection->executeQuery('PRAGMA table_info(waaseyaa_schema_authority)')->fetchAllAssociative(),
            'name',
        );
        foreach (['schema_fingerprint', 'ledger_fingerprint', 'source_catalog_fingerprint'] as $column) {
            if (!in_array($column, $columns, true)) {
                $this->connection->executeStatement(sprintf(
                    'ALTER TABLE waaseyaa_schema_authority ADD COLUMN %s VARCHAR(64) NULL',
                    $column,
                ));
            }
        }
    }

    private function ledgerFingerprint(): string
    {
        if (!$this->assertReadableLedger()) {
            throw new \RuntimeException('[S1-DB105] Cannot fingerprint an uninstalled migration ledger.');
        }
        $rows = $this->connection->executeQuery(
            'SELECT migration, package, batch, checksum, diff_hash FROM ' . self::TABLE . ' ORDER BY migration',
        )->fetchAllAssociative();
        $canonical = array_map(static fn(array $row): array => [
            'migration' => (string) $row['migration'],
            'package' => (string) $row['package'],
            'batch' => (int) $row['batch'],
            'checksum' => is_string($row['checksum']) ? $row['checksum'] : null,
            'diff_hash' => is_string($row['diff_hash']) ? $row['diff_hash'] : null,
        ], $rows);

        return hash('sha256', CanonicalJson::encode([
            'domain' => 'waaseyaa.migration-ledger.v1',
            'rows' => $canonical,
        ]));
    }

    private function assertSha256(string $fingerprint, string $label): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid %s SHA-256 fingerprint.', $label));
        }
    }

    public function hasRun(string $migration): bool
    {
        if (!$this->assertReadableLedger()) {
            return false;
        }
        $result = $this->connection->executeQuery(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE migration = ?',
            [$migration],
        );

        return (int) $result->fetchOne() > 0;
    }

    public function getLastBatchNumber(): int
    {
        if (!$this->assertReadableLedger()) {
            return 0;
        }
        $result = $this->connection->executeQuery(
            'SELECT MAX(batch) FROM ' . self::TABLE,
        );
        return (int) $result->fetchOne();
    }

    /**
     * Record a successful apply.
     *
     * Every coordinated apply passes `$checksum` and `$diffHash`. The columns
     * remain nullable only so historical pre-WP09 rows can be represented
     * honestly and refused by strict verification.
     */
    public function record(
        string $migration,
        string $package,
        int $batch,
        ?string $checksum = null,
        ?string $diffHash = null,
    ): void {
        $this->requireWritableLedger();
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
        $this->requireWritableLedger();
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
        if (!$this->assertReadableLedger()) {
            return null;
        }
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
        if (!$this->assertReadableLedger()) {
            return [];
        }
        $result = $this->connection->executeQuery(
            'SELECT migration, package, batch FROM ' . self::TABLE . ' WHERE batch = ? ORDER BY id DESC',
            [$batch],
        );
        return $result->fetchAllAssociative();
    }

    /** @return list<array{migration: string, package: string, batch: int}> */
    public function getCompletedWithDetails(): array
    {
        if (!$this->assertReadableLedger()) {
            return [];
        }
        $result = $this->connection->executeQuery(
            'SELECT migration, package, batch FROM ' . self::TABLE . ' ORDER BY id',
        );
        return $result->fetchAllAssociative();
    }

    /** @return list<string> */
    public function getCompleted(): array
    {
        if (!$this->assertReadableLedger()) {
            return [];
        }
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
        if (!$this->assertReadableLedger()) {
            return [];
        }
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

    /**
     * Validate the installed ledger using metadata reads only.
     *
     * A missing ledger represents a fresh database and is readable as an
     * empty ledger. An existing but stale or ambiguous ledger fails closed;
     * inspection must never silently repair it.
     */
    private function assertReadableLedger(): bool
    {
        if (!$this->ledgerExists()) {
            return false;
        }

        $columns = array_column(
            $this->connection->executeQuery('PRAGMA table_info(' . self::TABLE . ')')->fetchAllAssociative(),
            'name',
        );
        $required = ['id', 'migration', 'package', 'batch', 'ran_at', 'checksum', 'diff_hash'];
        $missing = array_values(array_diff($required, $columns));
        if ($missing !== []) {
            throw new \RuntimeException(sprintf(
                '[S1-DB105] Migration ledger inspection refused: schema upgrade required; missing columns: %s.',
                implode(', ', $missing),
            ));
        }

        $indexes = $this->connection->executeQuery(
            'PRAGMA index_list(' . self::TABLE . ')',
        )->fetchAllAssociative();
        $hasUniqueIdentity = false;
        foreach ($indexes as $index) {
            if (($index['name'] ?? null) === 'waaseyaa_migrations_migration_unique'
                && (int) ($index['unique'] ?? 0) === 1) {
                $hasUniqueIdentity = true;
                break;
            }
        }
        if (!$hasUniqueIdentity) {
            throw new \RuntimeException(
                '[S1-DB105] Migration ledger inspection refused: the canonical migration identity constraint is missing.',
            );
        }

        $duplicate = $this->connection->fetchOne(
            'SELECT migration FROM ' . self::TABLE . ' GROUP BY migration HAVING COUNT(*) > 1 ORDER BY migration LIMIT 1',
        );
        if (is_string($duplicate) && $duplicate !== '') {
            throw new \RuntimeException(sprintf(
                '[S1-DB102] Duplicate migration identity "%s" requires operator reconciliation.',
                $duplicate,
            ));
        }

        return true;
    }

    private function requireWritableLedger(): void
    {
        if (!$this->assertReadableLedger()) {
            throw new \RuntimeException(
                '[S1-DB105] Migration ledger mutation refused: the ledger is not installed. Use the schema coordinator or db:init.',
            );
        }
    }
}

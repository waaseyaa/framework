<?php

declare(strict_types=1);

namespace Waaseyaa\Attachment\Schema;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\SchemaInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Manages the attachment table schema.
 *
 * Creates the full attachment table in one call, including all base columns
 * (matching what SqlSchemaHandler would auto-generate for this entity type)
 * and the attachment-specific columns and composite indexes.
 *
 * **Canonical schema authority.** For a `sql-blob` backend entity type (the
 * default, and what `Attachment` uses — see {@see \Waaseyaa\Attachment\Attachment}),
 * the generic entity-storage schema-sync path (`SqlSchemaHandler`, driven by
 * `EntityTypeManagerFactory` at kernel boot and by `EntitySchemaSync` at
 * CLI `db:init`/`schema:sync`) materializes ONLY the framework-standard base
 * columns every content entity gets — `id`, `uuid`, `bundle`, the label
 * column (`filename` here), `langcode`, `_data` — because it has no
 * knowledge of any package's `#[Field]`-declared entity-level columns for
 * that backend (that materialization path exists only for the `sql-column`
 * backend, via `SqlColumnSchemaBuilder`). This class is the CANONICAL and
 * ONLY provider of the attachment-specific columns
 * (`parent_entity_type`, `parent_entity_id`, `is_active`, `created_at`,
 * `updated_at`) and the composite/partial indexes below. It is wired into
 * every real kernel boot by {@see \Waaseyaa\Attachment\AttachmentServiceProvider::boot()}.
 *
 * {@see ensureTable()} is written to converge to this canonical shape
 * regardless of which path creates the base table first: if the table does
 * not exist yet, {@see createTable()} builds it complete (base + attachment
 * columns + composite indexes) in one call; if the table already exists —
 * e.g. because the generic sql-blob path materialized the base-only table
 * first (a lazy `getRepository('attachment')` call racing ahead of this
 * package's `boot()`, or a pre-existing install from before this class was
 * wired in) — {@see ensureColumns()} / {@see ensureIndexes()} additively
 * add the missing attachment-specific columns/indexes onto the existing
 * table, and {@see backfillNewColumnsFromDataBlob()} copies pre-existing
 * rows' values for those columns out of the `_data` JSON blob so healed
 * rows keep their data (real columns win over `_data` at read time).
 * Either ordering converges to the same final shape and data.
 *
 * Columns:
 *   - id               INTEGER PK AUTOINCREMENT
 *   - uuid             VARCHAR(128) UNIQUE NOT NULL
 *   - bundle           VARCHAR(128) NOT NULL DEFAULT ''
 *   - filename         VARCHAR(255) NOT NULL DEFAULT '' (label key)
 *   - langcode         VARCHAR(12) NOT NULL DEFAULT 'en'
 *   - parent_entity_type VARCHAR(64) NOT NULL DEFAULT ''
 *   - parent_entity_id   VARCHAR(255) NOT NULL DEFAULT ''
 *   - is_active          INTEGER NOT NULL DEFAULT 0
 *   - created_at         INTEGER NOT NULL DEFAULT 0
 *   - updated_at         INTEGER NOT NULL DEFAULT 0
 *   - _data              TEXT NOT NULL DEFAULT '{}' (JSON blob: filename, content_type, size, storage_uri, checksum)
 *
 * Indexes:
 *   - UNIQUE on uuid
 *   - Composite on (parent_entity_type, parent_entity_id)
 *   - Composite on (parent_entity_type, parent_entity_id, is_active) — fast active lookup
 *   - Partial UNIQUE on (parent_entity_type, parent_entity_id) WHERE is_active = 1,
 *     on platforms that support partial indexes (SQLite, PostgreSQL) — see
 *     {@see ensureActivePartialUniqueIndex()}. This is a backstop, not the
 *     primary invariant mechanism; it is a no-op (with a logged warning) on
 *     MySQL/MariaDB, which have no partial-index support at all.
 *
 * @api
 */
final class AttachmentSchema
{
    private const TABLE = 'attachment';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly DatabaseInterface $database,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Ensures the attachment table exists with all required columns and indexes.
     *
     * Idempotent, and self-healing regardless of call order: when the table
     * does not exist, {@see createTable()} builds the complete canonical
     * shape in one call. When it already exists — most likely because the
     * generic sql-blob schema-sync path materialized the base-only table
     * first (see the class docblock) — the heal branch additively adds the
     * attachment-specific columns/indexes rather than silently no-op'ing on
     * an incomplete table, AND backfills each newly-added column's VALUES
     * from the `_data` JSON blob ({@see backfillNewColumnsFromDataBlob()}).
     * The value backfill is load-bearing, not cosmetic: rows written under
     * the degraded schema carry their parent linkage / active flag /
     * timestamps in the blob, and `SqlStorageDriver::mergeFromRead()` lets
     * real columns WIN over `_data` on key collision — adding the columns
     * with their static defaults ('' / 0) and NOT backfilling would silently
     * blank every pre-existing row at hydration (listFor() stops finding it;
     * the download router's parent-delegated access check 404s it forever).
     *
     * The whole heal is best-effort (try/catch + logged warning, mirroring
     * {@see ensureActivePartialUniqueIndex()}'s posture): it runs on every
     * kernel boot via `AttachmentServiceProvider::boot()`, and a platform
     * quirk or partial failure must degrade loudly in the log — never crash
     * boot. Cost when there is nothing to heal: a fresh table skips the
     * branch entirely; an already-healed table does five fieldExists()
     * probes and two index-catalog COUNTs, no row reads.
     */
    public function ensureTable(): void
    {
        $schema = $this->database->schema();

        if (!$schema->tableExists(self::TABLE)) {
            $this->createTable($schema);
        } else {
            try {
                $addedColumns = $this->ensureColumns($schema);
                if ($addedColumns !== []) {
                    $this->backfillNewColumnsFromDataBlob($addedColumns);
                }
                $this->ensureIndexes($schema);
            } catch (\Throwable $e) {
                $this->logger->warning(\sprintf(
                    'AttachmentSchema: best-effort self-heal of the "%s" table failed: %s. '
                    . 'The table may be missing attachment-specific columns/indexes or '
                    . 'backfilled values; AttachmentRepository raw-column operations '
                    . '(setActive(), demoteSiblings()) may misbehave until the schema is '
                    . 'healed — re-run db:init or fix the underlying error.',
                    self::TABLE,
                    $e->getMessage(),
                ));
            }
        }

        // Idempotent (CREATE ... IF NOT EXISTS) regardless of whether the
        // table already existed — re-running against a pre-existing install
        // materializes the backstop index without touching table creation.
        $this->ensureActivePartialUniqueIndex();
    }

    private function createTable(SchemaInterface $schema): void
    {
        $schema->createTable(self::TABLE, [
            'fields' => [
                'id' => [
                    'type' => 'serial',
                    'not null' => true,
                ],
                'uuid' => [
                    'type' => 'varchar',
                    'length' => 128,
                    'not null' => true,
                    'default' => '',
                ],
                'bundle' => [
                    'type' => 'varchar',
                    'length' => 128,
                    'not null' => true,
                    'default' => '',
                ],
                'filename' => [
                    'type' => 'varchar',
                    'length' => 255,
                    'not null' => true,
                    'default' => '',
                ],
                'langcode' => [
                    'type' => 'varchar',
                    'length' => 12,
                    'not null' => true,
                    'default' => 'en',
                ],
                'parent_entity_type' => [
                    'type' => 'varchar',
                    'length' => 64,
                    'not null' => true,
                    'default' => '',
                ],
                'parent_entity_id' => [
                    'type' => 'varchar',
                    'length' => 255,
                    'not null' => true,
                    'default' => '',
                ],
                'is_active' => [
                    'type' => 'int',
                    'not null' => true,
                    'default' => 0,
                ],
                'created_at' => [
                    'type' => 'int',
                    'not null' => true,
                    'default' => 0,
                ],
                'updated_at' => [
                    'type' => 'int',
                    'not null' => true,
                    'default' => 0,
                ],
                '_data' => [
                    'type' => 'text',
                    'not null' => true,
                    'default' => '{}',
                ],
            ],
            'primary key' => ['id'],
            'unique keys' => [
                self::TABLE . '_uuid' => ['uuid'],
            ],
            'indexes' => [
                self::TABLE . '_bundle' => ['bundle'],
                self::TABLE . '_parent' => ['parent_entity_type', 'parent_entity_id'],
                self::TABLE . '_parent_active' => ['parent_entity_type', 'parent_entity_id', 'is_active'],
            ],
        ]);
    }

    /**
     * Additively adds the attachment-specific columns onto an
     * ALREADY-EXISTING `attachment` table (see {@see ensureTable()}).
     * Mirrors the base-column shapes in {@see createTable()} exactly;
     * skips any column that is already present.
     *
     * @return list<string> The names of the columns that were actually
     *   added (empty when the table was already complete) — the caller
     *   backfills exactly these from the `_data` blob.
     */
    private function ensureColumns(SchemaInterface $schema): array
    {
        $columns = [
            'parent_entity_type' => [
                'type' => 'varchar',
                'length' => 64,
                'not null' => true,
                'default' => '',
            ],
            'parent_entity_id' => [
                'type' => 'varchar',
                'length' => 255,
                'not null' => true,
                'default' => '',
            ],
            'is_active' => [
                'type' => 'int',
                'not null' => true,
                'default' => 0,
            ],
            'created_at' => [
                'type' => 'int',
                'not null' => true,
                'default' => 0,
            ],
            'updated_at' => [
                'type' => 'int',
                'not null' => true,
                'default' => 0,
            ],
        ];

        $added = [];
        foreach ($columns as $name => $spec) {
            if ($schema->fieldExists(self::TABLE, $name)) {
                continue;
            }
            $schema->addField(self::TABLE, $name, $spec);
            $added[] = $name;
        }

        return $added;
    }

    /**
     * Backfills newly-added columns' VALUES from each row's `_data` blob.
     *
     * Rows written under the degraded (base-only) schema carry
     * parent_entity_type / parent_entity_id / is_active / created_at /
     * updated_at inside the `_data` JSON blob (SqlStorageDriver routes any
     * value without a real column there). Once the real columns exist,
     * `mergeFromRead()` lets column values win over blob values on key
     * collision — so the freshly-added columns' static defaults would
     * shadow the real data unless copied out of the blob first. Runs ONLY
     * for the columns that {@see ensureColumns()} just added, and only
     * writes a column when the blob actually carries a value for it.
     *
     * Portability: no `json_extract` SQL (syntax diverges across
     * platforms) — rows are read via the query builder and decoded in PHP,
     * with one UPDATE per row that needs backfill. This is a one-time heal
     * (subsequent boots find the columns present and never reach here), so
     * per-row UPDATEs are acceptable; blob keys intentionally stay in
     * `_data` (harmless: columns win on read, and the next entity save
     * rebuilds the blob without column-routed keys).
     *
     * Value interpretation:
     *   - parent_entity_type / parent_entity_id: scalar blob values cast to
     *     string; non-scalar garbage is skipped.
     *   - is_active: the strict AttachmentActiveInvariant::isActive()
     *     allow-list (true / 1 / '1' → 1); anything else — including
     *     PHP-truthy garbage like the string 'false' — backfills as 0.
     *   - created_at / updated_at: numeric blob values cast to int;
     *     non-numeric garbage is skipped.
     *
     * @param list<string> $addedColumns
     */
    private function backfillNewColumnsFromDataBlob(array $addedColumns): void
    {
        $rows = $this->database->select(self::TABLE, 'a')
            ->fields('a', ['id', '_data'])
            ->execute();

        $healedCount = 0;
        foreach ($rows as $row) {
            $raw = $row['_data'] ?? null;
            if (!\is_string($raw) || $raw === '') {
                continue;
            }
            try {
                $blob = json_decode($raw, associative: true, flags: \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue; // Corrupt blob: nothing recoverable for this row.
            }
            if (!\is_array($blob)) {
                continue;
            }

            $updates = [];
            foreach ($addedColumns as $column) {
                if (!\array_key_exists($column, $blob)) {
                    continue;
                }
                $value = $blob[$column];
                $converted = match ($column) {
                    'parent_entity_type', 'parent_entity_id' => \is_scalar($value) ? (string) $value : null,
                    'is_active' => \in_array($value, [true, 1, '1'], true) ? 1 : 0,
                    'created_at', 'updated_at' => is_numeric($value) ? (int) $value : null,
                    default => null,
                };
                if ($converted !== null) {
                    $updates[$column] = $converted;
                }
            }

            if ($updates === []) {
                continue;
            }

            $this->database->update(self::TABLE)
                ->fields($updates)
                ->condition('id', $row['id'])
                ->execute();
            ++$healedCount;
        }

        if ($healedCount > 0) {
            $this->logger->info(\sprintf(
                'AttachmentSchema: self-heal backfilled %d pre-existing "%s" row(s) — copied '
                . '%s values out of the _data blob into the newly-added real columns.',
                $healedCount,
                self::TABLE,
                implode(', ', $addedColumns),
            ));
        }
    }

    /**
     * Additively adds the two composite indexes onto an ALREADY-EXISTING
     * `attachment` table (see {@see ensureTable()}). The partial unique
     * active-row index is handled separately by
     * {@see ensureActivePartialUniqueIndex()} — it is always (re)ensured,
     * not just on this branch.
     *
     * On a platform this class cannot identify, index backfill is SKIPPED
     * with a logged warning (indexes are a performance concern, not a
     * correctness one — better to run without them than to gamble on a
     * catalog query that may explode). Note: this and
     * `RelationshipSchemaManager::ensureIndexes()` share the
     * additive-backfill shape, but that class has no production caller —
     * this heal path is the first LIVE use of the pattern, hence the
     * platform hardening here that its inspiration never needed.
     */
    private function ensureIndexes(SchemaInterface $schema): void
    {
        $indexes = [
            self::TABLE . '_parent' => ['parent_entity_type', 'parent_entity_id'],
            self::TABLE . '_parent_active' => ['parent_entity_type', 'parent_entity_id', 'is_active'],
        ];

        foreach ($indexes as $name => $fields) {
            $exists = $this->indexExists($name);
            if ($exists === null) {
                $this->logger->warning(\sprintf(
                    'AttachmentSchema: cannot probe index existence on unrecognized platform; '
                    . 'skipping composite-index backfill on "%s" (queries still work, unindexed).',
                    self::TABLE,
                ));

                return;
            }
            if ($exists) {
                continue;
            }
            $schema->addIndex(self::TABLE, $name, $fields);
        }
    }

    /**
     * Platform-aware existence probe for a named index, following the same
     * platform-detection approach as {@see ensureActivePartialUniqueIndex()}:
     * each supported platform gets its own catalog query (`sqlite_master`
     * exists ONLY on SQLite — the naive first cut of this probe crashed
     * kernel boot on PostgreSQL/MySQL). Returns null when the platform is
     * unrecognized so the caller can skip rather than guess.
     */
    private function indexExists(string $name): ?bool
    {
        [$sql, $args] = match ($this->detectDatabasePlatform()) {
            'sqlite' => [
                "SELECT COUNT(*) AS cnt FROM sqlite_master WHERE type = 'index' AND name = ?",
                [$name],
            ],
            'postgresql' => [
                'SELECT COUNT(*) AS cnt FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [self::TABLE, $name],
            ],
            'mysql', 'mariadb' => [
                'SELECT COUNT(*) AS cnt FROM information_schema.statistics '
                . 'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [self::TABLE, $name],
            ],
            default => [null, []],
        };

        if ($sql === null) {
            return null;
        }

        $data = iterator_to_array($this->database->query($sql, $args), false);

        return (int) ($data[0]['cnt'] ?? 0) > 0;
    }

    /**
     * Belt-and-suspenders backstop for the at-most-one-active invariant:
     *
     *   CREATE UNIQUE INDEX <table>_one_active_per_parent
     *       ON <table>(parent_entity_type, parent_entity_id) WHERE is_active = 1
     *
     * This is NOT the primary enforcement mechanism — that is the save-path
     * guard ({@see \Waaseyaa\Attachment\AttachmentRepository::save()} /
     * {@see \Waaseyaa\Attachment\AttachmentActiveGuardListener}), which is
     * the only mechanism that runs on every platform. This index is an
     * additional hard backstop available where the platform supports
     * partial indexes:
     *
     *   - SQLite (>=3.8) and PostgreSQL (>=9.0) support partial indexes
     *     natively — a second concurrent writer attempting to set
     *     `is_active = 1` for a parent that already has an active row fails
     *     the INSERT/UPDATE outright with a unique-constraint violation,
     *     which closes the residual cross-process race documented on
     *     {@see \Waaseyaa\Attachment\AttachmentActiveGuardListener} for
     *     those two platforms.
     *   - MySQL/MariaDB have no partial-index support at all. This method
     *     is a no-op (with a logged warning) on those platforms; the
     *     invariant there rests entirely on the save-path guard plus
     *     {@see \Waaseyaa\Attachment\AttachmentRepository::getActive()}'s
     *     detection.
     *
     * Best-effort: wrapped in try/catch (unlike
     * `SqlSchemaHandler::ensureSqlBlobTranslatablePartialUuidIndex()`, which
     * this mirrors) because this index is an optional hardening layer, not
     * load-bearing schema — a platform this code fails to recognize must
     * not block `ensureTable()`/install.
     */
    private function ensureActivePartialUniqueIndex(): void
    {
        $indexName = self::TABLE . '_one_active_per_parent';

        $quote = $this->database instanceof DBALDatabase
            ? fn(string $id): string => $this->database->quoteIdentifier($id)
            : static fn(string $id): string => '"' . str_replace('"', '""', $id) . '"';

        $platform = $this->detectDatabasePlatform();

        if ($platform === 'mysql' || $platform === 'mariadb') {
            $this->logger->warning(\sprintf(
                'AttachmentSchema: MySQL/MariaDB does not support partial unique indexes; '
                . 'the at-most-one-active invariant on "%s" relies solely on the save-path '
                . 'guard (AttachmentRepository::save() / AttachmentActiveGuardListener) and '
                . 'AttachmentRepository::getActive() detection on this platform.',
                self::TABLE,
            ));

            return;
        }

        $sql = \sprintf(
            'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (%s, %s) WHERE %s = 1',
            $quote($indexName),
            $quote(self::TABLE),
            $quote('parent_entity_type'),
            $quote('parent_entity_id'),
            $quote('is_active'),
        );

        try {
            $this->database->query($sql);
        } catch (\Throwable $e) {
            $this->logger->warning(\sprintf(
                'AttachmentSchema: failed to materialize the partial unique active-row index '
                . 'on "%s" (platform "%s"): %s. The at-most-one-active invariant falls back to '
                . 'the save-path guard and getActive() detection alone.',
                self::TABLE,
                $platform,
                $e->getMessage(),
            ));
        }
    }

    /**
     * Best-effort platform discovery for partial-index emission.
     *
     * Returns 'sqlite', 'postgresql', 'mysql', 'mariadb', or 'unknown'.
     * When the database is not a DBALDatabase (e.g. a test stub), returns
     * 'sqlite' which yields the standards-compliant partial index syntax.
     */
    private function detectDatabasePlatform(): string
    {
        $db = $this->database;
        if (!$db instanceof DBALDatabase) {
            return 'sqlite';
        }
        try {
            $platform = $db->getConnection()->getDatabasePlatform();
            $platformClass = strtolower($platform::class);
        } catch (\Throwable) {
            return 'unknown';
        }
        if (str_contains($platformClass, 'sqlite')) {
            return 'sqlite';
        }
        if (str_contains($platformClass, 'postgres')) {
            return 'postgresql';
        }
        if (str_contains($platformClass, 'mariadb')) {
            return 'mariadb';
        }
        if (str_contains($platformClass, 'mysql')) {
            return 'mysql';
        }
        return 'unknown';
    }
}

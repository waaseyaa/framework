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
 * wired in) — {@see healMissingColumns()} / {@see ensureIndexes()}
 * additively add the missing attachment-specific columns/indexes onto the
 * existing table, and {@see backfillNewColumnsFromDataBlob()} copies
 * pre-existing rows' values for those columns out of the `_data` JSON blob
 * so healed rows keep their data (real columns win over `_data` at read
 * time). Either ordering converges to the same final shape and data.
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

    /**
     * The attachment-specific columns this class owns — mirrors the shapes
     * in {@see createTable()} exactly; used by the heal branch to detect
     * and add whatever the generic base-only table is missing.
     */
    private const HEAL_COLUMNS = [
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
     * Failure posture (final review round):
     *
     *   - Column adds + value backfill run in ONE database transaction
     *     ({@see healMissingColumns()}). On SQLite and PostgreSQL, DDL is
     *     transactional, so a mid-backfill failure rolls the column adds
     *     back too — the next boot re-detects the missing columns and the
     *     whole heal retries cleanly (convergent). On MySQL/MariaDB, DDL
     *     implicitly commits, so a mid-backfill failure strands the added
     *     columns and the backfill cannot re-trigger; the warning states
     *     the honest per-platform recovery (automatic retry vs. manual
     *     blob→column copy).
     *   - The partial backstop index is created LAST, inside the same
     *     try/catch — a failed heal must never leave the partial index in
     *     place ahead of the composite indexes: the first cut did exactly
     *     that, and the next boot's DBALSchema::addIndex()
     *     introspect-diff-RECREATE then stripped the partial index's WHERE
     *     clause and silently dropped the uuid unique constraint
     *     mid-rebuild. Heal-path index creation therefore NEVER routes
     *     through DBAL's recreate machinery — see {@see ensureIndexes()}.
     *   - The whole heal is best-effort (try/catch + logged warning,
     *     mirroring {@see ensureActivePartialUniqueIndex()}'s posture): it
     *     runs on every kernel boot via `AttachmentServiceProvider::boot()`,
     *     and a platform quirk or partial failure must degrade loudly in
     *     the log — never crash boot.
     *
     * Cost when there is nothing to heal: a fresh table skips the heal
     * branch entirely; an already-healed table does five fieldExists()
     * probes plus idempotent CREATE INDEX IF NOT EXISTS statements (or one
     * catalog probe per index on MySQL/MariaDB) — no row reads, no
     * transaction.
     */
    public function ensureTable(): void
    {
        $schema = $this->database->schema();

        if (!$schema->tableExists(self::TABLE)) {
            $this->createTable($schema);
            $this->ensureActivePartialUniqueIndex();

            return;
        }

        try {
            $this->healMissingColumns($schema);
            $this->ensureIndexes();
            // Deliberately LAST: the partial backstop may only materialize
            // once the column backfill and composite indexes succeeded.
            $this->ensureActivePartialUniqueIndex();
        } catch (\Throwable $e) {
            $recovery = match ($this->detectDatabasePlatform()) {
                'sqlite', 'postgresql' => 'DDL is transactional on this platform: the partial heal '
                    . 'was rolled back atomically and will retry automatically on the next boot.',
                'mysql', 'mariadb' => 'MySQL/MariaDB DDL implicitly commits: columns already added '
                    . 'cannot be rolled back, and the value backfill will NOT re-run once the '
                    . 'columns exist. Pre-existing rows keep their values in the _data JSON blob '
                    . 'but read as blank — heal them manually by copying blob values into the real '
                    . 'columns (per row: UPDATE attachment SET parent_entity_type/parent_entity_id/'
                    . 'created_at/updated_at from the matching _data keys; set is_active = 1 only '
                    . 'when the blob value is true, 1, or "1").',
                default => 'Unknown platform: verify the attachment table schema and row values manually.',
            };
            $this->logger->warning(\sprintf(
                'AttachmentSchema: best-effort self-heal of the "%s" table failed: %s. %s',
                self::TABLE,
                $e->getMessage(),
                $recovery,
            ));
        }
    }

    /**
     * Detects attachment-specific columns missing from an already-existing
     * table and — when any are missing — adds them AND backfills their
     * values from `_data` inside ONE database transaction, so on
     * transactional-DDL platforms (SQLite, PostgreSQL) a mid-backfill
     * failure rolls everything back and the heal retries convergently on
     * the next boot. See {@see ensureTable()} for the MySQL/MariaDB caveat
     * (implicit DDL commit makes the rollback partial there).
     *
     * No transaction is opened when nothing is missing — the steady state
     * from the boot after a successful heal onward.
     */
    private function healMissingColumns(SchemaInterface $schema): void
    {
        $missing = [];
        foreach (self::HEAL_COLUMNS as $name => $spec) {
            if (!$schema->fieldExists(self::TABLE, $name)) {
                $missing[$name] = $spec;
            }
        }

        if ($missing === []) {
            return;
        }

        $transaction = $this->database->transaction();
        try {
            foreach ($missing as $name => $spec) {
                $schema->addField(self::TABLE, $name, $spec);
            }
            $this->backfillNewColumnsFromDataBlob(array_keys($missing));

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }
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
     * {@see ensureActivePartialUniqueIndex()}, which the caller runs LAST.
     *
     * Deliberately raw platform-aware SQL, NEVER `SchemaInterface::addIndex()`
     * (final review round): `DBALSchema::addIndex()` implements index
     * addition as introspect-diff-RECREATE-TABLE on SQLite, and DBAL's
     * introspection STRIPS a partial index's WHERE clause — replaying it as
     * a FULL unique index that fails on legitimately-duplicate inactive
     * rows, mid-rebuild, silently dropping whichever indexes had not been
     * recreated yet (the uuid unique constraint, in the reproduced
     * sequence). Raw `CREATE INDEX IF NOT EXISTS` (SQLite ≥3.8,
     * PostgreSQL ≥9.5) touches nothing but the one index; MySQL/MariaDB
     * (no `IF NOT EXISTS` on stock MySQL 8) get an
     * `information_schema.statistics` existence probe (scoped by
     * `DATABASE()`, so no cross-schema false positives) followed by a plain
     * `CREATE INDEX`.
     *
     * On a platform this class cannot identify, index backfill is SKIPPED
     * with a logged warning (indexes are a performance concern, not a
     * correctness one — better to run without them than to gamble on
     * unknown catalog/DDL syntax). Note: `RelationshipSchemaManager` shares
     * the additive-index idea, but that class has no production caller —
     * this heal path is the first LIVE use of the pattern, hence the
     * platform hardening here that its inspiration never needed.
     */
    private function ensureIndexes(): void
    {
        $platform = $this->detectDatabasePlatform();
        if ($platform === 'unknown') {
            $this->logger->warning(\sprintf(
                'AttachmentSchema: unrecognized database platform; skipping composite-index '
                . 'backfill on "%s" (queries still work, unindexed).',
                self::TABLE,
            ));

            return;
        }

        $indexes = [
            self::TABLE . '_parent' => ['parent_entity_type', 'parent_entity_id'],
            self::TABLE . '_parent_active' => ['parent_entity_type', 'parent_entity_id', 'is_active'],
        ];

        $isMysqlFamily = $platform === 'mysql' || $platform === 'mariadb';

        foreach ($indexes as $name => $fields) {
            if ($isMysqlFamily && $this->mysqlIndexExists($name)) {
                continue;
            }

            $this->database->query(\sprintf(
                'CREATE INDEX %s%s ON %s (%s)',
                $isMysqlFamily ? '' : 'IF NOT EXISTS ',
                $this->database->quoteIdentifier($name),
                $this->database->quoteIdentifier(self::TABLE),
                implode(', ', array_map(
                    fn(string $field): string => $this->database->quoteIdentifier($field),
                    $fields,
                )),
            ));
        }
    }

    /**
     * Index-existence probe for the MySQL family only — stock MySQL 8 has
     * no `CREATE INDEX IF NOT EXISTS`, so {@see ensureIndexes()} probes
     * first there. SQLite/PostgreSQL use `IF NOT EXISTS` directly and never
     * call this (which also removes the first cut's `sqlite_master`-
     * everywhere crash and its `pg_indexes` multi-schema false positive —
     * this probe is scoped to the current schema via `DATABASE()`).
     */
    private function mysqlIndexExists(string $name): bool
    {
        $data = iterator_to_array($this->database->query(
            'SELECT COUNT(*) AS cnt FROM information_schema.statistics '
            . 'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [self::TABLE, $name],
        ), false);

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
     *   - SQLite (>=3.8) and PostgreSQL (>=9.5 — partial indexes exist
     *     since 9.0, but the `CREATE INDEX IF NOT EXISTS` form this method
     *     emits needs 9.5) support this natively — a second concurrent
     *     writer attempting to set
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

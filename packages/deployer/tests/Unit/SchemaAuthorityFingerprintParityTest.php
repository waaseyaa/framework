<?php

declare(strict_types=1);

namespace Waaseyaa\Deployer\Tests\Unit;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Deployer\RuntimeState\SchemaAuthorityFingerprint;
use Waaseyaa\Foundation\Migration\LogicalSchemaFingerprint;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\SchemaMutationCoordinator;

/**
 * #3149 Rule 6: `packages/deployer/composer.json` deliberately requires
 * nothing beyond `ext-pdo` / `php` / `deployer/deployer`, so
 * {@see SchemaAuthorityFingerprint} cannot call
 * {@see \Waaseyaa\Foundation\Migration\LogicalSchemaFingerprint} or
 * `MigrationRepository`'s ledger fingerprint directly — doing so would pull
 * the full framework dependency graph into the isolated deploy-tools vendor
 * boundary consumer applications install the deployer recipe into. It
 * duplicates their algorithm instead, `require-dev`-only, and this test pins
 * that duplication byte-identical across fixtures so the two can never drift
 * apart unnoticed.
 *
 * @internal
 */
#[CoversClass(SchemaAuthorityFingerprint::class)]
final class SchemaAuthorityFingerprintParityTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/waaseyaa-fingerprint-parity-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    #[Test]
    public function logical_schema_fingerprint_matches_foundation_for_an_empty_database(): void
    {
        $path = $this->directory . '/empty.sqlite';
        $pdo = $this->open($path);

        self::assertSame(
            $this->foundationSchemaFingerprint($path),
            SchemaAuthorityFingerprint::logicalSchemaFingerprint($pdo),
        );
    }

    #[Test]
    public function logical_schema_fingerprint_matches_foundation_for_tables_indexes_triggers_and_null_sql_rows(): void
    {
        $path = $this->directory . '/rich.sqlite';
        $pdo = $this->open($path);
        $pdo->exec("CREATE TABLE widget (id INTEGER PRIMARY KEY, name TEXT NOT NULL DEFAULT 'x', CHECK (id > 0))");
        $pdo->exec('CREATE UNIQUE INDEX widget_name_unique ON widget (name)');
        $pdo->exec('CREATE TABLE widget_log (id INTEGER PRIMARY KEY, widget_id INTEGER, note TEXT)');
        $pdo->exec(
            'CREATE TRIGGER widget_audit AFTER INSERT ON widget
             BEGIN
                INSERT INTO widget_log (widget_id, note) VALUES (NEW.id, ' . "'created'" . ');
             END',
        );
        // sqlite_sequence is an internal table auto-created for
        // AUTOINCREMENT, but it is not a NULL-`sql` case: SQLite stores a
        // real `CREATE TABLE sqlite_sequence(name,seq)` for it, and both
        // implementations exclude it (and the auto-index SQLite creates for
        // every PRIMARY KEY / UNIQUE constraint, `sqlite_autoindex_*`) the
        // same way, by the `WHERE name NOT LIKE 'sqlite_%'` filter — the only
        // NULL-`sql` rows `sqlite_master`/`sqlite_schema` produce in
        // practice. There is consequently no reachable non-`sqlite_%`-named
        // row with a NULL `sql` to exercise the `?? "\0null"` /
        // `normalizeSql(null)` branch against; this fixture keeps
        // AUTOINCREMENT for its own sake (an extra internal table alongside
        // ordinary ones) without claiming it exercises that branch.
        $pdo->exec('CREATE TABLE counted (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT)');
        $pdo->exec("INSERT INTO counted (label) VALUES ('a')");
        // Mixed line endings and leading/trailing whitespace inside the raw
        // DDL text — normalizeSql() must fold these identically on both
        // sides, independent of how sqlite_master happens to store them.
        $pdo->exec("CREATE TABLE \r\n  spaced  \r\n (id INTEGER PRIMARY KEY)");

        self::assertSame(
            $this->foundationSchemaFingerprint($path),
            SchemaAuthorityFingerprint::logicalSchemaFingerprint($pdo),
        );
    }

    /**
     * A wider construct fixture than the one above: non-ASCII/Unicode
     * identifiers (a removed `JSON_UNESCAPED_UNICODE` would escape them
     * differently on whichever side lost it), `WITHOUT ROWID`, a partial
     * index, a quoted mixed-case identifier containing `/`, a view, CR/CRLF
     * embedded inside a trigger body, and FTS5 (which creates several
     * `_data`/`_idx`/`_content`/`_docsize`/`_config` shadow tables, none
     * `sqlite_%`-named, alongside the virtual table itself).
     */
    #[Test]
    public function logical_schema_fingerprint_matches_foundation_for_unicode_without_rowid_partial_index_view_crlf_trigger_and_fts5(): void
    {
        $path = $this->directory . '/exotic.sqlite';
        $pdo = $this->open($path);

        $pdo->exec('CREATE TABLE "wïdgét_日本語" (id INTEGER PRIMARY KEY, "nãme" TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE wr (id INTEGER PRIMARY KEY, label TEXT) WITHOUT ROWID');
        $pdo->exec('CREATE TABLE flagged (id INTEGER PRIMARY KEY, active INTEGER NOT NULL)');
        $pdo->exec('CREATE INDEX flagged_active_idx ON flagged (id) WHERE active = 1');
        $pdo->exec('CREATE TABLE "Mixed/Case" (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE VIEW flagged_view AS SELECT id FROM flagged WHERE active = 1');
        $pdo->exec("CREATE TRIGGER flagged_audit AFTER INSERT ON flagged\r\nBEGIN\r\n   SELECT 1;\r\nEND");
        $pdo->exec('CREATE VIRTUAL TABLE ft USING fts5(body)');

        self::assertSame(
            $this->foundationSchemaFingerprint($path),
            SchemaAuthorityFingerprint::logicalSchemaFingerprint($pdo),
        );
    }

    #[Test]
    public function ledger_fingerprint_matches_foundation_when_the_ledger_is_absent(): void
    {
        $path = $this->directory . '/no-ledger.sqlite';
        $pdo = $this->open($path);
        $pdo->exec('CREATE TABLE content (id INTEGER PRIMARY KEY)');

        self::assertSame(
            $this->foundationLedgerFingerprint($path),
            SchemaAuthorityFingerprint::ledgerFingerprint($pdo),
        );
    }

    #[Test]
    public function ledger_fingerprint_matches_foundation_for_a_populated_governed_ledger(): void
    {
        $path = $this->directory . '/governed.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        $repository = new MigrationRepository($connection);
        $coordinator = new SchemaMutationCoordinator($connection, $repository);
        $coordinator->execute(static function () use ($connection): void {
            $connection->executeStatement('CREATE TABLE content (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
            $connection->executeStatement(
                "INSERT INTO waaseyaa_migrations (migration, package, batch, ran_at, checksum, diff_hash)
                 VALUES ('2026_01_01_000001_create_content', 'app/content', 1, '2026-01-01 00:00:00', ?, ?)",
                [hash('sha256', 'checksum-fixture'), hash('sha256', 'diff-hash-fixture')],
            );
            // A pre-WP09 row with null checksum/diff_hash: both sides must
            // encode the SQL NULL the same way (PHP null, not the string "NULL").
            $connection->executeStatement(
                "INSERT INTO waaseyaa_migrations (migration, package, batch, ran_at, checksum, diff_hash)
                 VALUES ('2025_12_01_000000_legacy', 'app/content', 1, '2025-12-01 00:00:00', NULL, NULL)",
            );
        });
        $connection->close();

        $pdo = $this->open($path);
        self::assertSame(
            $this->foundationLedgerFingerprint($path),
            SchemaAuthorityFingerprint::ledgerFingerprint($pdo),
        );
    }

    /**
     * Rows inserted out of migration-name order (`c`, then `a`, then `b`): a
     * ledger query missing its own `ORDER BY migration` would return them in
     * rowid/insertion order instead, diverging from foundation's DBAL query,
     * which always orders by `migration`. Also mixes a string-shaped batch
     * (`'07'`), an integer checksum, a float diff_hash, and an empty-string
     * checksum in with the usual hex/`null` values — SQLite's column-affinity
     * coercion means both sides read back whatever was actually stored, so
     * this fixture is a parity guard against either implementation's own
     * `(int)` cast or `is_string()` null-handling diverging, not a claim
     * about what gets stored.
     */
    #[Test]
    public function ledger_fingerprint_matches_foundation_for_an_out_of_order_ledger_with_mixed_value_types(): void
    {
        $path = $this->directory . '/mixed-ledger.sqlite';
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        $repository = new MigrationRepository($connection);
        $coordinator = new SchemaMutationCoordinator($connection, $repository);
        $coordinator->execute(static function () use ($connection): void {
            $connection->executeStatement('CREATE TABLE content (id INTEGER PRIMARY KEY)');
            $connection->executeStatement(
                "INSERT INTO waaseyaa_migrations (migration, package, batch, ran_at, checksum, diff_hash)
                 VALUES ('c_migration', 'app/content', '07', '2026-01-03 00:00:00', 12345, 1.5)",
            );
            $connection->executeStatement(
                "INSERT INTO waaseyaa_migrations (migration, package, batch, ran_at, checksum, diff_hash)
                 VALUES ('a_migration', 'app/content', 1, '2026-01-01 00:00:00', '', NULL)",
            );
            $connection->executeStatement(
                'INSERT INTO waaseyaa_migrations (migration, package, batch, ran_at, checksum, diff_hash)
                 VALUES (?, ?, ?, ?, ?, ?)',
                ['b_migration', 'app/content', 2, '2026-01-02 00:00:00', hash('sha256', 'b-checksum'), hash('sha256', 'b-diff')],
            );
        });
        $connection->close();

        $pdo = $this->open($path);
        self::assertSame(
            $this->foundationLedgerFingerprint($path),
            SchemaAuthorityFingerprint::ledgerFingerprint($pdo),
        );
    }

    #[Test]
    public function both_fingerprints_match_foundation_for_a_real_preparer_output(): void
    {
        $artifactPath = $this->directory . '/artifact.sqlite';
        $artifactConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $artifactPath]);
        $repository = new MigrationRepository($artifactConnection);
        $coordinator = new SchemaMutationCoordinator($artifactConnection, $repository);
        $coordinator->execute(static function () use ($artifactConnection): void {
            $artifactConnection->executeStatement('CREATE TABLE content (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
            $artifactConnection->executeStatement("INSERT INTO content VALUES (1, 'artifact content')");
        });
        $artifactConnection->close();

        $servingPath = $this->directory . '/serving.sqlite';
        $serving = $this->open($servingPath);
        $serving->exec('CREATE TABLE content (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
        $serving->exec("INSERT INTO content VALUES (1, 'serving content')");
        $serving->exec('CREATE TABLE state (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $serving->exec("INSERT INTO state VALUES ('last_run', '2026-09-22')");
        $serving = null;

        $candidatePath = $this->directory . '/candidate.sqlite';
        new \Waaseyaa\Deployer\RuntimeState\SqliteArtifactPreparer(new \Waaseyaa\Deployer\RuntimeState\FrameworkRuntimeTableCatalogue())->prepare(
            $servingPath,
            $artifactPath,
            $candidatePath,
            ['content'],
        );

        $candidate = $this->open($candidatePath);
        self::assertSame(
            $this->foundationSchemaFingerprint($candidatePath),
            SchemaAuthorityFingerprint::logicalSchemaFingerprint($candidate),
        );
        self::assertSame(
            $this->foundationLedgerFingerprint($candidatePath),
            SchemaAuthorityFingerprint::ledgerFingerprint($candidate),
        );
    }

    private function foundationSchemaFingerprint(string $path): string
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        try {
            return LogicalSchemaFingerprint::capture($connection);
        } finally {
            $connection->close();
        }
    }

    private function foundationLedgerFingerprint(string $path): string
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        try {
            return new MigrationRepository($connection)->currentLedgerFingerprint();
        } finally {
            $connection->close();
        }
    }

    private function open(string $path): \PDO
    {
        return new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }
}

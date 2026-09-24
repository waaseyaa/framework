<?php

declare(strict_types=1);

namespace Waaseyaa\Deployer\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Deployer\RuntimeState\FrameworkRuntimeTableCatalogue;
use Waaseyaa\Deployer\RuntimeState\SchemaAuthorityFingerprint;
use Waaseyaa\Deployer\RuntimeState\SqliteArtifactPreparer;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\SchemaMutationCoordinator;

/**
 * #3149: the preparer must re-record and verify the aggregate schema
 * fingerprint after an artifact handoff, or the next code-only deployment's
 * S1 pre-state check refuses with `[S1-DB109]` even though nothing is
 * actually wrong — see #2548's 2026-09-22 and 2026-09-23 comments for the
 * Sheguiandah staging reproduction this fixture is built from.
 *
 * The `probe_*` tests below are an independent review's reproduction: they
 * drive `cloneSchema()`'s `name = ? OR tbl_name = ?` match through real
 * serving triggers, entirely through the public `prepare()` API. An earlier
 * version of this file claimed the guards they exercise were unreachable
 * except by direct construction; that claim was false, and these tests
 * replace it with proof.
 *
 * @internal
 */
#[CoversClass(SqliteArtifactPreparer::class)]
#[CoversClass(SchemaAuthorityFingerprint::class)]
final class SqliteArtifactPreparerSchemaAuthorityTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/waaseyaa-artifact-authority-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    /**
     * The exact acceptance-evidence reproduction from #3149: an artifact with
     * a fingerprinted manifest, a serving database with a runtime-preserved
     * table (`state`) the artifact lacks, `prepare()`, then the real S1
     * pre-state assertion against the prepared candidate — a code-only
     * preflight equivalent. On the parent commit this must fail closed with
     * `[S1-DB109]`; after the fix it must not raise.
     */
    #[Test]
    public function a_handoff_that_preserves_a_runtime_table_survives_the_next_code_only_preflight(): void
    {
        $artifactPath = $this->directory . '/artifact.sqlite';
        $artifactConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $artifactPath]);
        $this->governArtifact($artifactConnection);
        $artifactConnection->close();

        $servingPath = $this->directory . '/serving.sqlite';
        $serving = $this->open($servingPath);
        $serving->exec('CREATE TABLE content (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
        $serving->exec("INSERT INTO content VALUES (1, 'serving content')");
        // `state` (RuntimeTablePolicy::Preserve) is absent from the artifact
        // and present on serving: exactly the shape #2548 reproduced.
        $serving->exec('CREATE TABLE state (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $serving->exec("INSERT INTO state VALUES ('last_run', '2026-09-22')");
        $serving = null;

        $artifactPdoBefore = $this->open($artifactPath);
        $artifactGeneration = (int) $artifactPdoBefore->query(
            'SELECT generation FROM waaseyaa_schema_authority WHERE authority_id = 1',
        )->fetchColumn();

        $candidatePath = $this->directory . '/candidate.sqlite';
        new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
            $servingPath,
            $artifactPath,
            $candidatePath,
            ['content'],
        );

        self::assertFileExists($candidatePath, 'A refused preflight must not leave a candidate behind to inspect.');

        $candidateConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $candidatePath]);
        $repository = new MigrationRepository($candidateConnection);

        try {
            $repository->assertSchemaAuthorityPreState();
        } catch (\RuntimeException $refusal) {
            self::fail(sprintf(
                "The prepared candidate failed the S1 pre-state assertion it must pass:\n%s",
                $refusal->getMessage(),
            ));
        } finally {
            $candidateConnection->close();
        }

        // The candidate's own recorded manifest must describe the candidate,
        // not the artifact: a stale `schema_fingerprint` was exactly the
        // defect. `ledger_fingerprint`, `source_catalog_fingerprint`, and
        // `generation` stay byte-identical to the artifact's, because
        // `waaseyaa_migrations` and `waaseyaa_schema_authority` are both
        // `RuntimeTablePolicy::Artifact` and the preparer never touches them
        // beyond the artifact copy and the conditional `schema_fingerprint`
        // re-record.
        $candidatePdo = $this->open($candidatePath);
        $recorded = $candidatePdo->query(
            'SELECT generation, schema_fingerprint, ledger_fingerprint, source_catalog_fingerprint FROM waaseyaa_schema_authority WHERE authority_id = 1',
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(
            SchemaAuthorityFingerprint::logicalSchemaFingerprint($candidatePdo),
            $recorded['schema_fingerprint'],
        );
        self::assertSame($artifactGeneration, (int) $recorded['generation']);

        $artifactPdo = $this->open($artifactPath);
        $artifactRecorded = $artifactPdo->query(
            'SELECT ledger_fingerprint, source_catalog_fingerprint FROM waaseyaa_schema_authority WHERE authority_id = 1',
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertSame($artifactRecorded['ledger_fingerprint'], $recorded['ledger_fingerprint']);
        self::assertNotNull($artifactRecorded['source_catalog_fingerprint'], 'The fixture must record a real source_catalog_fingerprint, not compare NULL to NULL.');
        self::assertSame($artifactRecorded['source_catalog_fingerprint'], $recorded['source_catalog_fingerprint']);
    }

    #[Test]
    public function a_stale_artifact_schema_fingerprint_fails_closed_before_any_candidate_is_written(): void
    {
        $artifactPath = $this->directory . '/artifact.sqlite';
        $artifactConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $artifactPath]);
        $this->governArtifact($artifactConnection);
        $artifactConnection->close();

        // Corrupt the artifact's own manifest after governance: its recorded
        // schema_fingerprint no longer describes its own computed schema.
        $tamper = $this->open($artifactPath);
        $tamper->exec("UPDATE waaseyaa_schema_authority SET schema_fingerprint = 'stale' || schema_fingerprint WHERE authority_id = 1");
        $tamper = null;

        $servingPath = $this->directory . '/serving.sqlite';
        $serving = $this->open($servingPath);
        $serving->exec('CREATE TABLE content (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
        $serving = null;

        $candidatePath = $this->directory . '/candidate.sqlite';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stale');

        try {
            new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
                $servingPath,
                $artifactPath,
                $candidatePath,
                ['content'],
            );
        } finally {
            self::assertFileDoesNotExist($candidatePath, 'A stale artifact manifest must never bless a candidate.');
        }
    }

    /**
     * The schema half of the precondition (the test above) is only half the
     * proof: an artifact whose recorded `ledger_fingerprint` no longer
     * matches its own computed ledger must fail at the same precondition,
     * with the same "stale manifest" message — not slip through to commit
     * and only be caught later by `[S1-DB109]`.
     */
    #[Test]
    public function a_stale_artifact_ledger_fingerprint_fails_closed_at_the_precondition(): void
    {
        $artifactPath = $this->directory . '/artifact.sqlite';
        $artifactConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $artifactPath]);
        $this->governArtifact($artifactConnection);
        $artifactConnection->close();

        $tamper = $this->open($artifactPath);
        $tamper->exec("UPDATE waaseyaa_schema_authority SET ledger_fingerprint = 'stale' || ledger_fingerprint WHERE authority_id = 1");
        $tamper = null;

        $servingPath = $this->directory . '/serving.sqlite';
        $serving = $this->open($servingPath);
        $serving->exec('CREATE TABLE content (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
        $serving = null;

        $candidatePath = $this->directory . '/candidate.sqlite';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stale');

        try {
            new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
                $servingPath,
                $artifactPath,
                $candidatePath,
                ['content'],
            );
        } catch (\RuntimeException $refusal) {
            self::assertStringNotContainsString(
                'S1-DB109',
                $refusal->getMessage(),
                'A stale ledger fingerprint must fail at the precondition, not later at the post-commit S1-DB109 verification.',
            );

            throw $refusal;
        } finally {
            self::assertFileDoesNotExist($candidatePath, 'A stale artifact manifest must never bless a candidate.');
        }
    }

    /**
     * `waaseyaa_schema_authority` present but missing a required fingerprint
     * column is a pre-#2547 installation too old for this contract — not the
     * same thing as no manifest at all, and must not be silently skipped the
     * same way. Mirrors foundation's own `[S1-DB105]` refusal.
     */
    #[Test]
    public function a_manifest_table_missing_a_required_column_fails_closed_with_s1_db105(): void
    {
        $artifactPath = $this->directory . '/artifact.sqlite';
        $artifact = $this->open($artifactPath);
        $artifact->exec('CREATE TABLE content (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
        $artifact->exec(
            'CREATE TABLE waaseyaa_schema_authority (
                authority_id INTEGER PRIMARY KEY CHECK (authority_id = 1),
                generation INTEGER NOT NULL,
                schema_fingerprint VARCHAR(64) NULL,
                ledger_fingerprint VARCHAR(64) NULL
            )',
        );
        $artifact->exec('INSERT INTO waaseyaa_schema_authority (authority_id, generation) VALUES (1, 0)');
        $artifact = null;

        $servingPath = $this->directory . '/serving.sqlite';
        $serving = $this->open($servingPath);
        $serving->exec('CREATE TABLE content (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
        $serving = null;

        $candidatePath = $this->directory . '/candidate.sqlite';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('S1-DB105');

        try {
            new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
                $servingPath,
                $artifactPath,
                $candidatePath,
                ['content'],
            );
        } finally {
            self::assertFileDoesNotExist($candidatePath, 'A too-old manifest table must never bless a candidate.');
        }
    }

    /**
     * Review probe (a): `cloneSchema()` matches `name = ? OR tbl_name = ?`
     * for the runtime table it is cloning ("state"), so a serving trigger
     * literally NAMED "state" is cloned along with the `state` table itself
     * even though it is actually `ON content` — an application-owned table
     * the catalogue does not recognise at all. On the parent commit this
     * trigger was silently installed into the candidate with no refusal at
     * all (there was no reconciliation to notice it); this is therefore real
     * hardening, not only a test-strength fix. Proven here entirely through
     * the public `prepare()` API, replacing an earlier reflection-only test
     * that incorrectly claimed this shape was unreachable that way.
     */
    #[Test]
    public function probe_a_a_serving_trigger_named_after_a_preserved_table_but_on_a_different_table_fails_closed(): void
    {
        $artifactPath = $this->directory . '/artifact.sqlite';
        $artifactConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $artifactPath]);
        $this->governArtifact($artifactConnection);
        $artifactConnection->close();

        $servingPath = $this->directory . '/serving.sqlite';
        $serving = $this->open($servingPath);
        $serving->exec('CREATE TABLE state (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
        // SQLite resolves every table a trigger body references at CREATE
        // TRIGGER time, including the ON-clause table, so serving needs its
        // own "content" table for this to compile — it is otherwise
        // irrelevant, since the candidate's real "content" comes from the
        // artifact, not from serving.
        $serving->exec('CREATE TABLE content (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
        // Named "state" (the Preserve-policy table being cloned) but ON
        // "content" — an application table, not a catalogue table at all.
        $serving->exec('CREATE TRIGGER state AFTER INSERT ON content BEGIN SELECT 1; END');
        $serving = null;

        $candidatePath = $this->directory . '/candidate.sqlite';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('content');

        try {
            new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
                $servingPath,
                $artifactPath,
                $candidatePath,
                ['content'],
            );
        } finally {
            self::assertFileDoesNotExist($candidatePath, 'An unbounded schema difference must never leave a candidate behind.');
        }
    }

    /**
     * Review probe (b): a trigger genuinely `ON state` (so `cloneSchema()`
     * clones it via `tbl_name = 'state'` with no name trick needed) fires
     * during `copyRows()` and mutates `waaseyaa_schema_authority` directly.
     * The item-7 bind check — the candidate's own copied manifest row must
     * still equal exactly what was captured from the artifact before the
     * copy — catches this: the row `reconcileSchemaAuthority()` reads no
     * longer matches what was captured, whether or not the schema_fingerprint
     * it wrote happens to collide with the artifact's original value.
     */
    #[Test]
    public function probe_b_a_serving_trigger_on_the_preserved_table_that_mutates_the_schema_authority_row_fails_closed(): void
    {
        $artifactPath = $this->directory . '/artifact.sqlite';
        $artifactConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $artifactPath]);
        $this->governArtifact($artifactConnection);
        $artifactConnection->close();

        $servingPath = $this->directory . '/serving.sqlite';
        $serving = $this->open($servingPath);
        $serving->exec('CREATE TABLE state (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
        // Serving's own decoy waaseyaa_schema_authority: only needed so the
        // trigger body below compiles against this connection. The row the
        // trigger actually mutates lives in the candidate, cloned from the
        // artifact.
        $serving->exec(
            'CREATE TABLE waaseyaa_schema_authority (
                authority_id INTEGER PRIMARY KEY CHECK (authority_id = 1),
                schema_fingerprint VARCHAR(64) NULL
            )',
        );
        $serving->exec(
            "CREATE TRIGGER state_hijack AFTER INSERT ON state
             BEGIN
                UPDATE waaseyaa_schema_authority SET schema_fingerprint = 'hijacked-by-trigger' WHERE authority_id = 1;
             END",
        );
        $serving->exec("INSERT INTO state VALUES ('last_run', '2026-09-22')");
        $serving = null;

        $candidatePath = $this->directory . '/candidate.sqlite';

        $this->expectException(\RuntimeException::class);

        try {
            new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
                $servingPath,
                $artifactPath,
                $candidatePath,
                ['content'],
            );
        } finally {
            self::assertFileDoesNotExist($candidatePath, 'A candidate whose manifest was mutated mid-preparation must never survive.');
        }
    }

    /**
     * Review probe (c): a trigger `ON state` that inserts a rogue row into
     * `waaseyaa_migrations` instead — another `RuntimeTablePolicy::Artifact`
     * table, but a DATA mutation, not a schema one, so it has no
     * `sqlite_schema` footprint for `assertBoundedSchemaDifference()` to see
     * and does not touch `waaseyaa_schema_authority` itself for the item-7
     * bind check to see either. Only the post-commit
     * `assertSchemaAuthorityVerified()` — recomputing the candidate's ledger
     * fingerprint after commit and comparing it to the (untouched) recorded
     * one — catches it, with `[S1-DB109]`. This is the test that proves the
     * post-commit verification step is load-bearing, not redundant with the
     * pre-commit guards.
     */
    #[Test]
    public function probe_c_a_serving_trigger_on_the_preserved_table_that_mutates_another_artifact_table_is_caught_only_post_commit(): void
    {
        $artifactPath = $this->directory . '/artifact.sqlite';
        $artifactConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $artifactPath]);
        $this->governArtifact($artifactConnection);
        $artifactConnection->close();

        $servingPath = $this->directory . '/serving.sqlite';
        $serving = $this->open($servingPath);
        $serving->exec('CREATE TABLE state (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
        // Serving's own decoy waaseyaa_migrations: only needed so the
        // trigger body below compiles against this connection. The row the
        // trigger actually inserts lands in the candidate's real ledger,
        // cloned from the artifact.
        $serving->exec(
            'CREATE TABLE waaseyaa_migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration VARCHAR(255) NOT NULL,
                package VARCHAR(128) NOT NULL,
                batch INTEGER NOT NULL,
                ran_at TIMESTAMP,
                checksum VARCHAR(64) NULL,
                diff_hash VARCHAR(64) NULL
            )',
        );
        $serving->exec(
            "CREATE TRIGGER state_ledger_hijack AFTER INSERT ON state
             BEGIN
                INSERT INTO waaseyaa_migrations (migration, package, batch, ran_at, checksum, diff_hash)
                VALUES ('rogue_migration', 'rogue/pkg', 999, '2026-01-01 00:00:00', NULL, NULL);
             END",
        );
        $serving->exec("INSERT INTO state VALUES ('last_run', '2026-09-22')");
        $serving = null;

        $candidatePath = $this->directory . '/candidate.sqlite';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('S1-DB109');

        try {
            new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
                $servingPath,
                $artifactPath,
                $candidatePath,
                ['content'],
            );
        } finally {
            self::assertFileDoesNotExist($candidatePath, 'A candidate whose ledger was mutated mid-preparation must never survive, even after commit.');
        }
    }

    /**
     * Re-review item 1: a real gap. The item-7 bind originally compared only
     * the three fingerprint columns, not `generation` — a cloned trigger `ON
     * state` that bumps `waaseyaa_schema_authority.generation` was accepted,
     * silently violating Required outcome 3 (generation must stay exactly
     * what the artifact recorded). `generation` is now part of both the
     * captured snapshot and the bind comparison; this is the public-API
     * proof.
     */
    #[Test]
    public function probe_b_a_serving_trigger_on_the_preserved_table_that_mutates_generation_fails_closed(): void
    {
        $artifactPath = $this->directory . '/artifact.sqlite';
        $artifactConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $artifactPath]);
        $this->governArtifact($artifactConnection);
        $artifactConnection->close();

        $servingPath = $this->directory . '/serving.sqlite';
        $serving = $this->open($servingPath);
        $serving->exec('CREATE TABLE state (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
        // Serving's own decoy waaseyaa_schema_authority: only needed so the
        // trigger body below compiles against this connection.
        $serving->exec(
            'CREATE TABLE waaseyaa_schema_authority (
                authority_id INTEGER PRIMARY KEY CHECK (authority_id = 1),
                generation INTEGER NOT NULL
            )',
        );
        $serving->exec(
            "CREATE TRIGGER state_generation_hijack AFTER INSERT ON state
             BEGIN
                UPDATE waaseyaa_schema_authority SET generation = generation + 5 WHERE authority_id = 1;
             END",
        );
        $serving->exec("INSERT INTO state VALUES ('last_run', '2026-09-22')");
        $serving = null;

        $candidatePath = $this->directory . '/candidate.sqlite';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no longer matches');

        try {
            new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
                $servingPath,
                $artifactPath,
                $candidatePath,
                ['content'],
            );
        } finally {
            self::assertFileDoesNotExist($candidatePath, 'A candidate whose generation was mutated mid-preparation must never survive.');
        }
    }

    /**
     * Re-review item 2 (mutation M16b): the bind previously ignored
     * `source_catalog_fingerprint`, so no test exercised a trigger mutating
     * it specifically — a regression there would have survived. Public-API
     * proof that it is now part of the bind comparison.
     */
    #[Test]
    public function probe_b_a_serving_trigger_on_the_preserved_table_that_mutates_source_catalog_fingerprint_fails_closed(): void
    {
        $artifactPath = $this->directory . '/artifact.sqlite';
        $artifactConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $artifactPath]);
        $this->governArtifact($artifactConnection);
        $artifactConnection->close();

        $servingPath = $this->directory . '/serving.sqlite';
        $serving = $this->open($servingPath);
        $serving->exec('CREATE TABLE state (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
        // Serving's own decoy waaseyaa_schema_authority: only needed so the
        // trigger body below compiles against this connection.
        $serving->exec(
            'CREATE TABLE waaseyaa_schema_authority (
                authority_id INTEGER PRIMARY KEY CHECK (authority_id = 1),
                source_catalog_fingerprint VARCHAR(64) NULL
            )',
        );
        $serving->exec(
            "CREATE TRIGGER state_catalog_hijack AFTER INSERT ON state
             BEGIN
                UPDATE waaseyaa_schema_authority SET source_catalog_fingerprint = 'hijacked-by-trigger' WHERE authority_id = 1;
             END",
        );
        $serving->exec("INSERT INTO state VALUES ('last_run', '2026-09-22')");
        $serving = null;

        $candidatePath = $this->directory . '/candidate.sqlite';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no longer matches');

        try {
            new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
                $servingPath,
                $artifactPath,
                $candidatePath,
                ['content'],
            );
        } finally {
            self::assertFileDoesNotExist($candidatePath, 'A candidate whose source_catalog_fingerprint was mutated mid-preparation must never survive.');
        }
    }

    /**
     * Re-review item 3 (public-API coverage for mutation M4b): probe (a)
     * above used a trigger `ON content`, an application-owned table not in
     * the catalogue at all — the `$definition === null` branch of the
     * bounded-difference check. This variant instead names an actual
     * catalogue table whose policy IS `RuntimeTablePolicy::Artifact`
     * (`cache_discovery`), exercising the other branch through the public
     * `prepare()` API, matching Required outcome 2 by name.
     */
    #[Test]
    public function probe_a_a_serving_trigger_named_after_a_preserved_table_but_on_an_artifact_policy_table_fails_closed(): void
    {
        $artifactPath = $this->directory . '/artifact.sqlite';
        $artifactConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $artifactPath]);
        $this->governArtifact($artifactConnection, static function (Connection $connection): void {
            $connection->executeStatement('CREATE TABLE cache_discovery (cid TEXT PRIMARY KEY, data TEXT)');
        });
        $artifactConnection->close();

        $servingPath = $this->directory . '/serving.sqlite';
        $serving = $this->open($servingPath);
        $serving->exec('CREATE TABLE state (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
        // Serving's own decoy cache_discovery: only needed so the trigger
        // below compiles against this connection — the real cache_discovery
        // the candidate carries comes from the artifact copy.
        $serving->exec('CREATE TABLE cache_discovery (cid TEXT PRIMARY KEY, data TEXT)');
        // Named "state" (the Preserve-policy table being cloned) but ON
        // "cache_discovery" — a catalogue table whose policy is Artifact.
        $serving->exec('CREATE TRIGGER state AFTER INSERT ON cache_discovery BEGIN SELECT 1; END');
        $serving = null;

        $candidatePath = $this->directory . '/candidate.sqlite';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cache_discovery');

        try {
            new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
                $servingPath,
                $artifactPath,
                $candidatePath,
                ['content'],
            );
        } finally {
            self::assertFileDoesNotExist($candidatePath, 'An unbounded schema difference must never leave a candidate behind.');
        }
    }

    /**
     * Kept in addition to the public-API probe (a) above (review: "or keep
     * them in addition"): drives `reconcileSchemaAuthority()` directly with a
     * hand-built artifact snapshot, so it covers the `RuntimeTablePolicy::Artifact`
     * branch of the bounded-difference check specifically (probe (a) instead
     * exercises the "not a catalogue table at all" branch), independent of
     * whichever cloning path a future caller might use to reach it.
     */
    #[Test]
    public function a_difference_in_an_artifact_policy_table_fails_closed(): void
    {
        $artifactPath = $this->directory . '/artifact.sqlite';
        $artifactConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $artifactPath]);
        $this->governArtifact($artifactConnection, static function (Connection $connection): void {
            $connection->executeStatement('CREATE TABLE cache_items (cid TEXT PRIMARY KEY, data TEXT)');
        });
        $artifactConnection->close();

        $candidatePath = $this->directory . '/candidate.sqlite';
        copy($artifactPath, $candidatePath);
        $candidatePdo = $this->open($candidatePath);
        $candidatePdo->exec('ALTER TABLE cache_items ADD COLUMN extra TEXT');

        $artifactSnapshot = $this->captureSnapshot($artifactPath);
        $preparer = new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue());
        $definitions = new FrameworkRuntimeTableCatalogue()->definitions();
        $reconcile = new \ReflectionMethod(SqliteArtifactPreparer::class, 'reconcileSchemaAuthority');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cache_items');

        $reconcile->invoke($preparer, $candidatePdo, $definitions, $artifactSnapshot);
    }

    /**
     * Corrected per review: this does NOT isolate the re-record `UPDATE`'s
     * own `WHERE schema_fingerprint = ?` clause from the item-7 bind check —
     * that is impossible to do with a hand-built snapshot. The bind check
     * compares the candidate's real `schema_fingerprint` against
     * `$artifactSchemaAuthority['schema_fingerprint']`, and the re-record
     * `UPDATE`'s `WHERE` clause is parameterised with that exact same
     * `$artifactSchemaAuthority['schema_fingerprint']` value moments later,
     * with nothing in between that could change the candidate's row
     * (`assertBoundedSchemaDifference()` is read-only). So whenever the bind
     * check's `schema_fingerprint` comparison passes, the re-record
     * `UPDATE`'s identical comparison cannot then fail — there is no snapshot
     * that reaches the `UPDATE` with a schema_fingerprint mismatch the bind
     * check did not already catch. This fixture instead directly proves what
     * IS true and useful: `reconcileSchemaAuthority()` refuses a hand-built
     * artifact snapshot whose `schema_fingerprint` does not match the
     * candidate's own (unmutated) row — via the bind check, in practice,
     * every time. The re-record `UPDATE`'s own `rowCount() !== 1` branch
     * remains defensive code with no test that reaches it specifically; see
     * `docs/change-records/FW-3149.md`'s mutation table (M2, M16) for why
     * that is recorded as an accepted, harmless surviving mutation rather
     * than pursued further.
     */
    #[Test]
    public function a_manifest_that_no_longer_matches_the_captured_artifact_snapshot_fails_closed(): void
    {
        $artifactPath = $this->directory . '/artifact.sqlite';
        $artifactConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $artifactPath]);
        $this->governArtifact($artifactConnection);
        $artifactConnection->close();

        $candidatePath = $this->directory . '/candidate.sqlite';
        copy($artifactPath, $candidatePath);
        $candidatePdo = $this->open($candidatePath);

        $artifactSnapshot = $this->captureSnapshot($artifactPath);
        $artifactSnapshot['schema_fingerprint'] = 'concurrently-changed-value';

        $preparer = new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue());
        $definitions = new FrameworkRuntimeTableCatalogue()->definitions();
        $reconcile = new \ReflectionMethod(SqliteArtifactPreparer::class, 'reconcileSchemaAuthority');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no longer matches');

        $reconcile->invoke($preparer, $candidatePdo, $definitions, $artifactSnapshot);
    }

    #[Test]
    public function an_artifact_without_a_fingerprinted_manifest_is_left_untouched(): void
    {
        // A fresh install / #2452 adoption: waaseyaa_schema_authority exists
        // (schema mutation always installs it) but carries no fingerprints.
        $artifactPath = $this->directory . '/artifact.sqlite';
        $artifact = $this->open($artifactPath);
        $artifact->exec('CREATE TABLE content (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
        $artifact->exec(
            'CREATE TABLE waaseyaa_schema_authority (
                authority_id INTEGER PRIMARY KEY CHECK (authority_id = 1),
                generation INTEGER NOT NULL,
                schema_fingerprint VARCHAR(64) NULL,
                ledger_fingerprint VARCHAR(64) NULL,
                source_catalog_fingerprint VARCHAR(64) NULL
            )',
        );
        $artifact->exec('INSERT INTO waaseyaa_schema_authority (authority_id, generation) VALUES (1, 0)');
        $artifact = null;

        $servingPath = $this->directory . '/serving.sqlite';
        $serving = $this->open($servingPath);
        $serving->exec('CREATE TABLE content (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
        $serving->exec('CREATE TABLE state (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $serving->exec("INSERT INTO state VALUES ('last_run', 'x')");
        $serving = null;

        $candidatePath = $this->directory . '/candidate.sqlite';
        new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
            $servingPath,
            $artifactPath,
            $candidatePath,
            ['content'],
        );

        $candidate = $this->open($candidatePath);
        $row = $candidate->query(
            'SELECT generation, schema_fingerprint, ledger_fingerprint, source_catalog_fingerprint FROM waaseyaa_schema_authority WHERE authority_id = 1',
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(0, (int) $row['generation']);
        self::assertNull($row['schema_fingerprint']);
        self::assertNull($row['ledger_fingerprint']);
        self::assertNull($row['source_catalog_fingerprint']);
    }

    /** Run one governed schema transition so the artifact gets a real, self-consistent manifest. */
    private function governArtifact(Connection $connection, ?callable $extra = null): void
    {
        $repository = new MigrationRepository($connection);
        $coordinator = new SchemaMutationCoordinator($connection, $repository);
        $coordinator->execute(static function () use ($connection, $extra, $repository): void {
            $connection->executeStatement('CREATE TABLE content (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
            $connection->executeStatement("INSERT INTO content VALUES (1, 'artifact content')");
            if ($extra !== null) {
                $extra($connection);
            }
            // A real SHA-256, not NULL: several assertions in this file
            // compare source_catalog_fingerprint end to end, and a NULL
            // fixture would make that comparison vacuously true (NULL ==
            // NULL) regardless of whether the preparer actually propagates
            // the value.
            $repository->recordSourceCatalogFingerprint(hash('sha256', 'fixture-source-catalog'));
        });
    }

    /**
     * Hand-builds the same snapshot shape `captureArtifactSchemaAuthority()`
     * returns, for the two tests above that invoke `reconcileSchemaAuthority()`
     * directly rather than through `prepare()`.
     *
     * @return array{schema_fingerprint:string, ledger_fingerprint:string, source_catalog_fingerprint:?string, generation:int, schema_objects:list<array{type:string,name:string,table:string,sql:?string}>}
     */
    private function captureSnapshot(string $artifactPath): array
    {
        $pdo = $this->open($artifactPath);
        $manifest = $pdo->query(
            'SELECT schema_fingerprint, ledger_fingerprint, source_catalog_fingerprint, generation FROM waaseyaa_schema_authority WHERE authority_id = 1',
        )->fetch(\PDO::FETCH_ASSOC);

        return [
            'schema_fingerprint' => (string) $manifest['schema_fingerprint'],
            'ledger_fingerprint' => (string) $manifest['ledger_fingerprint'],
            'source_catalog_fingerprint' => $manifest['source_catalog_fingerprint'],
            'generation' => (int) $manifest['generation'],
            'schema_objects' => SchemaAuthorityFingerprint::schemaObjects($pdo),
        ];
    }

    private function open(string $path): \PDO
    {
        return new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }
}

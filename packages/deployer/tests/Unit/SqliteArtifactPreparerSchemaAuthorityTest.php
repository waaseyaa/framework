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
        // defect. `ledger_fingerprint` and `source_catalog_fingerprint` stay
        // byte-identical to the artifact's, because `waaseyaa_migrations` and
        // `waaseyaa_schema_authority` are both `RuntimeTablePolicy::Artifact`
        // and the preparer never touches them beyond the artifact copy.
        $candidatePdo = $this->open($candidatePath);
        $recorded = $candidatePdo->query(
            'SELECT schema_fingerprint, ledger_fingerprint, source_catalog_fingerprint FROM waaseyaa_schema_authority WHERE authority_id = 1',
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(
            SchemaAuthorityFingerprint::logicalSchemaFingerprint($candidatePdo),
            $recorded['schema_fingerprint'],
        );

        $artifactPdo = $this->open($artifactPath);
        $artifactRecorded = $artifactPdo->query(
            'SELECT ledger_fingerprint, source_catalog_fingerprint FROM waaseyaa_schema_authority WHERE authority_id = 1',
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertSame($artifactRecorded['ledger_fingerprint'], $recorded['ledger_fingerprint']);
        self::assertSame($artifactRecorded['source_catalog_fingerprint'], $recorded['source_catalog_fingerprint']);
    }

    #[Test]
    public function a_stale_artifact_manifest_fails_closed_before_any_candidate_is_written(): void
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
     * `prepare()`'s own runtime-preservation loop never touches an
     * Artifact-policy table's schema — it is skipped outright — so this
     * fixture cannot arise from ordinary inputs through the public API. It
     * instead drives the private `reconcileSchemaAuthority()` guard directly
     * (defense in depth against a future regression in that loop) with a
     * "candidate" whose Artifact-policy `cache_items` table was widened
     * relative to the artifact.
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

        $artifactPdo = $this->open($artifactPath);
        $preparer = new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue());
        $definitions = new FrameworkRuntimeTableCatalogue()->definitions();
        $reconcile = new \ReflectionMethod(SqliteArtifactPreparer::class, 'reconcileSchemaAuthority');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cache_items');

        $reconcile->invoke($preparer, $artifactPdo, $candidatePdo, $definitions);
    }

    /**
     * `prepare()` builds the candidate as a private, single-writer working
     * file, so there is no real concurrency window inside one call for a
     * second writer to change its manifest mid-flight. This fixture proves
     * the re-record step is conditional at all — invoking the private
     * `reconcileSchemaAuthority()` guard directly against a candidate whose
     * `waaseyaa_schema_authority.schema_fingerprint` was already changed to
     * something other than the value the artifact's manifest names, which is
     * exactly what a concurrent writer landing between prepare()'s
     * precondition check and its conditional `UPDATE ... WHERE
     * schema_fingerprint = ?` would produce.
     */
    #[Test]
    public function a_manifest_changed_concurrently_between_precondition_and_re_record_fails_closed(): void
    {
        $artifactPath = $this->directory . '/artifact.sqlite';
        $artifactConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $artifactPath]);
        $this->governArtifact($artifactConnection);
        $artifactConnection->close();

        $candidatePath = $this->directory . '/candidate.sqlite';
        copy($artifactPath, $candidatePath);
        $candidatePdo = $this->open($candidatePath);
        $candidatePdo->exec(
            "UPDATE waaseyaa_schema_authority SET schema_fingerprint = 'concurrently-changed-value' WHERE authority_id = 1",
        );

        $artifactPdo = $this->open($artifactPath);
        $preparer = new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue());
        $definitions = new FrameworkRuntimeTableCatalogue()->definitions();
        $reconcile = new \ReflectionMethod(SqliteArtifactPreparer::class, 'reconcileSchemaAuthority');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('concurrently');

        $reconcile->invoke($preparer, $artifactPdo, $candidatePdo, $definitions);
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
        $coordinator->execute(static function () use ($connection, $extra): void {
            $connection->executeStatement('CREATE TABLE content (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
            $connection->executeStatement("INSERT INTO content VALUES (1, 'artifact content')");
            if ($extra !== null) {
                $extra($connection);
            }
        });
    }

    private function open(string $path): \PDO
    {
        return new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Migration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Deployer\RuntimeState\FrameworkRuntimeTableCatalogue;
use Waaseyaa\Deployer\RuntimeState\SqliteArtifactPreparer;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Migration\MigrationLoader;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;

/**
 * #2547: the catalogue must classify what Framework migrations actually
 * install, proven by running the preparer over that schema.
 *
 * The sibling {@see FrameworkRuntimeTableCatalogueCompletenessTest} compares
 * two *sets* — installed table names against catalogued ones — and its
 * grandfather list let 22 unclassified tables sit there indefinitely while it
 * stayed green. Set comparison cannot tell you whether the boundary those sets
 * feed actually works, which is why Framework could pass CI and still be unable
 * to install a consumer artifact through its own handoff.
 *
 * This drives the real {@see SqliteArtifactPreparer} over a database built by
 * the real migrations. It is the assertion the production failure would have
 * tripped: an uncatalogued table makes prepare() throw before a single serving
 * row is copied.
 */
#[CoversNothing]
final class MigrationInstalledArtifactPreparationTest extends TestCase
{
    /**
     * The only tables a consumer must still declare for a Framework-only
     * database, and why that is not simply another catalogue gap.
     *
     * All three are installed by Framework migrations for Framework-shipped
     * entity types, so on ownership alone they look like catalogue omissions.
     * They are held back deliberately: their rows are ambiguous between content
     * (authored in the build, so the artifact should win) and runtime state
     * (`MediaVersionRepository` writes `media_version` on the serving host, so
     * the host should win), and the catalogue silently overrides an application
     * declaration rather than conflicting with it. Guessing wrong would
     * therefore not fail loudly — it would quietly discard one side's rows.
     *
     * #2547 scopes them out on the same reasoning, noting they are already
     * declared by the reporting consumer. Adjudicating them is its own change
     * with its own evidence; until then this list is the honest statement of
     * what a consumer carries.
     *
     * @var list<string>
     */
    private const array CONSUMER_DECLARED_TABLES = [
        'classification_label_definition',
        'media_version',
        'retention_policy',
    ];

    /**
     * Singleton counters: one migration-seeded row whose VALUE is the state.
     *
     * A row count cannot tell Preserve from Artifact here — both databases hold
     * exactly one row either way — and the generic fixture below cannot seed
     * them at all, because their `CHECK (singleton_id = 1)` rejects a second
     * row. Left to the generic path they were silently skipped, which is how a
     * scheduler fence reset — the catalogue's own example of a correctness
     * failure, not lost bookkeeping — could have passed this test.
     *
     * @var array<string, string> table => counter column
     */
    private const array SINGLETON_COUNTERS = [
        'cache_generation' => 'generation',
        'oidc_signing_key_version_sequence' => 'next_version',
        'waaseyaa_scheduler_fence_sequence' => 'next_fence',
    ];

    /** Distinctive enough that no migration default can produce it. */
    private const int SERVING_COUNTER_VALUE = 424_242;

    /**
     * Non-Artifact tables a single generic row cannot satisfy, asserted as an
     * exact set so a new one cannot join them silently.
     *
     * Each rejects the generic fixture on a CHECK constraint that encodes a
     * relationship between columns (a version range, a lifecycle enum) or on a
     * uniqueness constraint against a row its own migration already seeded.
     * Modelling them properly means modelling the subsystem that writes them,
     * which is #2548's coherent multi-table fixture, not this test's business.
     *
     * @var list<string>
     */
    private const array GENERIC_FIXTURE_EXEMPT = [
        'audit_checkpoint',
        'waaseyaa_application_master_rekey',
        'waaseyaa_application_master_version',
        'waaseyaa_config_generation',
    ];

    /** @var list<string> tables the generic fixture could not seed */
    private array $constraintSkips = [];

    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/waaseyaa_artifact_prep_' . bin2hex(random_bytes(6));
        mkdir($this->workspace, 0o700, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->workspace);
    }

    /**
     * The reported production failure, as a test: a serving database and an
     * artifact both built from THIS commit's migrations must prepare cleanly
     * once the consumer declares the three tables above — and nothing else.
     *
     * With an uncatalogued table present this throws "Unknown … tables"; the
     * consumer never gets as far as a serving mutation.
     */
    #[Test]
    public function a_migration_installed_database_prepares_against_itself(): void
    {
        $serving = $this->migratedDatabase('serving.sqlite');
        $artifact = $this->migratedDatabase('artifact.sqlite');
        $candidate = $this->workspace . '/candidate.sqlite';

        $report = new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
            currentDatabase: $serving,
            artifactDatabase: $artifact,
            candidateDatabase: $candidate,
            applicationArtifactTables: self::CONSUMER_DECLARED_TABLES,
        );

        self::assertSame(FrameworkRuntimeTableCatalogue::VERSION, $report->catalogueVersion);
        self::assertFileExists($candidate);
    }

    /**
     * Classification is not enough on its own: the policies must also survive
     * the round trip. A serving database carrying rows in preserved tables must
     * still hold exactly those rows in the candidate.
     */
    #[Test]
    public function serving_rows_in_preserved_tables_survive_into_the_candidate(): void
    {
        $serving = $this->migratedDatabase('serving.sqlite');
        $artifact = $this->migratedDatabase('artifact.sqlite');
        $candidate = $this->workspace . '/candidate.sqlite';

        $catalogue = new FrameworkRuntimeTableCatalogue();
        $seeded = $this->seedPreservedRows($serving, $catalogue);
        self::assertNotSame([], $seeded, 'The fixture must seed at least one preserved table.');
        self::assertSame(
            self::GENERIC_FIXTURE_EXEMPT,
            $this->constraintSkips,
            'A non-Artifact table stopped accepting the generic fixture. Seed it '
            . 'deliberately or record why it cannot be seeded — do not let it fall '
            . 'out of this test unnoticed.',
        );
        $this->advanceSingletonCounters($serving);

        $report = new SqliteArtifactPreparer($catalogue)->prepare(
            currentDatabase: $serving,
            artifactDatabase: $artifact,
            candidateDatabase: $candidate,
            applicationArtifactTables: self::CONSUMER_DECLARED_TABLES,
        );

        $pdo = new \PDO('sqlite:' . $candidate, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        foreach ($seeded as $table => $expectedRows) {
            self::assertSame(
                $expectedRows,
                (int) $pdo->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn(),
                sprintf('Serving rows in preserved table %s did not reach the candidate.', $table),
            );
            self::assertArrayHasKey($table, $report->tables, $table . ' must appear in the install evidence.');
        }

        foreach (self::SINGLETON_COUNTERS as $table => $column) {
            self::assertSame(
                self::SERVING_COUNTER_VALUE,
                (int) $pdo->query(sprintf('SELECT "%s" FROM "%s" WHERE singleton_id = 1', $column, $table))->fetchColumn(),
                sprintf('%s carries the artifact counter; the serving value was reset.', $table),
            );
            self::assertArrayHasKey($table, $report->tables, $table . ' must appear in the install evidence.');
        }
    }

    /**
     * `waaseyaa_entity_mutation_authority` is the one table that needs both
     * sides, and neither Preserve nor Artifact expresses that. IdentityMerge
     * must let the serving row win on a shared key — an aggregate version that
     * moved backwards re-enables ABA — while an entity only the artifact knows
     * about keeps the authority row without which
     * `EntityRepository::hydrate()` refuses to read it.
     */
    #[Test]
    public function identity_merge_keeps_the_serving_row_and_the_artifact_only_row(): void
    {
        $serving = $this->migratedDatabase('serving.sqlite');
        $artifact = $this->migratedDatabase('artifact.sqlite');
        $candidate = $this->workspace . '/candidate.sqlite';

        // Same key on both sides, with the serving host further ahead.
        $this->seedMutationAuthority($serving, 'node', '1', 7, 'a');
        $this->seedMutationAuthority($artifact, 'node', '1', 2, 'b');
        // An entity the build introduced and the serving host has never seen.
        $this->seedMutationAuthority($artifact, 'node', '2', 3, 'c');

        new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
            currentDatabase: $serving,
            artifactDatabase: $artifact,
            candidateDatabase: $candidate,
            applicationArtifactTables: self::CONSUMER_DECLARED_TABLES,
        );

        $pdo = new \PDO('sqlite:' . $candidate, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $rows = $pdo->query(
            'SELECT entity_id, aggregate_version, mutation_tag FROM waaseyaa_entity_mutation_authority ORDER BY entity_id',
        )->fetchAll(\PDO::FETCH_ASSOC);

        self::assertSame(
            [
                ['entity_id' => '1', 'aggregate_version' => 7, 'mutation_tag' => str_repeat('a', 64)],
                ['entity_id' => '2', 'aggregate_version' => 3, 'mutation_tag' => str_repeat('c', 64)],
            ],
            array_map(
                static fn(array $row): array => [
                    'entity_id' => (string) $row['entity_id'],
                    'aggregate_version' => (int) $row['aggregate_version'],
                    'mutation_tag' => (string) $row['mutation_tag'],
                ],
                $rows,
            ),
        );
    }

    /**
     * Move every singleton counter off its migration default on the serving
     * side. The artifact is built by the same migrations, so a candidate that
     * still reads the default took the artifact's copy.
     */
    private function advanceSingletonCounters(string $path): void
    {
        $pdo = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        foreach (self::SINGLETON_COUNTERS as $table => $column) {
            $before = $pdo->query(sprintf('SELECT "%s" FROM "%s" WHERE singleton_id = 1', $column, $table))->fetchColumn();
            self::assertNotFalse($before, $table . ' must be seeded by its migration.');
            self::assertNotSame(
                self::SERVING_COUNTER_VALUE,
                (int) $before,
                $table . ' already holds the marker value, so preserving it would prove nothing.',
            );
            self::assertSame(
                1,
                $pdo->exec(sprintf(
                    'UPDATE "%s" SET "%s" = %d WHERE singleton_id = 1',
                    $table,
                    $column,
                    self::SERVING_COUNTER_VALUE,
                )),
                $table . ' must hold exactly one singleton row.',
            );
        }
    }

    private function seedMutationAuthority(
        string $path,
        string $entityType,
        string $entityId,
        int $version,
        string $tagCharacter,
    ): void {
        $pdo = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $statement = $pdo->prepare(<<<'SQL'
            INSERT INTO waaseyaa_entity_mutation_authority (
                storage_authority, tenant_id, entity_type, entity_id,
                aggregate_version, mutation_tag, lifecycle_state
            ) VALUES ('default', 'default', ?, ?, ?, ?, 'active')
            SQL);
        $statement->execute([$entityType, $entityId, $version, str_repeat($tagCharacter, 64)]);
    }

    /**
     * A table Framework installs but does not classify must fail closed, loudly
     * — this is the behaviour that turned an unclassified catalogue into a
     * production outage, and it must stay.
     */
    #[Test]
    public function an_unclassified_table_still_fails_closed(): void
    {
        $serving = $this->migratedDatabase('serving.sqlite');
        $artifact = $this->migratedDatabase('artifact.sqlite');

        foreach ([$serving, $artifact] as $path) {
            $pdo = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE TABLE waaseyaa_unclassified_future_table (id INTEGER PRIMARY KEY)');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/waaseyaa_unclassified_future_table/');

        new SqliteArtifactPreparer(new FrameworkRuntimeTableCatalogue())->prepare(
            currentDatabase: $serving,
            artifactDatabase: $artifact,
            candidateDatabase: $this->workspace . '/candidate.sqlite',
            applicationArtifactTables: self::CONSUMER_DECLARED_TABLES,
        );
    }

    /**
     * Seed one row into every preserved/append-only table whose columns can be
     * satisfied generically, and report what was seeded.
     *
     * @return array<string, int> table => expected row count
     */
    private function seedPreservedRows(string $path, FrameworkRuntimeTableCatalogue $catalogue): array
    {
        $pdo = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $seeded = [];

        foreach ($catalogue->definitions() as $definition) {
            if ($definition->name === 'user') {
                // Seeded by migratedDatabase(); IdentityMerge has different
                // round-trip semantics and belongs to #2549, not here.
                continue;
            }
            if (isset(self::SINGLETON_COUNTERS[$definition->name])) {
                // A value, not a row: advanceSingletonCounters() owns these.
                continue;
            }
            if ($definition->name === 'waaseyaa_entity_mutation_authority') {
                // IdentityMerge needs both sides seeded; its own test does that.
                continue;
            }
            if ($definition->policy === \Waaseyaa\Deployer\RuntimeState\RuntimeTablePolicy::Artifact) {
                continue;
            }
            $columns = $pdo->query(
                'SELECT name, type, "notnull", dflt_value, pk FROM pragma_table_info(' . $pdo->quote($definition->name) . ')',
            )->fetchAll(\PDO::FETCH_ASSOC);
            if ($columns === []) {
                continue;
            }
            // A generic value cannot satisfy a foreign key, and a dangling one
            // would fail the preparer's own foreign_key_check — correctly. The
            // FK-bearing tables (the configuration-authority graph) need a
            // coherent multi-table fixture, which is #2548's subject; a broad
            // sample of the rest proves preservation here.
            $foreignKeys = $pdo->query(
                'SELECT 1 FROM pragma_foreign_key_list(' . $pdo->quote($definition->name) . ')',
            )->fetchAll();
            if ($foreignKeys !== []) {
                continue;
            }

            $names = [];
            $values = [];
            foreach ($columns as $column) {
                // Only columns that MUST be supplied: a generic fixture cannot
                // guess a meaningful value, so anything nullable or defaulted
                // is left alone.
                if ((int) $column['notnull'] !== 1 || $column['dflt_value'] !== null) {
                    continue;
                }
                $names[] = '"' . $column['name'] . '"';
                // An account-reference column must resolve to a real uid or
                // the preparer rejects it — the seeded user is uid 1. Its
                // declared SQLite type is not a reliable guide (oidc_access_token
                // .account_id is TEXT), so the definition decides, not the type.
                $values[] = in_array($column['name'], $definition->accountReferenceColumns, true)
                    ? '1'
                    : $this->sampleValue((string) $column['type']);
            }

            try {
                if ($names === []) {
                    $pdo->exec('INSERT INTO "' . $definition->name . '" DEFAULT VALUES');
                } else {
                    $pdo->exec(sprintf(
                        'INSERT INTO "%s" (%s) VALUES (%s)',
                        $definition->name,
                        implode(', ', $names),
                        implode(', ', $values),
                    ));
                }
            } catch (\PDOException) {
                // A table with constraints or triggers a generic fixture cannot
                // satisfy. Recorded rather than swallowed: the caller asserts
                // the exact set, so a table that quietly stops being seeded
                // fails instead of thinning this test's coverage in silence.
                $this->constraintSkips[] = $definition->name;
                continue;
            }

            $seeded[$definition->name] = (int) $pdo->query(
                'SELECT COUNT(*) FROM "' . $definition->name . '"',
            )->fetchColumn();
        }

        sort($this->constraintSkips, SORT_STRING);

        return $seeded;
    }

    private function sampleValue(string $declaredType): string
    {
        $type = strtoupper($declaredType);

        return str_contains($type, 'INT') || str_contains($type, 'REAL') || str_contains($type, 'NUM')
            ? '1'
            : "'waaseyaa-2547'";
    }

    /** Build a file-backed database from every Framework migration. */
    private function migratedDatabase(string $filename): string
    {
        $path = $this->workspace . '/' . $filename;
        $root = dirname(__DIR__, 3);

        $paths = [];
        foreach (glob($root . '/packages/*/composer.json') ?: [] as $composerPath) {
            $composer = json_decode((string) file_get_contents($composerPath), true, flags: JSON_THROW_ON_ERROR);
            $migrationsDir = $composer['extra']['waaseyaa']['migrations'] ?? null;
            if (!is_string($migrationsDir)) {
                continue;
            }
            $paths[(string) $composer['name']] = dirname($composerPath) . '/' . $migrationsDir;
        }
        ksort($paths, SORT_STRING);
        self::assertNotSame([], $paths, 'No package declares extra.waaseyaa.migrations.');

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        $migrator = new Migrator($connection, new MigrationRepository($connection));
        self::assertGreaterThan(
            0,
            $migrator->run(new MigrationLoader($root, new PackageManifest(migrations: $paths))->loadAll())->count,
        );

        // `user` is an entity-storage table, created by entity schema sync
        // rather than by a migration — but every real serving and artifact
        // database has one, and the preparer refuses to validate account
        // references without it. A minimal shape is enough here: this test is
        // about catalogue coverage, and the identity-merge semantics of the
        // real table are #2549's subject.
        $connection->executeStatement(
            'CREATE TABLE user (uid INTEGER PRIMARY KEY AUTOINCREMENT, uuid VARCHAR(64) NOT NULL, name VARCHAR(255) NOT NULL, _data TEXT)',
        );
        $connection->executeStatement('CREATE UNIQUE INDEX user_uuid ON user (uuid)');
        $connection->executeStatement(
            "INSERT INTO user (uid, uuid, name, _data) VALUES (1, 'u-1', 'seed', '{}')",
        );
        $connection->close();

        self::assertFileExists($path);

        return $path;
    }
}

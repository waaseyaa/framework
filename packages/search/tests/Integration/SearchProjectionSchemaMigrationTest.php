<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\MigrationLoader;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Foundation\Migration\SchemaMutationCoordinator;
use Waaseyaa\Search\Fts5\Fts5SearchSchema;
use Waaseyaa\Testing\Database\TemporarySqliteDatabase;

/**
 * FW-SEARCH-PERSIST-01: the waaseyaa/search migration owns the FTS5 search
 * projection on the authoritative database. It creates it, adopts a compatible
 * runtime-created projection in place with its rows, rebuilds a retired
 * Porter-tokenized index with its rows, and refuses any other shape without
 * changing anything.
 */
#[CoversClass(Fts5SearchSchema::class)]
final class SearchProjectionSchemaMigrationTest extends TestCase
{
    private const string MIGRATION = __DIR__ . '/../../migrations/2026_09_24_000001_search_projection_schema.php';

    /** The statements the pre-migration runtime code (`Fts5SearchIndexer::ensureSchema()`) issued, verbatim. */
    private const array RUNTIME_DDL = [
        <<<'SQL'
                CREATE VIRTUAL TABLE IF NOT EXISTS search_index USING fts5(
                    document_id UNINDEXED,
                    title,
                    body,
                    tokenize="unicode61 remove_diacritics 0 tokenchars '''’ʼ'"
                )
            SQL,
        <<<'SQL'
                CREATE TABLE IF NOT EXISTS search_metadata (
                    document_id TEXT PRIMARY KEY,
                    entity_type TEXT NOT NULL,
                    content_type TEXT NOT NULL DEFAULT '',
                    source_name TEXT NOT NULL DEFAULT '',
                    quality_score INTEGER NOT NULL DEFAULT 0,
                    topics TEXT NOT NULL DEFAULT '[]',
                    url TEXT NOT NULL DEFAULT '',
                    og_image TEXT NOT NULL DEFAULT '',
                    created_at TEXT NOT NULL,
                    schema_version TEXT NOT NULL
                )
            SQL,
        'CREATE INDEX IF NOT EXISTS idx_search_meta_entity_type ON search_metadata(entity_type)',
        'CREATE INDEX IF NOT EXISTS idx_search_meta_content_type ON search_metadata(content_type)',
        'CREATE INDEX IF NOT EXISTS idx_search_meta_source ON search_metadata(source_name)',
    ];

    /** The pre-0.1.0-alpha.263 index definition. */
    private const string PORTER_DDL = <<<'SQL'
                CREATE VIRTUAL TABLE IF NOT EXISTS search_index USING fts5(
                    document_id UNINDEXED,
                    title,
                    body,
                    tokenize='porter unicode61'
                )
            SQL;

    private TemporarySqliteDatabase $temporary;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->temporary = new TemporarySqliteDatabase();
        $database = $this->temporary->database();
        self::assertInstanceOf(DBALDatabase::class, $database);
        $this->connection = $database->getConnection();
        // A recorded manifest, as on any installed application.
        $this->coordinated(static function (): void {});
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        $this->temporary->remove();
    }

    #[Test]
    public function createsTheProjectionWhenAbsent(): void
    {
        $this->applyMigration();

        self::assertSame(
            ['idx_search_meta_content_type', 'idx_search_meta_entity_type', 'idx_search_meta_source', 'search_index', 'search_metadata'],
            array_keys($this->ownedObjects()),
        );
        $this->insertRows();
        self::assertSame('search:1', $this->connection->fetchOne("SELECT document_id FROM search_index WHERE search_index MATCH 'giizhik'"));
        $this->assertManifestDescribesLiveSchema();
    }

    #[Test]
    public function aMigratedProjectionHasTheRuntimeCreatedDefinitions(): void
    {
        // Same stored SQL means the same logical schema fingerprint, whichever
        // path created the projection.
        $this->coordinated(function (): void {
            foreach (self::RUNTIME_DDL as $ddl) {
                $this->connection->executeStatement($ddl);
            }
        });
        $runtime = $this->ownedObjects();
        $this->connection->executeStatement('DROP TABLE search_index');
        $this->connection->executeStatement('DROP TABLE search_metadata');
        $this->coordinated(static function (): void {}, readopt: true);

        $this->applyMigration();

        self::assertSame($runtime, $this->ownedObjects());
    }

    #[Test]
    public function thePackageDeclaresTheMigrationAndTheMigratorInstallsItOnce(): void
    {
        $packageRoot = dirname(__DIR__, 2);
        $composer = json_decode((string) file_get_contents($packageRoot . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('migrations', $composer['extra']['waaseyaa']['migrations'] ?? null);

        $all = new MigrationLoader(dirname($packageRoot, 2), new PackageManifest(migrations: ['waaseyaa/search' => $packageRoot . '/migrations']))->loadAll();
        self::assertSame(['waaseyaa/search:2026_09_24_000001_search_projection_schema'], array_merge(...array_values(array_map('array_keys', $all))));

        $migrator = new Migrator($this->connection, new MigrationRepository($this->connection));
        self::assertSame(1, $migrator->run($all)->count);
        self::assertCount(5, $this->ownedObjects());
        self::assertSame(0, $migrator->run($all)->count);
        $this->assertManifestDescribesLiveSchema();
    }

    #[Test]
    public function adoptsARuntimeCreatedProjectionInPlaceAndKeepsEveryRow(): void
    {
        // The projection exists and a later transition put it inside the manifest.
        $this->coordinated(function (): void {
            foreach (self::RUNTIME_DDL as $ddl) {
                $this->connection->executeStatement($ddl);
            }
            $this->insertRows();
        });
        $before = $this->snapshot();

        $this->applyMigration();

        self::assertSame($before, $this->snapshot(), 'definitions and rows are unchanged');
        $this->assertManifestDescribesLiveSchema();
    }

    #[Test]
    public function completesAPartialProjection(): void
    {
        $this->coordinated(function (): void {
            $this->connection->executeStatement(self::RUNTIME_DDL[1]);
        });

        $this->applyMigration();

        self::assertCount(5, $this->ownedObjects());
        $this->assertManifestDescribesLiveSchema();
    }

    #[Test]
    public function rebuildsARetiredPorterIndexAndKeepsEveryRow(): void
    {
        $this->coordinated(function (): void {
            $this->connection->executeStatement(self::PORTER_DDL);
            foreach (array_slice(self::RUNTIME_DDL, 1) as $ddl) {
                $this->connection->executeStatement($ddl);
            }
            $this->insertRows();
        });
        $rows = $this->snapshot()['rows'];

        $this->applyMigration();

        $objects = $this->ownedObjects();
        self::assertStringNotContainsString('porter', $objects['search_index']);
        self::assertStringContainsString('remove_diacritics 0', $objects['search_index']);
        self::assertSame($rows, $this->snapshot()['rows']);
        // Re-tokenized: an exact orthographic term matches; the stemmed form no longer does.
        self::assertSame('search:1', $this->connection->fetchOne("SELECT document_id FROM search_index WHERE search_index MATCH 'giizhik'"));
        self::assertFalse($this->connection->fetchOne("SELECT document_id FROM search_index WHERE search_index MATCH 'run'"));
        self::assertSame([], $this->connection->fetchFirstColumn("SELECT name FROM sqlite_master WHERE name LIKE 'search_index_retired%'"));
        $this->assertManifestDescribesLiveSchema();
    }

    #[Test]
    public function aRuntimeCreatedProjectionOutsideTheManifestIsStillRefusedAsDrift(): void
    {
        // The migration can't run on a drifted database: the coordinator
        // refuses first. The documented recovery handles it.
        foreach (self::RUNTIME_DDL as $ddl) {
            $this->connection->executeStatement($ddl);
        }
        $this->insertRows();
        $before = $this->snapshot();
        $manifest = $this->manifestFingerprint();

        try {
            $this->applyMigration();
            self::fail('A drifted database must be refused.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('[S1-DB109]', $exception->getMessage());
        }

        self::assertSame($before, $this->snapshot());
        self::assertSame($manifest, $this->manifestFingerprint());
    }

    #[Test]
    public function theDocumentedRecoveryAdoptsADriftedProjectionAndKeepsEveryRow(): void
    {
        foreach (self::RUNTIME_DDL as $ddl) {
            $this->connection->executeStatement($ddl);
        }
        $this->insertRows();
        $before = $this->snapshot();

        // On a copy without the projection, the live schema matches the
        // manifest, so the projection is the only drift.
        $this->assertOnlyProjectionDrift();

        // The S1 spec's governed re-adoption, then the migration adopts in place.
        $this->connection->executeStatement('UPDATE waaseyaa_schema_authority SET schema_fingerprint = NULL, ledger_fingerprint = NULL WHERE authority_id = 1');
        $this->applyMigration();

        self::assertSame($before, $this->snapshot());
        $this->assertManifestDescribesLiveSchema();
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function incompatibleShapes(): iterable
    {
        yield 'extra metadata column' => [
            [str_replace('schema_version TEXT NOT NULL', 'schema_version TEXT NOT NULL, extra TEXT', self::RUNTIME_DDL[1])],
            '`search_metadata` is defined as',
        ];
        yield 'nullable metadata column' => [
            [str_replace('entity_type TEXT NOT NULL', 'entity_type TEXT', self::RUNTIME_DDL[1])],
            '`search_metadata` is defined as',
        ];
        yield 'different index columns' => [
            ['CREATE VIRTUAL TABLE search_index USING fts5(document_id UNINDEXED, title)'],
            '`search_index` is defined as',
        ];
        yield 'different tokenizer' => [
            ["CREATE VIRTUAL TABLE search_index USING fts5(document_id UNINDEXED, title, body, tokenize='trigram')"],
            '`search_index` is defined as',
        ];
        yield 'plain table named search_index' => [
            ['CREATE TABLE search_index (document_id TEXT, title TEXT, body TEXT)'],
            '`search_index` is defined as',
        ];
        yield 'index on a different column' => [
            [self::RUNTIME_DDL[1], 'CREATE INDEX idx_search_meta_source ON search_metadata(url)'],
            '`idx_search_meta_source` is defined as',
        ];
        yield 'leftover from an interrupted upgrade' => [
            ['CREATE VIRTUAL TABLE search_index_retired_porter USING fts5(document_id UNINDEXED, title, body)'],
            'interrupted tokenizer upgrade',
        ];
        yield 'orphaned FTS5 shadow table' => [
            ['CREATE TABLE search_index_data (id INTEGER PRIMARY KEY, block BLOB)'],
            '`search_index_data` exists without the `search_index` table',
        ];
        // SQLite names are case-insensitive: this collides with search_metadata.
        yield 'owned name in a different case' => [
            [str_replace('search_metadata', 'Search_Metadata', self::RUNTIME_DDL[1])],
            '`search_metadata` is defined as `CREATE TABLE Search_Metadata(',
        ];
    }

    #[Test]
    public function aLaterFailureInTheTransitionRollsBackTheCreatedProjection(): void
    {
        $before = $this->schemaSnapshot();
        $manifest = $this->manifestFingerprint();

        $this->failAfterTheMigrationRuns(function (): void {
            self::assertCount(5, $this->ownedObjects(), 'the migration created the projection inside the transition');
        });

        self::assertSame($before, $this->schemaSnapshot(), 'no projection object survives the rollback');
        self::assertSame($manifest, $this->manifestFingerprint());
        $this->assertManifestDescribesLiveSchema();
    }

    #[Test]
    public function aLaterFailureInTheTransitionRollsBackThePorterRebuild(): void
    {
        $this->coordinated(function (): void {
            $this->connection->executeStatement(self::PORTER_DDL);
            foreach (array_slice(self::RUNTIME_DDL, 1) as $ddl) {
                $this->connection->executeStatement($ddl);
            }
            $this->insertRows();
        });
        $before = $this->snapshot();
        $schema = $this->schemaSnapshot();
        $manifest = $this->manifestFingerprint();

        $this->failAfterTheMigrationRuns(function (): void {
            self::assertStringNotContainsString('porter', $this->ownedObjects()['search_index'], 'the rebuild ran inside the transition');
        });

        self::assertSame($before, $this->snapshot(), 'the retired index and every row are back');
        self::assertSame($schema, $this->schemaSnapshot(), 'no rebuild leftover survives the rollback');
        self::assertSame($manifest, $this->manifestFingerprint());
        self::assertSame('search:2', $this->connection->fetchOne("SELECT document_id FROM search_index WHERE search_index MATCH 'run'"), 'the Porter tokens are intact');
        $this->assertManifestDescribesLiveSchema();
    }

    /** @param list<string> $ddl */
    #[Test]
    #[DataProvider('incompatibleShapes')]
    public function refusesAnIncompatibleProjectionAndChangesNothing(array $ddl, string $difference): void
    {
        $this->coordinated(function () use ($ddl): void {
            foreach ($ddl as $statement) {
                $this->connection->executeStatement($statement);
            }
        });
        $before = $this->schemaSnapshot();
        $manifest = $this->manifestFingerprint();

        try {
            $this->applyMigration();
            self::fail('An incompatible search projection must be refused.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('[SEARCH-DB001]', $exception->getMessage());
            self::assertStringContainsString($difference, $exception->getMessage());
            self::assertStringContainsString('FW-SEARCH-PERSIST-01', $exception->getMessage(), 'names the recovery instructions');
        }

        self::assertSame($before, $this->schemaSnapshot(), 'nothing created, dropped or rebuilt');
        self::assertSame($manifest, $this->manifestFingerprint(), 'the transition rolled back');
    }

    private function applyMigration(): void
    {
        $migration = require self::MIGRATION;
        self::assertInstanceOf(Migration::class, $migration);
        $this->coordinated(fn() => $migration->up(new SchemaBuilder($this->connection)));
    }

    /**
     * Run the migration inside one coordinated transition, check its effect
     * there, then fail a later step of the same transition.
     */
    private function failAfterTheMigrationRuns(\Closure $inspect): void
    {
        $migration = require self::MIGRATION;
        self::assertInstanceOf(Migration::class, $migration);

        try {
            $this->coordinated(function () use ($migration, $inspect): void {
                $migration->up(new SchemaBuilder($this->connection));
                $inspect();
                throw new \RuntimeException('a later step of the transition failed');
            });
            self::fail('The transition must fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('a later step of the transition failed', $exception->getMessage());
        }
    }

    private function coordinated(\Closure $transition, bool $readopt = false): void
    {
        if ($readopt) {
            $this->connection->executeStatement('UPDATE waaseyaa_schema_authority SET schema_fingerprint = NULL, ledger_fingerprint = NULL WHERE authority_id = 1');
        }
        new SchemaMutationCoordinator($this->connection, new MigrationRepository($this->connection))->execute($transition);
    }

    private function insertRows(): void
    {
        $this->connection->executeStatement("INSERT INTO search_index (document_id, title, body) VALUES ('search:1', 'Giizhik', 'cedar'), ('search:2', 'Running', 'runs quickly')");
        $this->connection->executeStatement("INSERT INTO search_metadata (document_id, entity_type, created_at, schema_version) VALUES ('search:1', 'node', '2026-09-24', '2'), ('search:2', 'node', '2026-09-24', '2')");
    }

    /** @return array<string, string> name => stored SQL for the five owned objects */
    private function ownedObjects(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT name, sql FROM sqlite_master WHERE name IN ('search_index', 'search_metadata', 'idx_search_meta_entity_type', 'idx_search_meta_content_type', 'idx_search_meta_source') ORDER BY name",
        );

        return array_column($rows, 'sql', 'name');
    }

    /** @return list<array<string, mixed>> */
    private function schemaSnapshot(): array
    {
        return $this->connection->fetchAllAssociative('SELECT type, name, tbl_name, sql FROM sqlite_master ORDER BY name');
    }

    /** @return array{objects: array<string, string>, rows: array<string, list<array<string, mixed>>>} */
    private function snapshot(): array
    {
        return [
            'objects' => $this->ownedObjects(),
            'rows' => [
                'index' => $this->connection->fetchAllAssociative('SELECT document_id, title, body FROM search_index ORDER BY document_id'),
                'metadata' => $this->connection->fetchAllAssociative('SELECT * FROM search_metadata ORDER BY document_id'),
            ],
        ];
    }

    private function manifestFingerprint(): ?string
    {
        return new MigrationRepository($this->connection)->schemaAuthorityManifest()?->schemaFingerprint;
    }

    private function assertManifestDescribesLiveSchema(): void
    {
        $repository = new MigrationRepository($this->connection);
        self::assertSame($repository->currentLogicalSchemaFingerprint(), $repository->schemaAuthorityManifest()?->schemaFingerprint);
    }

    /**
     * The recovery's proof that the projection is the only drift: without it,
     * the live schema fingerprint equals the recorded manifest. Done on a
     * scratch copy of this database, never on the original.
     */
    private function assertOnlyProjectionDrift(): void
    {
        $scratch = sys_get_temp_dir() . '/waaseyaa_search_drift_copy_' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->connection->executeStatement('VACUUM INTO ' . $this->connection->quote($scratch));
        try {
            $copy = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $scratch]);
            $copy->executeStatement('DROP TABLE search_index');
            $copy->executeStatement('DROP TABLE search_metadata');
            $repository = new MigrationRepository($copy);
            self::assertSame($repository->schemaAuthorityManifest()?->schemaFingerprint, $repository->currentLogicalSchemaFingerprint());
            $copy->close();
        } finally {
            @unlink($scratch);
        }
    }
}

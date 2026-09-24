<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
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
use Waaseyaa\Testing\Database\TemporarySqliteDatabase;

/**
 * FW-AIV-PERSIST-01: the ai-vector migration owns `embeddings` on the
 * authoritative database. It creates the table, adopts a compatible
 * runtime-created table in place with its rows, and refuses any other shape
 * without changing anything.
 */
#[CoversNothing]
final class EmbeddingsSchemaMigrationTest extends TestCase
{
    private const string MIGRATION = __DIR__ . '/../../migrations/2026_09_24_000001_embeddings_schema.php';

    /** The DDL the pre-migration runtime code (`SqliteEmbeddingStorage::ensureSchema()`) issued. */
    private const string LEGACY_RUNTIME_DDL = 'CREATE TABLE IF NOT EXISTS embeddings (
                entity_type TEXT NOT NULL,
                entity_id TEXT NOT NULL,
                vector TEXT NOT NULL,
                updated_at INTEGER NOT NULL,
                PRIMARY KEY(entity_type, entity_id)
            )';

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
    public function createsTheTableWhenAbsent(): void
    {
        $this->applyMigration();

        $schema = $this->connection->createSchemaManager()->introspectTable('embeddings');
        self::assertSame(['entity_type', 'entity_id', 'vector', 'updated_at'], array_map(static fn($c): string => $c->getName(), array_values($schema->getColumns())));
        foreach ($schema->getColumns() as $column) {
            self::assertTrue($column->getNotnull(), $column->getName() . ' is NOT NULL');
        }
        self::assertSame(['entity_type', 'entity_id'], $this->primaryKey());
        $this->assertManifestDescribesLiveSchema();
    }

    #[Test]
    public function thePackageDeclaresTheMigrationAndTheMigratorInstallsItOnce(): void
    {
        $packageRoot = dirname(__DIR__, 2);
        $composer = json_decode((string) file_get_contents($packageRoot . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('migrations', $composer['extra']['waaseyaa']['migrations'] ?? null);

        $all = new MigrationLoader(dirname($packageRoot, 2), new PackageManifest(migrations: ['waaseyaa/ai-vector' => $packageRoot . '/migrations']))->loadAll();
        self::assertSame(['waaseyaa/ai-vector:2026_09_24_000001_embeddings_schema'], array_merge(...array_values(array_map('array_keys', $all))));

        $migrator = new Migrator($this->connection, new MigrationRepository($this->connection));
        self::assertSame(1, $migrator->run($all)->count);
        self::assertTrue($this->connection->createSchemaManager()->tablesExist(['embeddings']));
        self::assertSame(0, $migrator->run($all)->count);
        $this->assertManifestDescribesLiveSchema();
    }

    #[Test]
    public function adoptsARuntimeCreatedTableInPlaceAndKeepsEveryRow(): void
    {
        // FETDER's production state: the runtime table exists, and a later
        // re-record put it inside the manifest.
        $this->coordinated(function (): void {
            $this->connection->executeStatement(self::LEGACY_RUNTIME_DDL);
            $this->insertRows();
        });
        $before = $this->tableSnapshot();

        $this->applyMigration();

        self::assertSame($before, $this->tableSnapshot(), 'table definition and rows are unchanged');
        $this->assertManifestDescribesLiveSchema();
    }

    #[Test]
    public function aRuntimeCreatedTableOutsideTheManifestIsStillRefusedAsDrift(): void
    {
        // The migration can't run on a drifted database: the coordinator
        // refuses first. The recovery procedure (FW-AIV-PERSIST-01) handles it.
        $this->connection->executeStatement(self::LEGACY_RUNTIME_DDL);
        $this->insertRows();
        $before = $this->tableSnapshot();
        $manifest = $this->manifestFingerprint();

        try {
            $this->applyMigration();
            self::fail('A drifted database must be refused.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('[S1-DB109]', $exception->getMessage());
        }

        self::assertSame($before, $this->tableSnapshot());
        self::assertSame($manifest, $this->manifestFingerprint());
    }

    #[Test]
    public function theDocumentedRecoveryAdoptsADriftedTableAndKeepsEveryRow(): void
    {
        $this->connection->executeStatement(self::LEGACY_RUNTIME_DDL);
        $this->insertRows();
        $before = $this->tableSnapshot();

        // Step 2: on a copy without `embeddings`, the live schema matches the
        // manifest, so `embeddings` is the only drift.
        $this->assertOnlyEmbeddingsDrift();

        // Step 4: the S1 spec's governed re-adoption.
        $this->connection->executeStatement('UPDATE waaseyaa_schema_authority SET schema_fingerprint = NULL, ledger_fingerprint = NULL WHERE authority_id = 1');

        // Step 5: the migration adopts the table in place.
        $this->applyMigration();

        self::assertSame($before, $this->tableSnapshot());
        $this->assertManifestDescribesLiveSchema();
    }

    /** @return iterable<string, array{string, string}> */
    public static function incompatibleShapes(): iterable
    {
        yield 'extra column' => [
            'CREATE TABLE embeddings (entity_type TEXT NOT NULL, entity_id TEXT NOT NULL, vector TEXT NOT NULL, updated_at INTEGER NOT NULL, model TEXT NOT NULL DEFAULT \'x\', PRIMARY KEY(entity_type, entity_id))',
            'unexpected column "model"',
        ];
        yield 'missing column' => [
            'CREATE TABLE embeddings (entity_type TEXT NOT NULL, entity_id TEXT NOT NULL, vector TEXT NOT NULL, PRIMARY KEY(entity_type, entity_id))',
            'missing column "updated_at"',
        ];
        yield 'nullable column' => [
            'CREATE TABLE embeddings (entity_type TEXT NOT NULL, entity_id TEXT NOT NULL, vector TEXT, updated_at INTEGER NOT NULL, PRIMARY KEY(entity_type, entity_id))',
            'column "vector" must be NOT NULL',
        ];
        yield 'integer entity id' => [
            'CREATE TABLE embeddings (entity_type TEXT NOT NULL, entity_id INTEGER NOT NULL, vector TEXT NOT NULL, updated_at INTEGER NOT NULL, PRIMARY KEY(entity_type, entity_id))',
            'column "entity_id" must have text affinity',
        ];
        yield 'text updated_at' => [
            'CREATE TABLE embeddings (entity_type TEXT NOT NULL, entity_id TEXT NOT NULL, vector TEXT NOT NULL, updated_at TEXT NOT NULL, PRIMARY KEY(entity_type, entity_id))',
            'column "updated_at" must have integer affinity',
        ];
        yield 'different primary key' => [
            'CREATE TABLE embeddings (entity_type TEXT NOT NULL, entity_id TEXT NOT NULL PRIMARY KEY, vector TEXT NOT NULL, updated_at INTEGER NOT NULL)',
            'primary key must be (entity_type, entity_id)',
        ];
        yield 'reversed primary key' => [
            'CREATE TABLE embeddings (entity_type TEXT NOT NULL, entity_id TEXT NOT NULL, vector TEXT NOT NULL, updated_at INTEGER NOT NULL, PRIMARY KEY(entity_id, entity_type))',
            'primary key must be (entity_type, entity_id)',
        ];
    }

    #[Test]
    #[DataProvider('incompatibleShapes')]
    public function refusesAnIncompatibleTableAndChangesNothing(string $ddl, string $difference): void
    {
        $this->coordinated(function () use ($ddl): void {
            $this->connection->executeStatement($ddl);
        });
        $this->connection->executeStatement("INSERT INTO embeddings (entity_type, entity_id, vector" . (str_contains($ddl, 'updated_at') ? ', updated_at' : '') . (str_contains($ddl, 'model') ? ', model' : '') . ") VALUES ('note', '1', '[1]'" . (str_contains($ddl, 'updated_at') ? ", 1" : '') . (str_contains($ddl, 'model') ? ", 'm'" : '') . ')');
        // The row insert is data, not schema; re-record so only the migration is under test.
        $this->coordinated(static function (): void {});
        $before = $this->tableSnapshot();
        $manifest = $this->manifestFingerprint();

        try {
            $this->applyMigration();
            self::fail('An incompatible embeddings table must be refused.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('[AIV-DB001]', $exception->getMessage());
            self::assertStringContainsString($difference, $exception->getMessage());
            self::assertStringContainsString('FW-AIV-PERSIST-01', $exception->getMessage(), 'names the recovery instructions');
        }

        self::assertSame($before, $this->tableSnapshot(), 'nothing dropped, recreated or emptied');
        self::assertSame($manifest, $this->manifestFingerprint(), 'the transition rolled back');
    }

    private function applyMigration(): void
    {
        $migration = require self::MIGRATION;
        self::assertInstanceOf(Migration::class, $migration);
        $this->coordinated(fn() => $migration->up(new SchemaBuilder($this->connection)));
    }

    private function coordinated(\Closure $transition): void
    {
        new SchemaMutationCoordinator($this->connection, new MigrationRepository($this->connection))->execute($transition);
    }

    private function insertRows(): void
    {
        $this->connection->executeStatement("INSERT INTO embeddings (entity_type, entity_id, vector, updated_at) VALUES ('note', '1', '[1,0]', 100), ('user', '7', '[0,1]', 200)");
    }

    /** @return array{sql: string|false, rows: list<array<string, mixed>>} */
    private function tableSnapshot(): array
    {
        return [
            'sql' => $this->connection->fetchOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'embeddings'"),
            'rows' => $this->connection->fetchAllAssociative('SELECT * FROM embeddings ORDER BY entity_type, entity_id'),
        ];
    }

    /** @return list<string> */
    private function primaryKey(): array
    {
        $constraint = $this->connection->createSchemaManager()->introspectTable('embeddings')->getPrimaryKeyConstraint();
        self::assertNotNull($constraint);

        return array_map(static fn($name): string => $name->getIdentifier()->getValue(), $constraint->getColumnNames());
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
     * The procedure's proof that `embeddings` is the only drift: without it,
     * the live schema fingerprint equals the recorded manifest. Done here on
     * a scratch copy of this database, never on the original.
     */
    private function assertOnlyEmbeddingsDrift(): void
    {
        $scratch = sys_get_temp_dir() . '/waaseyaa_aiv_drift_copy_' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->connection->executeStatement('VACUUM INTO ' . $this->connection->quote($scratch));
        try {
            $copy = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $scratch]);
            $copy->executeStatement('DROP TABLE embeddings');
            $repository = new MigrationRepository($copy);
            self::assertSame($repository->schemaAuthorityManifest()?->schemaFingerprint, $repository->currentLogicalSchemaFingerprint());
            $copy->close();
        } finally {
            @unlink($scratch);
        }
    }
}

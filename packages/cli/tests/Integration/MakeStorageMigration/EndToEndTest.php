<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Integration\MakeStorageMigration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\CLI\Command\Migration\BackfillHelper;
use Waaseyaa\CLI\Command\Migration\BackfillRowCountMismatchException;
use Waaseyaa\CLI\Command\Migration\StorageMigrationEmitter;
use Waaseyaa\CLI\Command\Migration\StorageMigrationTemplate;
use Waaseyaa\CLI\Handler\MakeStorageMigrationHandler;
use Waaseyaa\CLI\Provider\MakeStorageMigrationServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Tests\Helper\TestEntityType;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

/**
 * T055 — End-to-end integration test for make:storage-migration.
 *
 * Verifies the full generator→apply→rollback round-trip:
 * 1. Run `make:storage-migration fixture_item` → file written to tempDir.
 * 2. Evaluate the emitted migration; call up() on an in-memory SQLite DB.
 * 3. Assert typed columns exist and _data values were backfilled.
 * 4. Verify BackfillHelper throws BackfillRowCountMismatchException when
 *    row counts diverge after backfill (simulated via a trigger).
 */
#[CoversClass(MakeStorageMigrationHandler::class)]
#[CoversClass(StorageMigrationEmitter::class)]
#[CoversClass(StorageMigrationTemplate::class)]
#[CoversClass(BackfillHelper::class)]
final class EndToEndTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa-msm-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/migrations', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    // -------------------------------------------------------------------------
    // T055 — generator writes migration file; up() backfills data
    // -------------------------------------------------------------------------

    #[Test]
    public function generator_writes_file_and_up_backfills_data(): void
    {
        // --- 1. Build fixture entity type with mixed field types ---
        $entityType = TestEntityType::stub(
            id: 'fixture_item',
            fieldDefinitions: [
                'title'      => new FieldDefinition(name: 'title', type: 'string'),
                'score'      => new FieldDefinition(name: 'score', type: 'integer'),
                'published'  => new FieldDefinition(name: 'published', type: 'boolean'),
                'created_at' => new FieldDefinition(name: 'created_at', type: 'datetime'),
            ],
        );

        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $entityTypeManager->registerEntityType($entityType);

        // --- 2. Run make:storage-migration via CliTester ---
        $handler  = new MakeStorageMigrationHandler(
            projectRoot: $this->tempDir,
            entityTypeManager: $entityTypeManager,
            emitter: new StorageMigrationEmitter(),
            template: new StorageMigrationTemplate(),
        );

        $tester = $this->createTesterFor($handler);
        $tester->execute(['fixture_item']);

        self::assertSame(0, $tester->getExitCode(), $tester->getStderr());
        self::assertStringContainsString('Created: migrations/', $tester->getStdout());

        // --- 3. Verify the file exists and contains expected markers ---
        $files = glob($this->tempDir . '/migrations/*.php');
        self::assertIsArray($files);
        self::assertCount(1, $files);
        $migrationPath = $files[0];

        $content = (string) file_get_contents($migrationPath);
        self::assertStringContainsString('fixture_item', $content);
        self::assertStringContainsString('BackfillHelper::backfill', $content);
        self::assertStringContainsString('ADD COLUMN title', $content);
        self::assertStringContainsString('ADD COLUMN score', $content);

        // --- 4. Stand up in-memory SQLite, create the entity table ---
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // Minimal table matching sql-blob shape: id + _data blob.
        $conn->executeStatement(
            'CREATE TABLE fixture_item (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                _data TEXT
            )',
        );

        // Seed two rows with _data containing field values.
        $conn->executeStatement(
            "INSERT INTO fixture_item (_data) VALUES (?)",
            [json_encode(['title' => 'Hello', 'score' => 42, 'published' => true, 'created_at' => '2025-01-01 00:00:00'], JSON_THROW_ON_ERROR)],
        );
        $conn->executeStatement(
            "INSERT INTO fixture_item (_data) VALUES (?)",
            [json_encode(['title' => 'World', 'score' => 7, 'published' => false, 'created_at' => '2025-06-15 12:00:00'], JSON_THROW_ON_ERROR)],
        );

        self::assertSame(2, (int) $conn->fetchOne('SELECT COUNT(*) FROM fixture_item'));

        // --- 5. Load and execute the migration up() ---
        $migration = require $migrationPath;

        self::assertIsObject($migration);
        self::assertInstanceOf(\Waaseyaa\Foundation\Migration\Migration::class, $migration);

        $schema = new SchemaBuilder($conn);
        $migration->up($schema);

        // --- 6. Assert typed columns exist ---
        $schemaManager = $conn->createSchemaManager();
        $columns = $schemaManager->listTableColumns('fixture_item');
        $columnNames = array_keys($columns);

        self::assertContains('title', $columnNames);
        self::assertContains('score', $columnNames);
        self::assertContains('published', $columnNames);

        // --- 7. Assert backfilled data is correct ---
        $rows = $conn->fetchAllAssociative('SELECT id, title, score, published FROM fixture_item ORDER BY id');

        self::assertCount(2, $rows);
        self::assertSame('Hello', $rows[0]['title']);
        self::assertSame(42, (int) $rows[0]['score']);
        self::assertSame('World', $rows[1]['title']);
        self::assertSame(7, (int) $rows[1]['score']);

        // --- 8. Row count is preserved (no rows deleted) ---
        self::assertSame(2, (int) $conn->fetchOne('SELECT COUNT(*) FROM fixture_item'));
    }

    // -------------------------------------------------------------------------
    // --dry-run prints content to stdout, no file written
    // -------------------------------------------------------------------------

    #[Test]
    public function dry_run_prints_content_and_writes_no_file(): void
    {
        $entityType = TestEntityType::stub(
            id: 'dry_entity',
            fieldDefinitions: [
                'name' => new FieldDefinition(name: 'name', type: 'string'),
            ],
        );

        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $entityTypeManager->registerEntityType($entityType);

        $handler = new MakeStorageMigrationHandler(
            projectRoot: $this->tempDir,
            entityTypeManager: $entityTypeManager,
            emitter: new StorageMigrationEmitter(),
            template: new StorageMigrationTemplate(),
        );

        $tester = $this->createTesterFor($handler);
        $tester->execute(['dry_entity', '--dry-run']);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('declare(strict_types=1)', $tester->getStdout());
        self::assertStringContainsString('dry_entity', $tester->getStdout());

        // No file should be written.
        $files = glob($this->tempDir . '/migrations/*.php') ?: [];
        self::assertCount(0, $files);
    }

    // -------------------------------------------------------------------------
    // Exit code 1 — unknown entity type
    // -------------------------------------------------------------------------

    #[Test]
    public function exit_code_1_for_unknown_entity_type(): void
    {
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());

        $handler = new MakeStorageMigrationHandler(
            projectRoot: $this->tempDir,
            entityTypeManager: $entityTypeManager,
            emitter: new StorageMigrationEmitter(),
            template: new StorageMigrationTemplate(),
        );

        $tester = $this->createTesterFor($handler);
        $tester->execute(['no_such_type']);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('Unknown entity type: no_such_type', $tester->getStderr());
    }

    // -------------------------------------------------------------------------
    // Defense in depth — malicious entity type id, even if already registered
    // -------------------------------------------------------------------------

    #[Test]
    public function it_rejects_a_malicious_entity_type_id_even_if_registered(): void
    {
        // TestEntityType::stub() does not itself constrain ids to a
        // machine-name shape, so a consumer app could register one with
        // arbitrary characters. The id feeds the generated migration
        // filename and doc-comments (StorageMigrationTemplate); this handler
        // must not trust it blindly.
        $maliciousId = "fixture'); system('touch pwned'); //";
        $entityType = TestEntityType::stub(
            id: $maliciousId,
            fieldDefinitions: [
                'title' => new FieldDefinition(name: 'title', type: 'string'),
            ],
        );

        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $entityTypeManager->registerEntityType($entityType);

        $handler = new MakeStorageMigrationHandler(
            projectRoot: $this->tempDir,
            entityTypeManager: $entityTypeManager,
            emitter: new StorageMigrationEmitter(),
            template: new StorageMigrationTemplate(),
        );

        $tester = $this->createTesterFor($handler);
        $tester->execute([$maliciousId]);

        self::assertSame(1, $tester->getExitCode());
        self::assertStringContainsString('not a safe machine name', $tester->getStderr());
        $files = glob($this->tempDir . '/migrations/*.php') ?: [];
        self::assertCount(0, $files);
    }

    // -------------------------------------------------------------------------
    // Exit code 2 — unsupported target
    // -------------------------------------------------------------------------

    #[Test]
    public function exit_code_2_for_unsupported_target(): void
    {
        $entityType = TestEntityType::stub('some_entity');
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $entityTypeManager->registerEntityType($entityType);

        $handler = new MakeStorageMigrationHandler(
            projectRoot: $this->tempDir,
            entityTypeManager: $entityTypeManager,
            emitter: new StorageMigrationEmitter(),
            template: new StorageMigrationTemplate(),
        );

        $tester = $this->createTesterFor($handler);
        $tester->execute(['some_entity', '--target', 'redis']);

        self::assertSame(2, $tester->getExitCode());
        self::assertStringContainsString('Unsupported target backend', $tester->getStderr());
    }

    // -------------------------------------------------------------------------
    // Exit code 3 — file already exists without --force
    // -------------------------------------------------------------------------

    #[Test]
    public function exit_code_3_when_file_exists_without_force(): void
    {
        $entityType = TestEntityType::stub(
            id: 'existing_entity',
            fieldDefinitions: [
                'label' => new FieldDefinition(name: 'label', type: 'string'),
            ],
        );

        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $entityTypeManager->registerEntityType($entityType);

        $handler = new MakeStorageMigrationHandler(
            projectRoot: $this->tempDir,
            entityTypeManager: $entityTypeManager,
            emitter: new StorageMigrationEmitter(),
            template: new StorageMigrationTemplate(),
        );

        // First run — should succeed.
        $tester = $this->createTesterFor($handler);
        $tester->execute(['existing_entity']);
        self::assertSame(0, $tester->getExitCode());

        // Second run without --force — should exit 3.
        $tester2 = $this->createTesterFor($handler);
        $tester2->execute(['existing_entity']);
        self::assertSame(3, $tester2->getExitCode());
        self::assertStringContainsString('exists', $tester2->getStderr());
        self::assertStringContainsString('--force', $tester2->getStderr());

        // Third run with --force — should succeed.
        $tester3 = $this->createTesterFor($handler);
        $tester3->execute(['existing_entity', '--force']);
        self::assertSame(0, $tester3->getExitCode());
    }

    // -------------------------------------------------------------------------
    // BackfillHelper row-count mismatch triggers exception → migration rolls back
    // -------------------------------------------------------------------------

    #[Test]
    public function backfill_row_count_mismatch_throws_exception(): void
    {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // Table has both _data and the target typed column (x INTEGER).
        $conn->executeStatement(
            'CREATE TABLE mismatch_test (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                _data TEXT,
                x INTEGER
            )',
        );
        $conn->executeStatement("INSERT INTO mismatch_test (_data) VALUES (?)", ['{"x":1}']);
        $conn->executeStatement("INSERT INTO mismatch_test (_data) VALUES (?)", ['{"x":2}']);

        // Install a DELETE trigger that fires AFTER each UPDATE on the table.
        // BackfillHelper updates rows one-by-one; the trigger deletes the first
        // row after the first UPDATE, so the post-count (1) differs from the
        // pre-count (2), causing BackfillRowCountMismatchException.
        $conn->executeStatement(
            "CREATE TRIGGER delete_first_on_update
             AFTER UPDATE ON mismatch_test
             WHEN (SELECT COUNT(*) FROM mismatch_test) = 2
             BEGIN
                 DELETE FROM mismatch_test WHERE id = 1;
             END",
        );

        $this->expectException(BackfillRowCountMismatchException::class);
        $this->expectExceptionMessageMatches('/row count mismatch/');

        $helper = new BackfillHelper();
        $helper->execute($conn, 'mismatch_test', ['x']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createTesterFor(MakeStorageMigrationHandler $handler): CliTester
    {
        $provider = new MakeStorageMigrationServiceProvider();
        $definition = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'make:storage-migration') {
                $definition = $cmd;
                break;
            }
        }
        self::assertNotNull($definition, 'make:storage-migration command not found in provider');

        $container = new class ($handler) implements ContainerInterface {
            public function __construct(private readonly MakeStorageMigrationHandler $handler) {}

            public function get(string $id): mixed
            {
                if ($id === MakeStorageMigrationHandler::class) {
                    return $this->handler;
                }
                throw new \RuntimeException("Not found: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === MakeStorageMigrationHandler::class;
            }
        };

        return CliTester::for($definition, $container);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $item_path = $dir . '/' . $item;
            if (is_dir($item_path)) {
                $this->removeDir($item_path);
            } else {
                @unlink($item_path);
            }
        }
        @rmdir($dir);
    }
}

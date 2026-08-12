<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Integration\Migration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Foundation\Migration\TableBuilder;

/**
 * Retained-red proof for S1-FW-DB-02.
 *
 * These tests describe the enterprise schema-authority contract. They are
 * committed failing before the coordinator implementation so the historical
 * candidate proves each baseline defect rather than merely asserting it in
 * prose.
 */
final class SchemaAuthorityRetainedRedTest extends TestCase
{
    #[Test]
    public function read_only_ledger_inspection_does_not_install_schema(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repository = new MigrationRepository($connection);
        $migrator = new Migrator($connection, $repository);

        self::assertSame([], $repository->getCompleted());
        self::assertSame(['pending' => [], 'completed' => []], $migrator->status([]));
        self::assertSame([], $repository->allWithChecksums());

        $schemaObjects = $connection->executeQuery(
            "SELECT name FROM sqlite_master WHERE type IN ('table', 'index') AND name LIKE 'waaseyaa_%' ORDER BY name",
        )->fetchFirstColumn();
        self::assertSame([], $schemaObjects, 'Read-only ledger inspection created schema objects.');
    }

    #[Test]
    public function ordinary_kernel_migration_boot_does_not_install_or_upgrade_the_ledger(): void
    {
        $database = DBALDatabase::createSqlite(':memory:', 'testing');
        $kernel = new class('/tmp/waaseyaa-zero-ddl-boot') extends AbstractKernel {
            public function bootMigrationInspection(DBALDatabase $database): void
            {
                $this->database = $database;
                $this->manifest = new \Waaseyaa\Foundation\Discovery\PackageManifest();
                $this->bootMigrations();
            }
        };

        $kernel->bootMigrationInspection($database);

        $schemaObjects = $database->getConnection()->executeQuery(
            "SELECT name FROM sqlite_master WHERE type IN ('table', 'index') AND name LIKE 'waaseyaa_%' ORDER BY name",
        )->fetchFirstColumn();
        self::assertSame([], $schemaObjects, 'Ordinary kernel boot created migration schema objects.');
    }

    #[Test]
    public function read_only_inspection_refuses_a_stale_ledger_without_upgrading_it(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE waaseyaa_migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration VARCHAR(255) NOT NULL,
                package VARCHAR(128) NOT NULL,
                batch INTEGER NOT NULL,
                ran_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
        );
        $repository = new MigrationRepository($connection);

        try {
            $repository->getCompleted();
            self::fail('Stale ledger inspection did not fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('S1-DB105', $exception->getMessage());
        }

        $columns = array_column(
            $connection->executeQuery('PRAGMA table_info(waaseyaa_migrations)')->fetchAllAssociative(),
            'name',
        );
        self::assertNotContains('checksum', $columns);
        self::assertNotContains('diff_hash', $columns);
    }

    #[Test]
    public function read_only_inspection_refuses_a_ledger_without_the_identity_constraint(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE waaseyaa_migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration VARCHAR(255) NOT NULL,
                package VARCHAR(128) NOT NULL,
                batch INTEGER NOT NULL,
                ran_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                checksum VARCHAR(64) NULL,
                diff_hash VARCHAR(64) NULL
            )',
        );
        $repository = new MigrationRepository($connection);

        try {
            $repository->getCompleted();
            self::fail('Ledger inspection accepted a missing identity constraint.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('S1-DB105', $exception->getMessage());
        }

        $indexes = $connection->executeQuery(
            'PRAGMA index_list(waaseyaa_migrations)',
        )->fetchAllAssociative();
        self::assertSame([], $indexes, 'Read-only inspection installed the missing identity constraint.');
    }

    #[Test]
    public function migration_identity_is_unique_in_the_database_ledger(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repository = new MigrationRepository($connection);
        $repository->createTable();
        $repository->record('app:duplicate', 'waaseyaa/one', 1);

        $duplicateRefused = false;
        try {
            $repository->record('app:duplicate', 'waaseyaa/two', 2);
        } catch (\Throwable) {
            $duplicateRefused = true;
        }

        self::assertTrue($duplicateRefused, 'The ledger accepted a duplicate canonical migration identity.');
        self::assertSame(['app:duplicate'], $repository->getCompleted());
    }

    #[Test]
    public function one_requested_plan_is_atomic_across_all_migration_nodes(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repository = new MigrationRepository($connection);
        $repository->createTable();
        $schema = new SchemaBuilder($connection);
        $migrator = new Migrator($connection, $repository);

        $first = new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('must_roll_back_with_plan', static function (TableBuilder $table): void {
                    $table->id();
                });
            }
        };
        $second = new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                throw new \RuntimeException('injected second-node failure');
            }
        };

        try {
            $migrator->run(['app' => [
                'app:0001_first' => $first,
                'app:0002_failure' => $second,
            ]]);
            self::fail('The injected second-node failure did not escape.');
        } catch (\RuntimeException $exception) {
            self::assertSame('injected second-node failure', $exception->getMessage());
        }

        self::assertFalse($schema->hasTable('must_roll_back_with_plan'));
        self::assertSame([], $repository->getCompleted());
    }

    #[Test]
    public function rollback_refuses_when_source_is_missing_and_retains_ledger_truth(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repository = new MigrationRepository($connection);
        $repository->createTable();
        $repository->record('app:missing_source', 'app', 1);
        $migrator = new Migrator($connection, $repository);

        $refused = false;
        try {
            $migrator->rollback([]);
        } catch (\Throwable) {
            $refused = true;
        }

        self::assertTrue($refused, 'Rollback reported success without an executable reverse source.');
        self::assertTrue($repository->hasRun('app:missing_source'));
    }
}

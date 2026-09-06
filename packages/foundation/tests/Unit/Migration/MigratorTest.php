<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Migration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\MigrationResult;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Foundation\Migration\TableBuilder;

#[CoversClass(Migrator::class)]
#[CoversClass(MigrationRepository::class)]
#[CoversClass(MigrationResult::class)]
final class MigratorTest extends TestCase
{
    private \Doctrine\DBAL\Connection $connection;
    private SchemaBuilder $schema;
    private MigrationRepository $repository;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->schema = new SchemaBuilder($this->connection);
        $this->repository = new MigrationRepository($this->connection);
        $this->repository->createTable();
    }

    #[Test]
    public function runs_pending_migrations(): void
    {
        $migrations = [
            'waaseyaa/test' => [
                '2026_03_01_000001_create_test' => new class extends Migration {
                    public function up(SchemaBuilder $schema): void
                    {
                        $schema->create('test', function (TableBuilder $table) {
                            $table->id();
                            $table->string('name');
                        });
                    }
                },
            ],
        ];

        $migrator = new Migrator($this->connection, $this->repository);
        $result = $migrator->run($migrations);

        $this->assertSame(1, $result->count);
        $this->assertTrue($this->schema->hasTable('test'));
    }

    #[Test]
    public function skips_already_run_migrations(): void
    {
        $migration = new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('test', function (TableBuilder $table) {
                    $table->id();
                });
            }
        };

        $migrations = ['waaseyaa/test' => ['2026_03_01_000001_create_test' => $migration]];

        $migrator = new Migrator($this->connection, $this->repository);
        $migrator->run($migrations);
        $result = $migrator->run($migrations);

        $this->assertSame(0, $result->count);
    }

    #[Test]
    public function rollback_reverses_last_batch(): void
    {
        $migration = new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('test', function (TableBuilder $table) {
                    $table->id();
                });
            }

            public function providesSupportedReverse(): bool
            {
                return true;
            }

            public function down(SchemaBuilder $schema): void
            {
                $schema->dropIfExists('test');
            }
        };

        $migrations = ['waaseyaa/test' => ['2026_03_01_000001_create_test' => $migration]];

        $migrator = new Migrator($this->connection, $this->repository);
        $migrator->run($migrations);
        $this->assertTrue($this->schema->hasTable('test'));

        $result = $migrator->rollback($migrations);
        $this->assertSame(1, $result->count);
        $this->assertFalse($this->schema->hasTable('test'));
    }

    #[Test]
    public function respects_package_ordering_via_after(): void
    {
        $order = [];

        $migrationA = new class ($order) extends Migration {
            public array $after = ['waaseyaa/base'];
            public function __construct(private array &$order) {}
            public function up(SchemaBuilder $schema): void
            {
                $this->order[] = 'A';
            }
        };

        $migrationB = new class ($order) extends Migration {
            public function __construct(private array &$order) {}
            public function up(SchemaBuilder $schema): void
            {
                $this->order[] = 'B';
            }
        };

        // B is in waaseyaa/base, A depends on waaseyaa/base — B must run first
        $migrations = [
            'waaseyaa/dependent' => ['2026_03_01_000001_a' => $migrationA],
            'waaseyaa/base' => ['2026_03_01_000001_b' => $migrationB],
        ];

        $migrator = new Migrator($this->connection, $this->repository);
        $migrator->run($migrations);

        $this->assertSame(['B', 'A'], $order);
    }

    #[Test]
    public function status_reports_pending_and_completed(): void
    {
        $migration = new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };

        $migrations = [
            'waaseyaa/test' => [
                '2026_03_01_000001_first' => $migration,
                '2026_03_01_000002_second' => $migration,
            ],
        ];

        $migrator = new Migrator($this->connection, $this->repository);

        // Before running
        $status = $migrator->status($migrations);
        $this->assertCount(2, $status['pending']);
        $this->assertCount(0, $status['completed']);

        // Run one batch
        $migrator->run($migrations);

        $status = $migrator->status($migrations);
        $this->assertCount(0, $status['pending']);
        $this->assertCount(2, $status['completed']);
    }

    #[Test]
    public function runRollsBackSchemaChangeWhenMigrationFails(): void
    {
        $migrator = new Migrator($this->connection, $this->repository);

        $failingMigration = new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('should_not_exist', function ($table) {
                    $table->id();
                });
                throw new \RuntimeException('Intentional failure');
            }
        };

        try {
            $migrator->run(['app' => ['app:20260317_fail' => $failingMigration]]);
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse($this->schema->hasTable('should_not_exist'));
        $this->assertFalse($this->repository->hasRun('app:20260317_fail'));
    }

    #[Test]
    public function rollbackRollsBackOnFailure(): void
    {
        $migrator = new Migrator($this->connection, $this->repository);

        $migration = new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('rollback_test', function ($table) {
                    $table->id();
                });
            }

            public function providesSupportedReverse(): bool
            {
                return true;
            }

            public function down(SchemaBuilder $schema): void
            {
                $schema->drop('rollback_test');
                throw new \RuntimeException('Intentional rollback failure');
            }
        };

        $migrator->run(['app' => ['app:20260317_rollback_test' => $migration]]);

        try {
            $migrator->rollback(['app' => ['app:20260317_rollback_test' => $migration]]);
        } catch (\RuntimeException) {
            // expected
        }

        // Table should still exist because rollback failed and was rolled back
        $this->assertTrue($this->schema->hasTable('rollback_test'));
        // Migration record should still exist
        $this->assertTrue($this->repository->hasRun('app:20260317_rollback_test'));
    }

    #[Test]
    public function statusReturnsCompletedWithMetadata(): void
    {
        $migrator = new Migrator($this->connection, $this->repository);

        $migration = new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };

        $migrations = ['app' => ['app:20260317_test' => $migration]];
        $migrator->run($migrations);

        $status = $migrator->status($migrations);

        $this->assertSame([], $status['pending']);
        $this->assertCount(1, $status['completed']);
        $this->assertSame('app:20260317_test', $status['completed'][0]['migration']);
        $this->assertSame('app', $status['completed'][0]['package']);
        $this->assertSame(1, $status['completed'][0]['batch']);
    }
}

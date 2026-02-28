<?php

declare(strict_types=1);

namespace Aurora\Foundation\Tests\Unit\Migration;

use Aurora\Foundation\Migration\Migration;
use Aurora\Foundation\Migration\MigrationRepository;
use Aurora\Foundation\Migration\MigrationResult;
use Aurora\Foundation\Migration\Migrator;
use Aurora\Foundation\Migration\SchemaBuilder;
use Aurora\Foundation\Migration\TableBuilder;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
            'aurora/test' => [
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

        $migrations = ['aurora/test' => ['2026_03_01_000001_create_test' => $migration]];

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

            public function down(SchemaBuilder $schema): void
            {
                $schema->dropIfExists('test');
            }
        };

        $migrations = ['aurora/test' => ['2026_03_01_000001_create_test' => $migration]];

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

        $migrationA = new class($order) extends Migration {
            public array $after = ['aurora/base'];
            public function __construct(private array &$order) {}
            public function up(SchemaBuilder $schema): void { $this->order[] = 'A'; }
        };

        $migrationB = new class($order) extends Migration {
            public function __construct(private array &$order) {}
            public function up(SchemaBuilder $schema): void { $this->order[] = 'B'; }
        };

        // B is in aurora/base, A depends on aurora/base — B must run first
        $migrations = [
            'aurora/dependent' => ['2026_03_01_000001_a' => $migrationA],
            'aurora/base' => ['2026_03_01_000001_b' => $migrationB],
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
            'aurora/test' => [
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
}

<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\MigrateRollbackHandler;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

#[CoversClass(MigrateRollbackHandler::class)]
final class MigrateRollbackHandlerTest extends TestCase
{
    #[Test]
    public function rollsBackLastBatch(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repository = new MigrationRepository($connection);
        $repository->createTable();
        $migrator = new Migrator($connection, $repository);

        $migration = new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('rollback_table', function ($table) {
                    $table->id();
                });
            }

            public function down(SchemaBuilder $schema): void
            {
                $schema->dropIfExists('rollback_table');
            }
        };

        $migrations = ['app' => ['app:20260317_create_rollback' => $migration]];
        $migrator->run($migrations);

        $tester = $this->createTester($migrator, fn() => $migrations);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('app:20260317_create_rollback', $tester->getStdout());
        $this->assertStringContainsString('Rolled back 1 migration', $tester->getStdout());
    }

    #[Test]
    public function reportsNothingToRollBack(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repository = new MigrationRepository($connection);
        $repository->createTable();
        $migrator = new Migrator($connection, $repository);

        $tester = $this->createTester($migrator, fn() => []);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('Nothing to roll back', $tester->getStdout());
    }

    private function createTester(Migrator $migrator, \Closure $migrationsProvider): CliTester
    {
        $handler = new MigrateRollbackHandler($migrator, $migrationsProvider);
        $definition = new HandlerCommand(
            name: 'migrate:rollback',
            description: 'Roll back the last batch of migrations',
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException("Not found: $id"); }
            public function has(string $id): bool { return false; }
        };

        return CliTester::for($definition, $container);
    }
}

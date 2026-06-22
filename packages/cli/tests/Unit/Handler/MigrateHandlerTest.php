<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\MigrateHandler;
use Waaseyaa\CLI\Command\HandlerOption;
use Waaseyaa\CLI\Command\HandlerOptionMode;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

#[CoversClass(MigrateHandler::class)]
final class MigrateHandlerTest extends TestCase
{
    #[Test]
    public function runsPendingMigrations(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repository = new MigrationRepository($connection);
        $repository->createTable();
        $migrator = new Migrator($connection, $repository);

        $migration = new class extends Migration {
            public function up(SchemaBuilder $schema): void
            {
                $schema->create('test_table', function ($table) {
                    $table->id();
                });
            }
        };

        $migrations = ['app' => ['app:20260317_create_test' => $migration]];

        $tester = $this->createTester($migrator, fn() => $migrations);
        $tester->execute([]);
        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('app:20260317_create_test', $tester->getStdout());
        $this->assertStringContainsString('Ran 1 migration', $tester->getStdout());
    }

    #[Test]
    public function reportsNothingToMigrate(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repository = new MigrationRepository($connection);
        $repository->createTable();
        $migrator = new Migrator($connection, $repository);

        $tester = $this->createTester($migrator, fn() => []);
        $tester->execute([]);

        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('Nothing to migrate', $tester->getStdout());
    }

    private function createTester(Migrator $migrator, \Closure $migrationsProvider): CliTester
    {
        $handler = new MigrateHandler($migrator, $migrationsProvider);
        $definition = new HandlerCommand(
            name: 'migrate',
            description: 'Run pending database migrations (use --dry-run to preview, --verify to audit)',
            options: [
                new HandlerOption(name: 'dry-run', mode: HandlerOptionMode::None, description: 'Preview pending migrations without applying any SQL or writing to the ledger.'),
                new HandlerOption(name: 'verify', mode: HandlerOptionMode::None, description: 'Compare ledger checksums against the live source. Read-only.'),
                new HandlerOption(name: 'json', mode: HandlerOptionMode::None, description: 'Emit machine-readable JSON instead of human-readable text.'),
            ],
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException("Not found: $id"); }
            public function has(string $id): bool { return false; }
        };

        return CliTester::for($definition, $container);
    }
}

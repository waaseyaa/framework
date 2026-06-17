<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\MigrateStatusHandler;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

#[CoversClass(MigrateStatusHandler::class)]
final class MigrateStatusHandlerTest extends TestCase
{
    #[Test]
    public function showsPendingAndCompletedMigrations(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repository = new MigrationRepository($connection);
        $repository->createTable();
        $migrator = new Migrator($connection, $repository);

        $ran = new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };
        $pending = new class extends Migration {
            public function up(SchemaBuilder $schema): void {}
        };

        $migrations = ['app' => [
            'app:20260317_first' => $ran,
            'app:20260318_second' => $pending,
        ]];

        // Run only the first migration
        $migrator->run(['app' => ['app:20260317_first' => $ran]]);

        $tester = $this->createTester($migrator, fn() => $migrations);
        $tester->execute([]);

        $stdout = $tester->getStdout();
        $this->assertSame(0, $tester->getExitCode());
        $this->assertStringContainsString('app:20260317_first', $stdout);
        $this->assertStringContainsString('Ran', $stdout);
        $this->assertStringContainsString('app:20260318_second', $stdout);
        $this->assertStringContainsString('Pending', $stdout);
    }

    private function createTester(Migrator $migrator, \Closure $migrationsProvider): CliTester
    {
        $handler = new MigrateStatusHandler($migrator, $migrationsProvider);
        $definition = new HandlerCommand(
            name: 'migrate:status',
            description: 'Show the status of each migration',
            handler: \Closure::fromCallable([$handler, 'execute']),
        );

        $container = new class implements \Psr\Container\ContainerInterface {
            public function get(string $id): mixed { throw new \RuntimeException("Not found: $id"); }
            public function has(string $id): bool { return false; }
        };

        return CliTester::for($definition, $container);
    }
}

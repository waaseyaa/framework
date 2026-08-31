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
use Waaseyaa\Foundation\Schema\Diff\CompositeDiff;
use Waaseyaa\Foundation\Schema\Migration\MigrationInterfaceV2;
use Waaseyaa\Foundation\Schema\Migration\MigrationPlan;

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

    #[Test]
    public function shows_pending_v2_migrations(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repository = new MigrationRepository($connection);
        $repository->createTable();
        $migrator = new Migrator($connection, $repository);

        $v2 = new class implements MigrationInterfaceV2 {
            public function migrationId(): string
            {
                return 'acme/application:v2:add-profile';
            }

            public function package(): string
            {
                return 'acme/application';
            }

            public function dependencies(): array
            {
                return [];
            }

            public function plan(): MigrationPlan
            {
                return new MigrationPlan(
                    migrationId: $this->migrationId(),
                    package: $this->package(),
                    dependencies: [],
                    root: new CompositeDiff([]),
                );
            }
        };

        $tester = $this->createTester($migrator, static fn(): array => [], static fn(): array => [$v2]);
        $tester->execute([]);

        self::assertSame(0, $tester->getExitCode());
        self::assertStringContainsString('acme/application:v2:add-profile', $tester->getStdout());
        self::assertStringContainsString('Pending', $tester->getStdout());
    }

    private function createTester(
        Migrator $migrator,
        \Closure $migrationsProvider,
        ?\Closure $v2MigrationsProvider = null,
    ): CliTester
    {
        $handler = new MigrateStatusHandler($migrator, $migrationsProvider, $v2MigrationsProvider);
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

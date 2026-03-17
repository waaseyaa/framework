<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\CLI\Command\MigrateCommand;
use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\Migrator;
use Waaseyaa\Foundation\Migration\SchemaBuilder;

#[CoversClass(MigrateCommand::class)]
final class MigrateCommandTest extends TestCase
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

        $command = new MigrateCommand($migrator, fn () => $migrations);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('app:20260317_create_test', $tester->getDisplay());
        $this->assertStringContainsString('Ran 1 migration', $tester->getDisplay());
    }

    #[Test]
    public function reportsNothingToMigrate(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $repository = new MigrationRepository($connection);
        $repository->createTable();
        $migrator = new Migrator($connection, $repository);

        $command = new MigrateCommand($migrator, fn () => []);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Nothing to migrate', $tester->getDisplay());
    }
}

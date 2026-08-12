<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Integration\Migration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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

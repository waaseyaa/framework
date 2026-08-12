<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Migration;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Migration\MigrationRepository;
use Waaseyaa\Foundation\Migration\SchemaMutationCoordinator;

#[CoversClass(SchemaMutationCoordinator::class)]
final class SchemaMutationCoordinatorTest extends TestCase
{
    #[Test]
    public function authority_ledger_and_schema_effect_share_one_transaction(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $coordinator = new SchemaMutationCoordinator(
            $connection,
            new MigrationRepository($connection),
        );

        try {
            $coordinator->execute(function () use ($connection): never {
                self::assertTrue($connection->isTransactionActive());
                $connection->executeStatement('CREATE TABLE must_roll_back (id INTEGER PRIMARY KEY)');
                throw new \RuntimeException('injected coordinator failure');
            });
            self::fail('Injected coordinator failure did not escape.');
        } catch (\RuntimeException $exception) {
            self::assertSame('injected coordinator failure', $exception->getMessage());
        }

        $tables = $connection->executeQuery(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        )->fetchFirstColumn();
        self::assertSame([], $tables);
    }

    #[Test]
    public function successful_transition_returns_its_result_after_installing_authority(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $coordinator = new SchemaMutationCoordinator(
            $connection,
            new MigrationRepository($connection),
        );

        $result = $coordinator->execute(static fn(): string => 'committed');

        self::assertSame('committed', $result);
        self::assertTrue($connection->createSchemaManager()->tablesExist([
            'waaseyaa_schema_authority',
            'waaseyaa_migrations',
        ]));
    }
}

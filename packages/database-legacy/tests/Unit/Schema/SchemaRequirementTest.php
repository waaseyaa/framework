<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Tests\Unit\Schema;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\SchemaInterface;
use Waaseyaa\Database\Schema\SchemaRequirement;

#[CoversClass(SchemaRequirement::class)]
final class SchemaRequirementTest extends TestCase
{
    /** @return iterable<string, array{AbstractPlatform}> */
    public static function platforms(): iterable
    {
        yield 'SQLite' => [new SQLitePlatform()];
        yield 'PostgreSQL' => [new PostgreSQLPlatform()];
        yield 'MySQL' => [new MySQLPlatform()];
    }

    #[Test]
    #[DataProvider('platforms')]
    public function required_fields_share_one_live_column_inspection(AbstractPlatform $platform): void
    {
        $manager = $this->createMock(AbstractSchemaManager::class);
        $manager->expects(self::once())->method('tablesExist')->with(['example'])->willReturn(true);
        $manager->expects(self::once())->method('listTableColumns')->with('example')->willReturn([
            'id' => new Column('id', Type::getType('integer')),
            // Quoted map keys must never become canonical field names.
            '"key"' => new Column('key', Type::getType('string')),
            'value' => new Column('value', Type::getType('string')),
        ]);
        $connection = $this->createStub(Connection::class);
        $connection->method('getConfiguration')->willReturn(new Configuration());
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('createSchemaManager')->willReturn($manager);

        SchemaRequirement::assertAvailable(new DBALDatabase($connection), 'example', ['id', 'key', 'value'], 'example:0001');
    }

    #[Test]
    public function a_fresh_validation_detects_schema_drift_on_the_same_connection(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement('CREATE TABLE example (id INTEGER PRIMARY KEY, "key" TEXT, value TEXT)');
        SchemaRequirement::assertAvailable($database, 'example', ['id', 'key', 'value'], 'example:0001');
        $database->getConnection()->executeStatement('ALTER TABLE example DROP COLUMN value');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing: value');
        SchemaRequirement::assertAvailable($database, 'example', ['id', 'key', 'value'], 'example:0001');
    }

    #[Test]
    public function a_missing_table_is_refused_even_without_required_fields(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing: table');
        SchemaRequirement::assertAvailable(DBALDatabase::createSqlite(), 'absent', [], 'example:0001');
    }

    #[Test]
    public function non_dbal_adapters_keep_their_field_checks(): void
    {
        $schema = $this->createMock(SchemaInterface::class);
        $schema->expects(self::once())->method('tableExists')->with('example')->willReturn(true);
        $schema->expects(self::exactly(2))->method('fieldExists')->willReturnMap([
            ['example', 'id', true], ['example', 'value', false],
        ]);
        $database = $this->createStub(DatabaseInterface::class);
        $database->method('schema')->willReturn($schema);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing: value');
        SchemaRequirement::assertAvailable($database, 'example', ['id', 'value'], 'example:0001');
    }

    #[Test]
    public function column_inspection_failure_preserves_the_guard_failure_and_cause(): void
    {
        $failure = new \RuntimeException('Column inspection unavailable.');
        $manager = $this->createMock(AbstractSchemaManager::class);
        $manager->expects(self::once())->method('tablesExist')->willReturn(true);
        $manager->expects(self::once())->method('listTableColumns')->willThrowException($failure);
        $connection = $this->createStub(Connection::class);
        $connection->method('getConfiguration')->willReturn(new Configuration());
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('createSchemaManager')->willReturn($manager);

        try {
            SchemaRequirement::assertAvailable(new DBALDatabase($connection), 'example', ['id'], 'example:0001');
            self::fail('Inspection failure was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('[S1-DB106]', $exception->getMessage());
            self::assertStringContainsString('inspection failed', $exception->getMessage());
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    #[Test]
    public function complete_schema_is_accepted_without_mutation(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement(
            'CREATE TABLE example (id INTEGER PRIMARY KEY, value TEXT NOT NULL)',
        );
        $before = $this->schemaSql($database);

        SchemaRequirement::assertAvailable(
            $database,
            'example',
            ['id', 'value'],
            'waaseyaa/example:0001',
        );

        self::assertSame($before, $this->schemaSql($database));
    }

    #[Test]
    public function missing_field_is_refused_without_repair(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement(
            'CREATE TABLE example (id INTEGER PRIMARY KEY)',
        );
        $before = $this->schemaSql($database);

        try {
            SchemaRequirement::assertAvailable(
                $database,
                'example',
                ['id', 'value'],
                'waaseyaa/example:0001',
            );
            self::fail('An incomplete runtime schema was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('[S1-DB106]', $exception->getMessage());
            self::assertStringContainsString('value', $exception->getMessage());
            self::assertStringContainsString('waaseyaa/example:0001', $exception->getMessage());
        }

        self::assertSame($before, $this->schemaSql($database));
    }

    /** @return list<string> */
    private function schemaSql(DBALDatabase $database): array
    {
        return $database->getConnection()->executeQuery(
            "SELECT sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY type, name",
        )->fetchFirstColumn();
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Tests\Unit\Schema;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\Schema\DBALSchema;
use Waaseyaa\Database\Schema\SchemaRequirement;

#[CoversClass(DBALDatabase::class)]
#[CoversClass(DBALSchema::class)]
final class SchemaInspectionScopeTest extends TestCase
{
    public function test_one_catalog_and_one_column_read_per_required_table(): void
    {
        $manager = $this->createMock(AbstractSchemaManager::class);
        $manager->expects(self::once())->method('listTableNames')->willReturn(['First', 'second']);
        $manager->expects(self::exactly(2))->method('listTableColumns')->willReturn([
            'id' => new Column('id', Type::getType('integer')),
            '"key"' => new Column('key', Type::getType('string')),
        ]);
        $connection = $this->createStub(Connection::class);
        $connection->method('getConfiguration')->willReturn(new Configuration());
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('createSchemaManager')->willReturn($manager);
        $database = new DBALDatabase($connection);

        $database->inspectSchema(function () use ($database): void {
            SchemaRequirement::assertAvailable($database, 'first', ['id'], 'fixture:1');
            SchemaRequirement::assertAvailable($database, 'first', ['key'], 'fixture:1');
            SchemaRequirement::assertAvailable($database, 'second', ['id', 'key'], 'fixture:1');
            self::assertTrue($database->schema()->fieldExists('second', 'key'));
        });
    }

    public function test_later_scope_and_escaped_adapter_observe_drift(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement('CREATE TABLE example (id INTEGER PRIMARY KEY, value TEXT)');
        $escaped = $database->inspectSchema(function () use ($database): DBALSchema {
            SchemaRequirement::assertAvailable($database, 'example', ['id', 'value'], 'fixture:1');
            return $database->schema();
        });
        $database->getConnection()->executeStatement('ALTER TABLE example DROP COLUMN value');
        self::assertFalse($escaped->fieldExists('example', 'value'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing: value');
        $database->inspectSchema(fn() => SchemaRequirement::assertAvailable($database, 'example', ['id', 'value'], 'fixture:1'));
    }

    public function test_throwing_scope_does_not_poison_later_schema_reads(): void
    {
        $database = DBALDatabase::createSqlite();
        try {
            $database->inspectSchema(function () use ($database): void {
                self::assertFalse($database->schema()->tableExists('later'));
                throw new \RuntimeException('synthetic failure');
            });
            self::fail('Scope failure was swallowed.');
        } catch (\RuntimeException $failure) {
            self::assertSame('synthetic failure', $failure->getMessage());
        }
        $database->getConnection()->executeStatement('CREATE TABLE later (id INTEGER PRIMARY KEY)');
        self::assertTrue($database->schema()->tableExists('later'));
    }

    public static function mutations(): iterable
    {
        yield 'create table' => ['createTable', ['another', ['fields' => ['id' => ['type' => 'int']]]]];
        yield 'drop table' => ['dropTable', ['example']];
        yield 'add field' => ['addField', ['example', 'value', ['type' => 'text']]];
        yield 'drop field' => ['dropField', ['example', 'id']];
        yield 'add index' => ['addIndex', ['example', 'index_id', ['id']]];
        yield 'drop index' => ['dropIndex', ['example', 'index_id']];
        yield 'unique key' => ['addUniqueKey', ['example', 'unique_id', ['id']]];
        yield 'primary key' => ['addPrimaryKey', ['example', ['id']]];
        yield 'foreign key' => ['addForeignKey', ['example', 'fk', ['id'], 'other', ['id']]];
    }

    #[DataProvider('mutations')]
    public function test_schema_mutation_is_refused_inside_inspection(string $method, array $arguments): void
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement('CREATE TABLE example (id INTEGER PRIMARY KEY)');
        try {
            $database->inspectSchema(fn() => $database->schema()->{$method}(...$arguments));
            self::fail('Inspection permitted DDL.');
        } catch (\LogicException $failure) {
            self::assertStringContainsString('read-only', $failure->getMessage());
        }
        self::assertTrue($database->schema()->tableExists('example'));
        $database->schema()->dropTable('example');
        self::assertFalse($database->schema()->tableExists('example'));
    }

    public function test_nested_failure_restores_the_outer_inspection_and_then_live_reads(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->inspectSchema(function () use ($database): void {
            $outer = $database->schema();
            self::assertFalse($outer->tableExists('example'));
            try {
                $database->inspectSchema(function () use ($database, $outer): void {
                    self::assertNotSame($outer, $database->schema());
                    throw new \RuntimeException('nested failure');
                });
            } catch (\RuntimeException $failure) {
                self::assertSame('nested failure', $failure->getMessage());
            }
            self::assertSame($outer, $database->schema());
        });
        $database->getConnection()->executeStatement('CREATE TABLE example (id INTEGER PRIMARY KEY)');
        self::assertTrue($database->schema()->tableExists('example'));
    }
}

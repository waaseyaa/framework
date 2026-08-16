<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\Schema\SchemaRequirement;

#[CoversClass(SchemaRequirement::class)]
final class SchemaRequirementTest extends TestCase
{
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

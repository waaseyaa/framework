<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Backend;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Backend\SqlColumnSchemaBuilder;
use Waaseyaa\Field\FieldDefinition;

#[CoversClass(SqlColumnSchemaBuilder::class)]
#[CoversClass(FieldDefinition::class)]
final class SqlColumnSchemaBuilderTest extends TestCase
{
    #[Test]
    public function it_projects_canonical_registered_field_types(): void
    {
        $database = DBALDatabase::createSqlite();
        $entityType = new EntityType(
            id: 'schema_builder_test',
            label: 'Schema builder test',
            class: EntityBase::class,
            keys: ['id' => 'id'],
        );
        $count = new FieldDefinition(name: 'count', type: 'integer');
        $active = new FieldDefinition(name: 'active', type: 'boolean');

        new SqlColumnSchemaBuilder($database)->buildTable(
            $entityType,
            'schema_builder_test',
            [$count, $active],
            [
                'fields' => ['id' => ['type' => 'serial', 'not null' => true]],
                'primary key' => ['id'],
                'indexes' => [],
            ],
        );

        self::assertTrue($database->schema()->fieldExists('schema_builder_test', 'count'));
        self::assertTrue($database->schema()->fieldExists('schema_builder_test', 'active'));
        self::assertSame(['type' => 'integer'], $count->toJsonSchema());
    }
}

<?php

declare(strict_types=1);

namespace Aurora\EntityStorage\Tests\Unit;

use Aurora\Database\PdoDatabase;
use Aurora\Entity\EntityType;
use Aurora\EntityStorage\SqlSchemaHandler;
use Aurora\EntityStorage\Tests\Fixtures\TestStorageEntity;
use PHPUnit\Framework\TestCase;

final class SqlSchemaHandlerTest extends TestCase
{
    private PdoDatabase $database;
    private EntityType $entityType;

    protected function setUp(): void
    {
        $this->database = PdoDatabase::createSqlite();
        $this->entityType = new EntityType(
            id: 'test_entity',
            label: 'Test Entity',
            class: TestStorageEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
        );
    }

    public function testEnsureTableCreatesTable(): void
    {
        $handler = new SqlSchemaHandler($this->entityType, $this->database);
        $handler->ensureTable();

        $this->assertTrue($this->database->schema()->tableExists('test_entity'));
    }

    public function testEnsureTableIdempotent(): void
    {
        $handler = new SqlSchemaHandler($this->entityType, $this->database);

        // Call twice — should not throw.
        $handler->ensureTable();
        $handler->ensureTable();

        $this->assertTrue($this->database->schema()->tableExists('test_entity'));
    }

    public function testSchemaHasCorrectColumns(): void
    {
        $handler = new SqlSchemaHandler($this->entityType, $this->database);
        $handler->ensureTable();

        $schema = $this->database->schema();

        $this->assertTrue($schema->fieldExists('test_entity', 'id'));
        $this->assertTrue($schema->fieldExists('test_entity', 'uuid'));
        $this->assertTrue($schema->fieldExists('test_entity', 'bundle'));
        $this->assertTrue($schema->fieldExists('test_entity', 'label'));
        $this->assertTrue($schema->fieldExists('test_entity', 'langcode'));
    }

    public function testGetTableName(): void
    {
        $handler = new SqlSchemaHandler($this->entityType, $this->database);

        $this->assertSame('test_entity', $handler->getTableName());
    }

    public function testAddFieldColumns(): void
    {
        $handler = new SqlSchemaHandler($this->entityType, $this->database);
        $handler->ensureTable();

        $handler->addFieldColumns([
            'status' => [
                'type' => 'int',
                'not null' => true,
                'default' => 1,
            ],
            'body' => [
                'type' => 'text',
                'not null' => false,
            ],
        ]);

        $schema = $this->database->schema();
        $this->assertTrue($schema->fieldExists('test_entity', 'status'));
        $this->assertTrue($schema->fieldExists('test_entity', 'body'));
    }
}

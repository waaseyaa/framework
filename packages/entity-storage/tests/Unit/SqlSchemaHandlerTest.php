<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestConfigEntity;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use PHPUnit\Framework\TestCase;

final class SqlSchemaHandlerTest extends TestCase
{
    private DBALDatabase $database;
    private EntityType $entityType;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
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

    public function testConfigEntitySchemaUsesVarcharId(): void
    {
        $this->ensureConfigTable();

        $schema = $this->database->schema();
        $this->assertTrue($schema->tableExists('node_type'));
        $this->assertTrue($schema->fieldExists('node_type', 'type'));
        $this->assertTrue($schema->fieldExists('node_type', 'name'));
        // Config entities should NOT have a UUID column.
        $this->assertFalse($schema->fieldExists('node_type', 'uuid'));
    }

    public function testConfigEntityCanInsertStringId(): void
    {
        $this->ensureConfigTable();

        // Verify we can insert a string ID.
        $this->database->insert('node_type')
            ->fields(['type', 'name', 'bundle', 'langcode', '_data'])
            ->values([
                'type' => 'article',
                'name' => 'Article',
                'bundle' => '',
                'langcode' => 'en',
                '_data' => '{}',
            ])
            ->execute();

        $result = $this->database->select('node_type')
            ->fields('node_type')
            ->condition('type', 'article')
            ->execute();

        $row = null;
        foreach ($result as $r) {
            $row = (array) $r;
            break;
        }

        $this->assertNotNull($row);
        $this->assertSame('article', $row['type']);
        $this->assertSame('Article', $row['name']);
    }

    private function ensureConfigTable(): void
    {
        $configType = new EntityType(
            id: 'node_type',
            label: 'Content Type',
            class: TestConfigEntity::class,
            keys: [
                'id' => 'type',
                'label' => 'name',
                'bundle' => 'bundle',
                'langcode' => 'langcode',
            ],
        );

        $handler = new SqlSchemaHandler($configType, $this->database);
        $handler->ensureTable();
    }
}

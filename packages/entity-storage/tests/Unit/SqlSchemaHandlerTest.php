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

    public function testEnsureRevisionTableCreatesTableWithCompositePk(): void
    {
        $entityType = new EntityType(
            id: 'node',
            label: 'Content',
            class: TestStorageEntity::class,
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );

        $db = DBALDatabase::createSqlite();
        $handler = new SqlSchemaHandler($entityType, $db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $schema = $db->schema();
        $this->assertTrue($schema->tableExists('node_revision'));
        $this->assertTrue($schema->fieldExists('node_revision', 'entity_id'));
        $this->assertTrue($schema->fieldExists('node_revision', 'revision_id'));
        $this->assertTrue($schema->fieldExists('node_revision', 'revision_created'));
        $this->assertTrue($schema->fieldExists('node_revision', 'revision_log'));
        $this->assertTrue($schema->fieldExists('node_revision', '_data'));
    }

    public function testEnsureRevisionTableIsIdempotent(): void
    {
        $entityType = new EntityType(
            id: 'node',
            label: 'Content',
            class: TestStorageEntity::class,
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
        );

        $db = DBALDatabase::createSqlite();
        $handler = new SqlSchemaHandler($entityType, $db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();
        $handler->ensureRevisionTable(); // second call should be a no-op

        $this->assertTrue($db->schema()->tableExists('node_revision'));
    }

    public function testEnsureTableAddsRevisionIdColumnForRevisionableTypes(): void
    {
        $entityType = new EntityType(
            id: 'node',
            label: 'Content',
            class: TestStorageEntity::class,
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
        );

        $db = DBALDatabase::createSqlite();
        $handler = new SqlSchemaHandler($entityType, $db);
        $handler->ensureTable();

        $this->assertTrue($db->schema()->fieldExists('node', 'revision_id'));
    }

    public function testSeedRevisionsCreatesRevision1ForExistingRows(): void
    {
        $entityType = new EntityType(
            id: 'node',
            label: 'Content',
            class: TestStorageEntity::class,
            keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
        );

        $db = DBALDatabase::createSqlite();
        $handler = new SqlSchemaHandler($entityType, $db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        // Insert an existing row without a revision.
        $db->insert('node')
            ->fields(['nid', 'uuid', 'title', 'bundle', 'langcode', '_data'])
            ->values(['nid' => '1', 'uuid' => 'abc', 'title' => 'Existing', 'bundle' => 'page', 'langcode' => 'en', '_data' => '{}'])
            ->execute();

        $handler->seedRevisions();

        // Verify revision 1 was created.
        $result = $db->query('SELECT * FROM node_revision WHERE entity_id = ? AND revision_id = 1', ['1']);
        $revRow = null;
        foreach ($result as $row) {
            $revRow = (array) $row;
            break;
        }
        $this->assertNotNull($revRow);
        $this->assertSame('Existing', $revRow['title']);

        // Verify base table pointer updated.
        $result = $db->query('SELECT revision_id FROM node WHERE nid = ?', ['1']);
        foreach ($result as $row) {
            $this->assertSame(1, (int) ((array) $row)['revision_id']);
        }

        // Verify idempotent — second call is a no-op.
        $handler->seedRevisions();
        $result = $db->query('SELECT COUNT(*) as cnt FROM node_revision WHERE entity_id = ?', ['1']);
        foreach ($result as $row) {
            $this->assertSame(1, (int) ((array) $row)['cnt']);
        }
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

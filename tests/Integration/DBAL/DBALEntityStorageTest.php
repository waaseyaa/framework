<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\DBAL;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityConstants;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

/**
 * Integration tests for DBAL-backed entity storage (#459).
 *
 * Exercises CRUD operations, _data JSON blob, entity queries, and
 * schema handling through DBALDatabase with in-memory SQLite.
 */
final class DBALEntityStorageTest extends TestCase
{
    private DBALDatabase $database;
    private EntityType $entityType;
    private EventDispatcher $eventDispatcher;
    private EntityRepository $storage;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();

        $this->entityType = new EntityType(
            id: 'test_item',
            label: 'Test Item',
            class: DBALTestEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'title',
                'langcode' => 'langcode',
            ],
        );

        $this->eventDispatcher = new EventDispatcher();

        $schemaHandler = new SqlSchemaHandler($this->entityType, $this->database);
        $schemaHandler->ensureTable();
        $schemaHandler->addFieldColumns([
            'body' => ['type' => 'text', 'not null' => false],
            'status' => ['type' => 'int', 'not null' => true, 'default' => 1],
            'weight' => ['type' => 'int', 'not null' => false],
            'price' => ['type' => 'float', 'not null' => false],
        ]);

        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver, $this->entityType->getKeys()['id'] ?? 'id');
        $this->storage = new EntityRepository(
            $this->entityType,
            $driver,
            $this->eventDispatcher,
            database: $this->database,
        );
    }

    // ---- CRUD: Create ----

    public function testCreateReturnsNewEntity(): void
    {
        $entity = $this->storage->create([
            'title' => 'Test Create',
            'bundle' => 'default',
        ]);

        $this->assertInstanceOf(DBALTestEntity::class, $entity);
        $this->assertTrue($entity->isNew());
        $this->assertNull($entity->id());
        $this->assertSame('Test Create', $entity->label());
    }

    public function testCreateAutoGeneratesUuid(): void
    {
        $entity = $this->storage->create(['title' => 'UUID Test']);
        $uuid = $entity->uuid();

        $this->assertNotEmpty($uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid,
        );
    }

    // ---- CRUD: Save (Insert) ----

    public function testSaveNewEntityAssignsId(): void
    {
        $entity = $this->storage->create([
            'title' => 'Save Test',
            'bundle' => 'default',
        ]);

        $result = $this->storage->save($entity, validate: false);

        $this->assertSame(EntityConstants::SAVED_NEW, $result);
        $this->assertNotNull($entity->id());
        // EntityRepository assigns the driver's freshly-written id as-is
        // (a string), unlike the deleted SqlEntityStorage which explicitly
        // cast it to int; callers are expected to (string)-cast ids (see
        // GenealogyPedigreeServiceTest for the canonical convention).
        $this->assertIsNumeric($entity->id());
        $this->assertFalse($entity->isNew());
    }

    // ---- CRUD: Load ----

    public function testLoadReturnsPersistedEntity(): void
    {
        $entity = $this->storage->create([
            'title' => 'Load Test',
            'bundle' => 'page',
            'body' => 'Some body text',
            'status' => 0,
        ]);
        $this->storage->save($entity, validate: false);

        $loaded = $this->storage->find((string) $entity->id());

        $this->assertNotNull($loaded);
        $this->assertSame((string) $entity->id(), (string) $loaded->id());
        $this->assertSame('Load Test', $loaded->label());
        $this->assertSame('page', $loaded->bundle());
        $this->assertSame('Some body text', $loaded->get('body'));
        $this->assertFalse($loaded->isNew());
    }

    public function testLoadReturnsNullForNonexistent(): void
    {
        $this->assertNull($this->storage->find((string) 999999));
    }

    public function testLoadMultipleReturnsKeyedArray(): void
    {
        $e1 = $this->storage->create(['title' => 'E1', 'bundle' => 'a']);
        $e2 = $this->storage->create(['title' => 'E2', 'bundle' => 'b']);
        $e3 = $this->storage->create(['title' => 'E3', 'bundle' => 'c']);
        $this->storage->save($e1, validate: false);
        $this->storage->save($e2, validate: false);
        $this->storage->save($e3, validate: false);

        $loaded = [];
        foreach ($this->storage->findMany([$e1->id(), $e3->id()]) as $entity) {
            $id = $entity->id();
            if ($id !== null) {
                $loaded[$id] = $entity;
            }
        }

        $this->assertCount(2, $loaded);
        $this->assertArrayHasKey($e1->id(), $loaded);
        $this->assertArrayHasKey($e3->id(), $loaded);
    }

    // ---- CRUD: Update ----

    public function testUpdatePersistsChanges(): void
    {
        $entity = $this->storage->create([
            'title' => 'Original',
            'bundle' => 'blog',
            'body' => 'Original body',
        ]);
        $this->storage->save($entity, validate: false);

        $entity->set('title', 'Updated');
        $entity->set('body', 'Updated body');
        $result = $this->storage->save($entity, validate: false);

        $this->assertSame(EntityConstants::SAVED_UPDATED, $result);

        $reloaded = $this->storage->find((string) $entity->id());
        $this->assertSame('Updated', $reloaded->label());
        $this->assertSame('Updated body', $reloaded->get('body'));
    }

    public function testUpdatePreservesUuid(): void
    {
        $entity = $this->storage->create(['title' => 'UUID Keep', 'bundle' => 'a']);
        $this->storage->save($entity, validate: false);
        $originalUuid = $entity->uuid();

        $entity->set('title', 'UUID Still Here');
        $this->storage->save($entity, validate: false);

        $loaded = $this->storage->find((string) $entity->id());
        $this->assertSame($originalUuid, $loaded->uuid());
    }

    // ---- CRUD: Delete ----

    public function testDeleteRemovesEntities(): void
    {
        $e1 = $this->storage->create(['title' => 'Delete 1', 'bundle' => 'a']);
        $e2 = $this->storage->create(['title' => 'Keep', 'bundle' => 'a']);
        $this->storage->save($e1, validate: false);
        $this->storage->save($e2, validate: false);

        $this->storage->delete($e1);

        $this->assertNull($this->storage->find((string) $e1->id()));
        $this->assertNotNull($this->storage->find((string) $e2->id()));
    }

    public function testDeleteEmptyArrayDoesNotThrow(): void
    {
        $this->storage->deleteMany([]);
        $this->assertTrue(true);
    }

    // ---- Field types ----

    public function testAllFieldTypesRoundTrip(): void
    {
        $entity = $this->storage->create([
            'title' => 'Field Types',
            'bundle' => 'test',
            'body' => 'Long text body',
            'status' => 1,
            'weight' => 42,
            'price' => 19.99,
            'langcode' => 'en',
        ]);
        $this->storage->save($entity, validate: false);

        $loaded = $this->storage->find((string) $entity->id());
        $this->assertSame('Field Types', $loaded->label());
        $this->assertSame('Long text body', $loaded->get('body'));
        $this->assertSame('en', $loaded->language());
    }

    // ---- _data JSON blob: non-schema fields ----

    public function testNonSchemaFieldsStoredInDataBlob(): void
    {
        $entity = $this->storage->create([
            'title' => 'Data Blob',
            'bundle' => 'test',
            'custom_field' => 'custom_value',
            'tags' => ['php', 'dbal'],
        ]);
        $this->storage->save($entity, validate: false);

        $loaded = $this->storage->find((string) $entity->id());
        $this->assertSame('custom_value', $loaded->get('custom_field'));
        $this->assertSame(['php', 'dbal'], $loaded->get('tags'));
    }

    public function testDataBlobPreservedOnUpdate(): void
    {
        $entity = $this->storage->create([
            'title' => 'Blob Update',
            'bundle' => 'test',
            'meta' => ['key' => 'value'],
        ]);
        $this->storage->save($entity, validate: false);

        $entity->set('title', 'Blob Updated');
        $this->storage->save($entity, validate: false);

        $loaded = $this->storage->find((string) $entity->id());
        $this->assertSame('Blob Updated', $loaded->label());
        $this->assertSame(['key' => 'value'], $loaded->get('meta'));
    }

    public function testDataBlobWithNestedStructures(): void
    {
        $nested = [
            'level1' => [
                'level2' => [
                    'level3' => 'deep_value',
                    'numbers' => [1, 2, 3],
                ],
            ],
            'bool_true' => true,
            'bool_false' => false,
            'null_val' => null,
            'int_val' => 42,
            'float_val' => 3.14,
        ];

        $entity = $this->storage->create([
            'title' => 'Nested Blob',
            'bundle' => 'test',
            'nested_data' => $nested,
        ]);
        $this->storage->save($entity, validate: false);

        $loaded = $this->storage->find((string) $entity->id());
        $this->assertSame($nested, $loaded->get('nested_data'));
    }

    // ---- Entity queries ----

    public function testQueryWithCondition(): void
    {
        $this->createSampleEntities();

        $ids = $this->storage->getQuery()->accessCheck(false)
            ->condition('bundle', 'blog')
            ->execute();

        $this->assertCount(2, $ids);
    }

    public function testQueryWithSort(): void
    {
        $this->createSampleEntities();

        $ids = $this->storage->getQuery()->accessCheck(false)
            ->sort('title', 'DESC')
            ->execute();

        $titles = array_map(
            fn($id) => $this->storage->find((string) $id)->label(),
            $ids,
        );

        $expected = $titles;
        usort($expected, fn($a, $b) => strcmp($b, $a));
        $this->assertSame($expected, $titles);
    }

    public function testQueryWithRange(): void
    {
        $this->createSampleEntities();

        $ids = $this->storage->getQuery()->accessCheck(false)
            ->sort('id', 'ASC')
            ->range(0, 2)
            ->execute();

        $this->assertCount(2, $ids);
    }

    public function testQueryWithPaging(): void
    {
        // Create 5 entities.
        for ($i = 1; $i <= 5; $i++) {
            $entity = $this->storage->create(['title' => "Item $i", 'bundle' => 'page']);
            $this->storage->save($entity, validate: false);
        }

        // Page 1.
        $page1 = $this->storage->getQuery()->accessCheck(false)
            ->sort('id', 'ASC')
            ->range(0, 2)
            ->execute();
        $this->assertCount(2, $page1);

        // Page 2.
        $page2 = $this->storage->getQuery()->accessCheck(false)
            ->sort('id', 'ASC')
            ->range(2, 2)
            ->execute();
        $this->assertCount(2, $page2);

        // Page 3 (only 1 remaining).
        $page3 = $this->storage->getQuery()->accessCheck(false)
            ->sort('id', 'ASC')
            ->range(4, 2)
            ->execute();
        $this->assertCount(1, $page3);

        // No overlap.
        $this->assertEmpty(array_intersect($page1, $page2));
    }

    public function testQueryCount(): void
    {
        $this->createSampleEntities();

        $result = $this->storage->getQuery()->accessCheck(false)
            ->condition('bundle', 'blog')
            ->count()
            ->execute();

        $this->assertSame([2], $result);
    }

    public function testQueryWithMultipleConditions(): void
    {
        $this->createSampleEntities();

        $ids = $this->storage->getQuery()->accessCheck(false)
            ->condition('bundle', 'blog')
            ->condition('title', 'Blog Post 1')
            ->execute();

        $this->assertCount(1, $ids);
    }

    public function testQueryContainsOperator(): void
    {
        $this->createSampleEntities();

        $ids = $this->storage->getQuery()->accessCheck(false)
            ->condition('title', 'Blog', 'CONTAINS')
            ->execute();

        $this->assertCount(2, $ids);
    }

    public function testQueryStartsWithOperator(): void
    {
        $this->createSampleEntities();

        $ids = $this->storage->getQuery()->accessCheck(false)
            ->condition('title', 'About', 'STARTS_WITH')
            ->execute();

        $this->assertCount(1, $ids);
        $entity = $this->storage->find((string) $ids[0]);
        $this->assertSame('About Page', $entity->label());
    }

    public function testQueryLikeWithWildcards(): void
    {
        // Create entities with literal % and _ in titles.
        $e1 = $this->storage->create(['title' => '100% Complete', 'bundle' => 'test']);
        $e2 = $this->storage->create(['title' => 'field_name', 'bundle' => 'test']);
        $e3 = $this->storage->create(['title' => 'Normal Title', 'bundle' => 'test']);
        $this->storage->save($e1, validate: false);
        $this->storage->save($e2, validate: false);
        $this->storage->save($e3, validate: false);

        // CONTAINS with % in user input should only match the literal %.
        $ids = $this->storage->getQuery()->accessCheck(false)
            ->condition('title', '100%', 'CONTAINS')
            ->execute();
        $this->assertCount(1, $ids);

        // CONTAINS with _ in user input should only match the literal _.
        $ids = $this->storage->getQuery()->accessCheck(false)
            ->condition('title', 'field_', 'CONTAINS')
            ->execute();
        $this->assertCount(1, $ids);
    }

    // ---- Schema handler ----

    public function testSchemaHandlerCreatesTable(): void
    {
        $newType = new EntityType(
            id: 'schema_test',
            label: 'Schema Test',
            class: DBALTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );

        $handler = new SqlSchemaHandler($newType, $this->database);
        $handler->ensureTable();

        $this->assertTrue($this->database->schema()->tableExists('schema_test'));
    }

    public function testSchemaHandlerEnsureTableIsIdempotent(): void
    {
        $handler = new SqlSchemaHandler($this->entityType, $this->database);

        // Table already created in setUp; should not throw.
        $handler->ensureTable();
        $this->assertTrue($this->database->schema()->tableExists('test_item'));
    }

    public function testSchemaHandlerAddsColumns(): void
    {
        $newType = new EntityType(
            id: 'col_test',
            label: 'Column Test',
            class: DBALTestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );

        $handler = new SqlSchemaHandler($newType, $this->database);
        $handler->ensureTable();

        $handler->addFieldColumns([
            'description' => ['type' => 'text', 'not null' => false],
            'priority' => ['type' => 'int', 'not null' => true, 'default' => 0],
        ]);

        $schema = $this->database->schema();
        $this->assertTrue($schema->fieldExists('col_test', 'description'));
        $this->assertTrue($schema->fieldExists('col_test', 'priority'));
    }

    public function testSchemaHandlerReturnsTableName(): void
    {
        $handler = new SqlSchemaHandler($this->entityType, $this->database);
        $this->assertSame('test_item', $handler->getTableName());
    }

    // ---- Helpers ----

    private function createSampleEntities(): void
    {
        $items = [
            ['title' => 'Blog Post 1', 'bundle' => 'blog'],
            ['title' => 'Blog Post 2', 'bundle' => 'blog'],
            ['title' => 'About Page', 'bundle' => 'page'],
        ];

        foreach ($items as $values) {
            $entity = $this->storage->create($values);
            $this->storage->save($entity, validate: false);
        }
    }
}

/**
 * Concrete entity class for DBAL integration tests.
 */
class DBALTestEntity extends ContentEntityBase
{
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $title;
    #[Field(type: 'string', read: FieldReadLevel::Public)] public string $body;
    #[Field(type: 'integer', read: FieldReadLevel::Public)] public int $status;
    #[Field(type: 'integer', read: FieldReadLevel::Public)] public int $weight;
    #[Field(type: 'float', read: FieldReadLevel::Public)] public float $price;
    #[Field(type: 'json', read: FieldReadLevel::Public)] public array $custom_field;
    #[Field(type: 'json', read: FieldReadLevel::Public)] public array $tags;
    #[Field(type: 'json', read: FieldReadLevel::Public)] public array $nested_data;
    #[Field(type: 'json', read: FieldReadLevel::Public)] public array $meta;

    public function __construct(
        array $values = [],
        string $entityTypeId = 'test_item',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}

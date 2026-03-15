<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase4;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityConstants;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

/**
 * Full CRUD lifecycle integration test through the entity storage stack.
 *
 * Exercises: waaseyaa/entity + waaseyaa/database-legacy + waaseyaa/entity-storage
 * working together end-to-end with a real SQLite in-memory database.
 */
final class EntityStorageCrudTest extends TestCase
{
    private PdoDatabase $database;
    private EntityType $entityType;
    private EventDispatcher $eventDispatcher;
    private SqlEntityStorage $storage;

    protected function setUp(): void
    {
        $this->database = PdoDatabase::createSqlite();

        $this->entityType = new EntityType(
            id: 'article',
            label: 'Article',
            class: TestArticleEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'title',
                'langcode' => 'langcode',
            ],
        );

        $this->eventDispatcher = new EventDispatcher();

        // Use SqlSchemaHandler to create the table.
        $schemaHandler = new SqlSchemaHandler($this->entityType, $this->database);
        $schemaHandler->ensureTable();

        // Add a custom column for testing.
        $schemaHandler->addFieldColumns([
            'body' => [
                'type' => 'text',
                'not null' => false,
            ],
            'status' => [
                'type' => 'int',
                'not null' => true,
                'default' => 1,
            ],
        ]);

        $this->storage = new SqlEntityStorage(
            $this->entityType,
            $this->database,
            $this->eventDispatcher,
        );
    }

    // ---- CREATE tests ----

    public function testCreateReturnsNewEntityWithValues(): void
    {
        $entity = $this->storage->create([
            'title' => 'Hello World',
            'bundle' => 'blog',
        ]);

        $this->assertInstanceOf(TestArticleEntity::class, $entity);
        $this->assertTrue($entity->isNew());
        $this->assertNull($entity->id());
        $this->assertSame('Hello World', $entity->label());
        $this->assertSame('blog', $entity->bundle());
        $this->assertNotEmpty($entity->uuid(), 'UUID should be auto-generated');
    }

    public function testCreateAutoGeneratesUuid(): void
    {
        $entity = $this->storage->create(['title' => 'No UUID']);

        $uuid = $entity->uuid();
        $this->assertNotEmpty($uuid);
        // UUID v4 format.
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid,
        );
    }

    // ---- SAVE (INSERT) tests ----

    public function testSaveNewEntityReturnsNewConstantAndAssignsId(): void
    {
        $entity = $this->storage->create([
            'title' => 'First Article',
            'bundle' => 'blog',
        ]);

        $result = $this->storage->save($entity);

        $this->assertSame(EntityConstants::SAVED_NEW, $result);
        $this->assertNotNull($entity->id());
        $this->assertIsInt($entity->id());
        $this->assertFalse($entity->isNew(), 'Entity should no longer be new after save');
    }

    public function testSaveMultipleEntitiesAssignsSequentialIds(): void
    {
        $entities = [];
        for ($i = 0; $i < 5; $i++) {
            $entity = $this->storage->create([
                'title' => "Article $i",
                'bundle' => 'blog',
            ]);
            $this->storage->save($entity);
            $entities[] = $entity;
        }

        // IDs should be sequential starting at 1.
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($i + 1, $entities[$i]->id());
        }
    }

    // ---- LOAD tests ----

    public function testLoadReturnsEntityWithAllValues(): void
    {
        $entity = $this->storage->create([
            'title' => 'Load Test',
            'bundle' => 'page',
            'body' => 'Article body text',
            'status' => 0,
        ]);
        $this->storage->save($entity);
        $id = $entity->id();
        $uuid = $entity->uuid();

        $loaded = $this->storage->load($id);

        $this->assertNotNull($loaded);
        $this->assertInstanceOf(TestArticleEntity::class, $loaded);
        $this->assertSame($id, $loaded->id());
        $this->assertSame($uuid, $loaded->uuid());
        $this->assertSame('Load Test', $loaded->label());
        $this->assertSame('page', $loaded->bundle());
        $this->assertSame('Article body text', $loaded->get('body'));
        $this->assertFalse($loaded->isNew());
    }

    public function testLoadReturnsNullForNonexistentId(): void
    {
        $this->assertNull($this->storage->load(999999));
    }

    public function testLoadMultipleReturnsKeyedByEntityId(): void
    {
        $e1 = $this->storage->create(['title' => 'E1', 'bundle' => 'a']);
        $e2 = $this->storage->create(['title' => 'E2', 'bundle' => 'b']);
        $e3 = $this->storage->create(['title' => 'E3', 'bundle' => 'c']);
        $this->storage->save($e1);
        $this->storage->save($e2);
        $this->storage->save($e3);

        $loaded = $this->storage->loadMultiple([$e1->id(), $e3->id()]);

        $this->assertCount(2, $loaded);
        $this->assertArrayHasKey($e1->id(), $loaded);
        $this->assertArrayHasKey($e3->id(), $loaded);
        $this->assertSame('E1', $loaded[$e1->id()]->label());
        $this->assertSame('E3', $loaded[$e3->id()]->label());
    }

    public function testLoadMultipleEmptyReturnsEmpty(): void
    {
        $this->assertSame([], $this->storage->loadMultiple([]));
    }

    // ---- UPDATE tests ----

    public function testUpdateEntityPersistsChanges(): void
    {
        $entity = $this->storage->create([
            'title' => 'Original Title',
            'bundle' => 'blog',
            'body' => 'Original body',
        ]);
        $this->storage->save($entity);
        $id = $entity->id();

        // Update fields.
        $entity->set('title', 'Updated Title');
        $entity->set('body', 'Updated body content');
        $result = $this->storage->save($entity);

        $this->assertSame(EntityConstants::SAVED_UPDATED, $result);

        // Reload and verify persistence.
        $reloaded = $this->storage->load($id);
        $this->assertSame('Updated Title', $reloaded->label());
        $this->assertSame('Updated body content', $reloaded->get('body'));
    }

    public function testUpdatePreservesUuid(): void
    {
        $entity = $this->storage->create([
            'title' => 'UUID Preserve',
            'bundle' => 'blog',
        ]);
        $this->storage->save($entity);
        $originalUuid = $entity->uuid();

        $entity->set('title', 'UUID Still There');
        $this->storage->save($entity);

        $loaded = $this->storage->load($entity->id());
        $this->assertSame($originalUuid, $loaded->uuid());
    }

    // ---- DELETE tests ----

    public function testDeleteRemovesEntitiesFromDatabase(): void
    {
        $e1 = $this->storage->create(['title' => 'Delete 1', 'bundle' => 'a']);
        $e2 = $this->storage->create(['title' => 'Delete 2', 'bundle' => 'a']);
        $e3 = $this->storage->create(['title' => 'Keep', 'bundle' => 'a']);
        $this->storage->save($e1);
        $this->storage->save($e2);
        $this->storage->save($e3);

        $this->storage->delete([$e1, $e2]);

        $this->assertNull($this->storage->load($e1->id()));
        $this->assertNull($this->storage->load($e2->id()));
        $this->assertNotNull($this->storage->load($e3->id()));
    }

    public function testDeleteEmptyArrayDoesNotThrow(): void
    {
        $this->storage->delete([]);
        $this->assertTrue(true, 'No exception thrown');
    }

    // ---- EVENT tests ----

    public function testSaveDispatchesPreAndPostSaveEvents(): void
    {
        $firedEvents = [];

        $this->eventDispatcher->addListener(
            EntityEvents::PRE_SAVE->value,
            function (EntityEvent $event) use (&$firedEvents) {
                $firedEvents[] = 'pre_save:' . $event->entity->label();
            },
        );
        $this->eventDispatcher->addListener(
            EntityEvents::POST_SAVE->value,
            function (EntityEvent $event) use (&$firedEvents) {
                $firedEvents[] = 'post_save:' . $event->entity->label();
            },
        );

        $entity = $this->storage->create(['title' => 'Events!', 'bundle' => 'blog']);
        $this->storage->save($entity);

        $this->assertSame(['pre_save:Events!', 'post_save:Events!'], $firedEvents);
    }

    public function testDeleteDispatchesPreAndPostDeleteEvents(): void
    {
        $firedEvents = [];

        $this->eventDispatcher->addListener(
            EntityEvents::PRE_DELETE->value,
            function (EntityEvent $event) use (&$firedEvents) {
                $firedEvents[] = 'pre_delete:' . $event->entity->label();
            },
        );
        $this->eventDispatcher->addListener(
            EntityEvents::POST_DELETE->value,
            function (EntityEvent $event) use (&$firedEvents) {
                $firedEvents[] = 'post_delete:' . $event->entity->label();
            },
        );

        $entity = $this->storage->create(['title' => 'Bye!', 'bundle' => 'blog']);
        $this->storage->save($entity);

        // Clear save events and delete.
        $firedEvents = [];
        $this->storage->delete([$entity]);

        $this->assertSame(['pre_delete:Bye!', 'post_delete:Bye!'], $firedEvents);
    }

    // ---- QUERY tests ----

    public function testQueryWithCondition(): void
    {
        $this->createSampleEntities();

        $ids = $this->storage->getQuery()
            ->condition('bundle', 'blog')
            ->execute();

        $this->assertCount(2, $ids);
    }

    public function testQueryWithSort(): void
    {
        $this->createSampleEntities();

        $ids = $this->storage->getQuery()
            ->sort('title', 'DESC')
            ->execute();

        // Load each to verify ordering.
        $titles = [];
        foreach ($ids as $id) {
            $entity = $this->storage->load($id);
            $titles[] = $entity->label();
        }

        $expected = $titles;
        usort($expected, fn($a, $b) => strcmp($b, $a));
        $this->assertSame($expected, $titles, 'Results should be sorted by title descending');
    }

    public function testQueryWithRange(): void
    {
        $this->createSampleEntities();

        $ids = $this->storage->getQuery()
            ->sort('id', 'ASC')
            ->range(0, 2)
            ->execute();

        $this->assertCount(2, $ids);
    }

    public function testQueryCount(): void
    {
        $this->createSampleEntities();

        $result = $this->storage->getQuery()
            ->condition('bundle', 'blog')
            ->count()
            ->execute();

        $this->assertSame([2], $result);
    }

    public function testQueryCountAll(): void
    {
        $this->createSampleEntities();

        $result = $this->storage->getQuery()
            ->count()
            ->execute();

        $this->assertSame([3], $result);
    }

    public function testQueryWithMultipleConditions(): void
    {
        $this->createSampleEntities();

        $ids = $this->storage->getQuery()
            ->condition('bundle', 'blog')
            ->condition('title', 'Blog Post 1')
            ->execute();

        $this->assertCount(1, $ids);
        $entity = $this->storage->load($ids[0]);
        $this->assertSame('Blog Post 1', $entity->label());
    }

    // ---- DATA ROUND-TRIP test ----

    public function testFullDataRoundTrip(): void
    {
        $originalValues = [
            'title' => 'Round-trip Article',
            'bundle' => 'blog',
            'body' => 'This is the body text.',
            'status' => 1,
            'langcode' => 'fr',
        ];

        $entity = $this->storage->create($originalValues);
        $this->storage->save($entity);
        $id = $entity->id();

        // Load from a fresh storage to avoid any in-memory caching.
        $freshStorage = new SqlEntityStorage(
            $this->entityType,
            $this->database,
            $this->eventDispatcher,
        );
        $loaded = $freshStorage->load($id);

        $this->assertNotNull($loaded);
        $this->assertSame('Round-trip Article', $loaded->label());
        $this->assertSame('blog', $loaded->bundle());
        $this->assertSame('This is the body text.', $loaded->get('body'));
        $this->assertSame('fr', $loaded->language());
    }

    // ---- SCHEMA HANDLER tests ----

    public function testSchemaHandlerEnsureTableIsIdempotent(): void
    {
        $schemaHandler = new SqlSchemaHandler($this->entityType, $this->database);

        // Table was already created in setUp; calling again should not throw.
        $schemaHandler->ensureTable();

        $this->assertTrue(
            $this->database->schema()->tableExists('article'),
            'Table should exist after ensureTable()',
        );
    }

    public function testSchemaHandlerCreatesCorrectTableName(): void
    {
        $schemaHandler = new SqlSchemaHandler($this->entityType, $this->database);
        $this->assertSame('article', $schemaHandler->getTableName());
    }

    /**
     * Creates 3 sample entities: 2 blog, 1 page.
     */
    private function createSampleEntities(): void
    {
        $entities = [
            ['title' => 'Blog Post 1', 'bundle' => 'blog'],
            ['title' => 'Blog Post 2', 'bundle' => 'blog'],
            ['title' => 'About Page', 'bundle' => 'page'],
        ];

        foreach ($entities as $values) {
            $entity = $this->storage->create($values);
            $this->storage->save($entity);
        }
    }
}

/**
 * Concrete entity class used in CRUD integration tests.
 */
class TestArticleEntity extends ContentEntityBase
{
    public function __construct(
        array $values = [],
        string $entityTypeId = 'article',
        array $entityKeys = [],
        array $fieldDefinitions = [],
    ) {
        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}

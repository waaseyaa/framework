<?php

declare(strict_types=1);

namespace Aurora\EntityStorage\Tests\Unit;

use Aurora\Database\PdoDatabase;
use Aurora\Entity\EntityConstants;
use Aurora\Entity\EntityType;
use Aurora\Entity\Event\EntityEvent;
use Aurora\Entity\Event\EntityEvents;
use Aurora\EntityStorage\SqlEntityStorage;
use Aurora\EntityStorage\SqlSchemaHandler;
use Aurora\EntityStorage\Tests\Fixtures\TestStorageEntity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class SqlEntityStorageTest extends TestCase
{
    private PdoDatabase $database;
    private EntityType $entityType;
    private EventDispatcher $eventDispatcher;
    private SqlEntityStorage $storage;

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
        $this->eventDispatcher = new EventDispatcher();

        // Create the table.
        $schemaHandler = new SqlSchemaHandler($this->entityType, $this->database);
        $schemaHandler->ensureTable();

        $this->storage = new SqlEntityStorage(
            $this->entityType,
            $this->database,
            $this->eventDispatcher,
        );
    }

    public function testCreateReturnsNewEntity(): void
    {
        $entity = $this->storage->create([
            'label' => 'Test Label',
            'bundle' => 'article',
        ]);

        $this->assertInstanceOf(TestStorageEntity::class, $entity);
        $this->assertTrue($entity->isNew());
        $this->assertSame('Test Label', $entity->label());
        $this->assertSame('article', $entity->bundle());
        $this->assertNull($entity->id());
        $this->assertNotEmpty($entity->uuid());
    }

    public function testSaveNewEntityInsertsAndReturnsId(): void
    {
        $entity = $this->storage->create([
            'label' => 'New Entity',
            'bundle' => 'article',
        ]);

        $result = $this->storage->save($entity);

        $this->assertSame(EntityConstants::SAVED_NEW, $result);
        $this->assertNotNull($entity->id());
        $this->assertIsInt($entity->id());
        $this->assertFalse($entity->isNew());
    }

    public function testSaveExistingEntityUpdates(): void
    {
        $entity = $this->storage->create([
            'label' => 'Original Label',
            'bundle' => 'article',
        ]);
        $this->storage->save($entity);
        $id = $entity->id();

        // Update the label.
        $entity->set('label', 'Updated Label');
        $result = $this->storage->save($entity);

        $this->assertSame(EntityConstants::SAVED_UPDATED, $result);

        // Reload and verify.
        $loaded = $this->storage->load($id);
        $this->assertNotNull($loaded);
        $this->assertSame('Updated Label', $loaded->label());
    }

    public function testLoadReturnsEntity(): void
    {
        $entity = $this->storage->create([
            'label' => 'Load Me',
            'bundle' => 'page',
        ]);
        $this->storage->save($entity);
        $id = $entity->id();

        $loaded = $this->storage->load($id);

        $this->assertNotNull($loaded);
        $this->assertInstanceOf(TestStorageEntity::class, $loaded);
        $this->assertSame($id, $loaded->id());
        $this->assertSame('Load Me', $loaded->label());
        $this->assertSame('page', $loaded->bundle());
        $this->assertFalse($loaded->isNew());
    }

    public function testLoadReturnsNullForMissing(): void
    {
        $loaded = $this->storage->load(9999);

        $this->assertNull($loaded);
    }

    public function testLoadMultiple(): void
    {
        $entity1 = $this->storage->create(['label' => 'Entity 1', 'bundle' => 'article']);
        $entity2 = $this->storage->create(['label' => 'Entity 2', 'bundle' => 'page']);
        $entity3 = $this->storage->create(['label' => 'Entity 3', 'bundle' => 'article']);

        $this->storage->save($entity1);
        $this->storage->save($entity2);
        $this->storage->save($entity3);

        $entities = $this->storage->loadMultiple([$entity1->id(), $entity3->id()]);

        $this->assertCount(2, $entities);
        $this->assertArrayHasKey($entity1->id(), $entities);
        $this->assertArrayHasKey($entity3->id(), $entities);
        $this->assertSame('Entity 1', $entities[$entity1->id()]->label());
        $this->assertSame('Entity 3', $entities[$entity3->id()]->label());
    }

    public function testLoadMultipleWithEmptyIds(): void
    {
        $entities = $this->storage->loadMultiple([]);

        $this->assertSame([], $entities);
    }

    public function testDeleteRemovesEntities(): void
    {
        $entity1 = $this->storage->create(['label' => 'Delete Me 1', 'bundle' => 'article']);
        $entity2 = $this->storage->create(['label' => 'Delete Me 2', 'bundle' => 'article']);
        $entity3 = $this->storage->create(['label' => 'Keep Me', 'bundle' => 'article']);

        $this->storage->save($entity1);
        $this->storage->save($entity2);
        $this->storage->save($entity3);

        $this->storage->delete([$entity1, $entity2]);

        $this->assertNull($this->storage->load($entity1->id()));
        $this->assertNull($this->storage->load($entity2->id()));
        $this->assertNotNull($this->storage->load($entity3->id()));
    }

    public function testDeleteWithEmptyArray(): void
    {
        // Should not throw.
        $this->storage->delete([]);
        $this->assertTrue(true);
    }

    public function testSaveDispatchesEvents(): void
    {
        $events = [];

        $this->eventDispatcher->addListener(
            EntityEvents::PRE_SAVE->value,
            function (EntityEvent $event) use (&$events) {
                $events[] = 'pre_save';
            },
        );

        $this->eventDispatcher->addListener(
            EntityEvents::POST_SAVE->value,
            function (EntityEvent $event) use (&$events) {
                $events[] = 'post_save';
            },
        );

        $entity = $this->storage->create(['label' => 'Events Test', 'bundle' => 'article']);
        $this->storage->save($entity);

        $this->assertSame(['pre_save', 'post_save'], $events);
    }

    public function testDeleteDispatchesEvents(): void
    {
        $events = [];

        $this->eventDispatcher->addListener(
            EntityEvents::PRE_DELETE->value,
            function (EntityEvent $event) use (&$events) {
                $events[] = 'pre_delete:' . $event->entity->label();
            },
        );

        $this->eventDispatcher->addListener(
            EntityEvents::POST_DELETE->value,
            function (EntityEvent $event) use (&$events) {
                $events[] = 'post_delete:' . $event->entity->label();
            },
        );

        $entity1 = $this->storage->create(['label' => 'Entity A', 'bundle' => 'article']);
        $entity2 = $this->storage->create(['label' => 'Entity B', 'bundle' => 'article']);
        $this->storage->save($entity1);
        $this->storage->save($entity2);

        // Clear save events.
        $events = [];

        $this->storage->delete([$entity1, $entity2]);

        $this->assertSame([
            'pre_delete:Entity A',
            'pre_delete:Entity B',
            'post_delete:Entity A',
            'post_delete:Entity B',
        ], $events);
    }

    public function testGetEntityTypeId(): void
    {
        $this->assertSame('test_entity', $this->storage->getEntityTypeId());
    }

    public function testGetQueryReturnsQueryInstance(): void
    {
        $query = $this->storage->getQuery();

        $this->assertInstanceOf(\Aurora\Entity\Storage\EntityQueryInterface::class, $query);
    }

    public function testEntityPreservesUuidAfterSaveAndLoad(): void
    {
        $entity = $this->storage->create([
            'label' => 'UUID Test',
            'bundle' => 'article',
        ]);
        $originalUuid = $entity->uuid();

        $this->storage->save($entity);
        $loaded = $this->storage->load($entity->id());

        $this->assertSame($originalUuid, $loaded->uuid());
    }
}

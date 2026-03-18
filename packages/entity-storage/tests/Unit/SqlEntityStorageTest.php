<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityConstants;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestConfigEntity;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class SqlEntityStorageTest extends TestCase
{
    private DBALDatabase $database;
    private EntityType $entityType;
    private EventDispatcher $eventDispatcher;
    private SqlEntityStorage $storage;

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
            fieldDefinitions: [
                'created' => ['type' => 'timestamp'],
                'changed' => ['type' => 'timestamp'],
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

        $this->assertInstanceOf(\Waaseyaa\Entity\Storage\EntityQueryInterface::class, $query);
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

    public function testSaveNewEntitySetsCreatedTimestamp(): void
    {
        $before = time();

        $entity = $this->storage->create([
            'label' => 'Timestamp Test',
            'bundle' => 'page',
            'created' => 0,
            'changed' => 0,
        ]);
        $entity->enforceIsNew();
        $this->storage->save($entity);

        $loaded = $this->storage->load($entity->id());
        $created = (int) $loaded->get('created');
        $changed = (int) $loaded->get('changed');

        $this->assertGreaterThanOrEqual($before, $created);
        $this->assertLessThanOrEqual(time(), $created);
        $this->assertGreaterThanOrEqual($before, $changed);
    }

    public function testSaveExistingEntityUpdatesChangedTimestamp(): void
    {
        $entity = $this->storage->create([
            'label' => 'Update Test',
            'bundle' => 'page',
            'created' => 1000,
            'changed' => 1000,
        ]);
        $entity->enforceIsNew();
        $this->storage->save($entity);

        // Reload and save again.
        $loaded = $this->storage->load($entity->id());
        $loaded->set('label', 'Updated');
        $before = time();
        $this->storage->save($loaded);

        $reloaded = $this->storage->load($entity->id());

        // Created should NOT change on update.
        $this->assertSame(1000, (int) $reloaded->get('created'));
        // Changed should be updated.
        $this->assertGreaterThanOrEqual($before, (int) $reloaded->get('changed'));
    }

    public function testSaveNewEntityPreservesExplicitCreatedTimestamp(): void
    {
        $entity = $this->storage->create([
            'label' => 'Explicit Created',
            'bundle' => 'page',
            'created' => 1700000000,
            'changed' => 0,
        ]);
        $entity->enforceIsNew();
        $this->storage->save($entity);

        $loaded = $this->storage->load($entity->id());

        // Explicit non-zero created should be preserved.
        $this->assertSame(1700000000, (int) $loaded->get('created'));
    }

    public function testCreateEnforcesIsNew(): void
    {
        $entity = $this->storage->create([
            'label' => 'New Entity',
            'bundle' => 'article',
        ]);

        $this->assertTrue($entity->isNew());
    }

    public function testConfigEntityCreateSaveAndLoad(): void
    {
        $configStorage = $this->createConfigStorage();

        // Create and save a config entity with a string ID.
        $entity = $configStorage->create([
            'type' => 'article',
            'name' => 'Article',
            'bundle' => '',
        ]);

        $this->assertTrue($entity->isNew());
        $this->assertSame('article', $entity->id());

        $result = $configStorage->save($entity);
        $this->assertSame(EntityConstants::SAVED_NEW, $result);
        $this->assertFalse($entity->isNew());
        // String ID should be preserved, not cast to int.
        $this->assertSame('article', $entity->id());

        // Load and verify.
        $loaded = $configStorage->load('article');
        $this->assertNotNull($loaded);
        $this->assertSame('article', $loaded->id());
        $this->assertSame('Article', $loaded->label());
        $this->assertFalse($loaded->isNew());
    }

    public function testConfigEntityUpdate(): void
    {
        $configStorage = $this->createConfigStorage();

        // Create and save.
        $entity = $configStorage->create([
            'type' => 'page',
            'name' => 'Basic Page',
            'bundle' => '',
        ]);
        $configStorage->save($entity);

        // Load, update, re-save.
        $loaded = $configStorage->load('page');
        $loaded->set('name', 'Updated Page');
        $result = $configStorage->save($loaded);

        $this->assertSame(EntityConstants::SAVED_UPDATED, $result);

        // Reload and verify.
        $reloaded = $configStorage->load('page');
        $this->assertSame('Updated Page', $reloaded->label());
    }

    public function testConfigEntityDeleteWithStringIds(): void
    {
        $configStorage = $this->createConfigStorage();

        $entity1 = $configStorage->create(['type' => 'article', 'name' => 'Article', 'bundle' => '']);
        $entity2 = $configStorage->create(['type' => 'page', 'name' => 'Page', 'bundle' => '']);
        $entity3 = $configStorage->create(['type' => 'blog', 'name' => 'Blog', 'bundle' => '']);

        $configStorage->save($entity1);
        $configStorage->save($entity2);
        $configStorage->save($entity3);

        $configStorage->delete([$entity1, $entity2]);

        $this->assertNull($configStorage->load('article'));
        $this->assertNull($configStorage->load('page'));
        $this->assertNotNull($configStorage->load('blog'));
    }

    public function testConfigEntityLoadMultipleWithStringIds(): void
    {
        $configStorage = $this->createConfigStorage();

        $entity1 = $configStorage->create(['type' => 'article', 'name' => 'Article', 'bundle' => '']);
        $entity2 = $configStorage->create(['type' => 'page', 'name' => 'Page', 'bundle' => '']);
        $entity3 = $configStorage->create(['type' => 'blog', 'name' => 'Blog', 'bundle' => '']);

        $configStorage->save($entity1);
        $configStorage->save($entity2);
        $configStorage->save($entity3);

        $entities = $configStorage->loadMultiple(['article', 'blog']);

        $this->assertCount(2, $entities);
        $this->assertArrayHasKey('article', $entities);
        $this->assertArrayHasKey('blog', $entities);
        $this->assertSame('Article', $entities['article']->label());
        $this->assertSame('Blog', $entities['blog']->label());
    }

    public function testContentEntityWithPresetIntegerId(): void
    {
        $entity = $this->storage->create([
            'id' => 42,
            'label' => 'Pre-set ID',
            'bundle' => 'article',
        ]);
        $entity->enforceIsNew();

        $result = $this->storage->save($entity);

        $this->assertSame(EntityConstants::SAVED_NEW, $result);
        $this->assertSame(42, $entity->id());
        $this->assertFalse($entity->isNew());

        // Reload and verify.
        $loaded = $this->storage->load(42);
        $this->assertNotNull($loaded);
        $this->assertSame(42, $loaded->id());
        $this->assertSame('Pre-set ID', $loaded->label());
    }

    public function testConfigEntitySaveRejectsEmptyId(): void
    {
        $configStorage = $this->createConfigStorage();

        $entity = $configStorage->create(['type' => '', 'name' => 'No ID', 'bundle' => '']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a non-empty string ID');
        $configStorage->save($entity);
    }

    private function createConfigStorage(): SqlEntityStorage
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

        $schemaHandler = new SqlSchemaHandler($configType, $this->database);
        $schemaHandler->ensureTable();

        return new SqlEntityStorage(
            $configType,
            $this->database,
            $this->eventDispatcher,
        );
    }
}

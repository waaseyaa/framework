<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityConstants;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\LifecycleTrackingEntity;
use Waaseyaa\EntityStorage\Tests\Fixtures\SpyEntityEventFactory;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(EntityRepository::class)]
final class EntityRepositoryTest extends TestCase
{
    private InMemoryStorageDriver $driver;
    private EntityType $entityType;
    private EventDispatcher $eventDispatcher;
    private EntityRepository $repository;

    protected function setUp(): void
    {
        $this->driver = new InMemoryStorageDriver();
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
        $this->repository = new EntityRepository(
            $this->entityType,
            $this->driver,
            $this->eventDispatcher,
        );
    }

    #[Test]
    public function saveNewEntityReturnsNewConstant(): void
    {
        $entity = new TestStorageEntity(
            values: ['id' => '1', 'label' => 'Hello', 'bundle' => 'article', 'langcode' => 'en'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );
        $entity->enforceIsNew(true);

        $result = $this->repository->save($entity);

        $this->assertSame(EntityConstants::SAVED_NEW, $result);
        $this->assertFalse($entity->isNew());
    }

    #[Test]
    public function saveExistingEntityReturnsUpdatedConstant(): void
    {
        $entity = new TestStorageEntity(
            values: ['id' => '1', 'label' => 'Hello', 'bundle' => 'article', 'langcode' => 'en'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );

        // Entity has id set and enforceIsNew is false, so it's not new.
        $result = $this->repository->save($entity);

        $this->assertSame(EntityConstants::SAVED_UPDATED, $result);
    }

    #[Test]
    public function findReturnsEntity(): void
    {
        $entity = new TestStorageEntity(
            values: ['id' => '1', 'label' => 'Hello', 'bundle' => 'article', 'langcode' => 'en'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );
        $entity->enforceIsNew(true);
        $this->repository->save($entity);

        $found = $this->repository->find('1');

        $this->assertNotNull($found);
        $this->assertSame('Hello', $found->label());
        $this->assertFalse($found->isNew());
    }

    #[Test]
    public function findReturnsNullForMissing(): void
    {
        $this->assertNull($this->repository->find('999'));
    }

    #[Test]
    public function findByReturnMatchingEntities(): void
    {
        $this->driver->write('test_entity', '1', [
            'id' => '1',
            'label' => 'Article A',
            'bundle' => 'article',
            'langcode' => 'en',
        ]);
        $this->driver->write('test_entity', '2', [
            'id' => '2',
            'label' => 'Page B',
            'bundle' => 'page',
            'langcode' => 'en',
        ]);
        $this->driver->write('test_entity', '3', [
            'id' => '3',
            'label' => 'Article C',
            'bundle' => 'article',
            'langcode' => 'en',
        ]);

        $entities = $this->repository->findBy(['bundle' => 'article']);

        $this->assertCount(2, $entities);
        $labels = array_map(fn($e) => $e->label(), $entities);
        $this->assertContains('Article A', $labels);
        $this->assertContains('Article C', $labels);
    }

    #[Test]
    public function findByWithOrderAndLimit(): void
    {
        $this->driver->write('test_entity', '1', [
            'id' => '1',
            'label' => 'Bravo',
            'bundle' => 'article',
            'langcode' => 'en',
        ]);
        $this->driver->write('test_entity', '2', [
            'id' => '2',
            'label' => 'Alpha',
            'bundle' => 'article',
            'langcode' => 'en',
        ]);
        $this->driver->write('test_entity', '3', [
            'id' => '3',
            'label' => 'Charlie',
            'bundle' => 'article',
            'langcode' => 'en',
        ]);

        $entities = $this->repository->findBy([], ['label' => 'ASC'], 2);

        $this->assertCount(2, $entities);
        $this->assertSame('Alpha', $entities[0]->label());
        $this->assertSame('Bravo', $entities[1]->label());
    }

    #[Test]
    public function deleteRemovesEntity(): void
    {
        $entity = new TestStorageEntity(
            values: ['id' => '1', 'label' => 'Delete Me', 'bundle' => 'article', 'langcode' => 'en'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );
        $entity->enforceIsNew(true);
        $this->repository->save($entity);

        $this->repository->delete($entity);

        $this->assertNull($this->repository->find('1'));
    }

    #[Test]
    public function existsMethod(): void
    {
        $this->assertFalse($this->repository->exists('1'));

        $entity = new TestStorageEntity(
            values: ['id' => '1', 'label' => 'Exists', 'bundle' => 'article', 'langcode' => 'en'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );
        $entity->enforceIsNew(true);
        $this->repository->save($entity);

        $this->assertTrue($this->repository->exists('1'));
    }

    #[Test]
    public function countEntities(): void
    {
        $this->assertSame(0, $this->repository->count());

        $this->driver->write('test_entity', '1', ['id' => '1', 'bundle' => 'article', 'label' => 'A', 'langcode' => 'en']);
        $this->driver->write('test_entity', '2', ['id' => '2', 'bundle' => 'page', 'label' => 'B', 'langcode' => 'en']);

        $this->assertSame(2, $this->repository->count());
        $this->assertSame(1, $this->repository->count(['bundle' => 'article']));
    }

    #[Test]
    public function saveDispatchesPreAndPostSaveEvents(): void
    {
        $events = [];

        $this->eventDispatcher->addListener(
            EntityEvents::PRE_SAVE->value,
            function (EntityEvent $event) use (&$events) {
                $events[] = 'pre_save:' . $event->entity->label();
            },
        );

        $this->eventDispatcher->addListener(
            EntityEvents::POST_SAVE->value,
            function (EntityEvent $event) use (&$events) {
                $events[] = 'post_save:' . $event->entity->label();
            },
        );

        $entity = new TestStorageEntity(
            values: ['id' => '1', 'label' => 'Events', 'bundle' => 'article', 'langcode' => 'en'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );
        $this->repository->save($entity);

        $this->assertSame(['pre_save:Events', 'post_save:Events'], $events);
    }

    #[Test]
    public function deleteDispatchesPreAndPostDeleteEvents(): void
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

        $entity = new TestStorageEntity(
            values: ['id' => '1', 'label' => 'Bye', 'bundle' => 'article', 'langcode' => 'en'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );
        $entity->enforceIsNew(true);
        $this->repository->save($entity);

        $events = [];
        $this->repository->delete($entity);

        $this->assertSame(['pre_delete:Bye', 'post_delete:Bye'], $events);
    }

    #[Test]
    public function findWithLanguageFallback(): void
    {
        // Store base entity.
        $this->driver->write('test_entity', '1', [
            'id' => '1',
            'label' => 'Hello',
            'bundle' => 'article',
            'langcode' => 'en',
        ]);

        // Store English translation.
        $this->driver->writeTranslation('test_entity', '1', 'en', [
            'label' => 'Hello',
            'langcode' => 'en',
        ]);

        // Store French translation.
        $this->driver->writeTranslation('test_entity', '1', 'fr', [
            'label' => 'Bonjour',
            'langcode' => 'fr',
        ]);

        // Request German with fallback (should fall through to English).
        $this->repository->setFallbackChain(['en']);
        $entity = $this->repository->find('1', 'de', true);

        $this->assertNotNull($entity);
        // Should get English fallback.
        $this->assertSame('Hello', $entity->label());
    }

    #[Test]
    public function findWithSpecificLanguage(): void
    {
        $this->driver->write('test_entity', '1', [
            'id' => '1',
            'label' => 'Hello',
            'bundle' => 'article',
            'langcode' => 'en',
        ]);

        $this->driver->writeTranslation('test_entity', '1', 'fr', [
            'label' => 'Bonjour',
            'langcode' => 'fr',
        ]);

        $entity = $this->repository->find('1', 'fr');

        $this->assertNotNull($entity);
        $this->assertSame('Bonjour', $entity->label());
    }

    #[Test]
    public function saveUsesInjectedEventFactory(): void
    {
        $factory = new SpyEntityEventFactory();
        $repository = new EntityRepository(
            $this->entityType,
            $this->driver,
            $this->eventDispatcher,
            eventFactory: $factory,
        );

        $entity = new TestStorageEntity(
            values: ['id' => '1', 'label' => 'Hello', 'bundle' => 'article', 'langcode' => 'en'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );
        $entity->enforceIsNew(true);

        $repository->save($entity);
        $this->assertGreaterThan(0, $factory->callCount, 'Custom event factory should be called during save');
    }

    // -----------------------------------------------------------------------
    // Batch operations
    // -----------------------------------------------------------------------

    private function createSqlRepository(): EntityRepository
    {
        $db = DBALDatabase::createSqlite();
        $driver = new SqlStorageDriver(new SingleConnectionResolver($db));
        (new SqlSchemaHandler($this->entityType, $db))->ensureTable();

        return new EntityRepository(
            $this->entityType,
            $driver,
            $this->eventDispatcher,
            database: $db,
        );
    }

    private function newEntity(string $id, string $label = 'Test'): TestStorageEntity
    {
        $entity = new TestStorageEntity(
            values: ['id' => $id, 'label' => $label, 'bundle' => 'article', 'langcode' => 'en'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );
        $entity->enforceIsNew(true);

        return $entity;
    }

    #[Test]
    public function saveManyReturnsResultsPerEntity(): void
    {
        $repository = $this->createSqlRepository();
        $results = $repository->saveMany([$this->newEntity('1', 'First'), $this->newEntity('2', 'Second')]);

        $this->assertCount(2, $results);
        $this->assertSame(EntityConstants::SAVED_NEW, $results[0]);
        $this->assertSame(EntityConstants::SAVED_NEW, $results[1]);
    }

    #[Test]
    public function saveManyWithEmptyArrayReturnsEmpty(): void
    {
        $repository = $this->createSqlRepository();
        $this->assertSame([], $repository->saveMany([]));
    }

    #[Test]
    public function saveManyDispatchesEventsAfterCommit(): void
    {
        $repository = $this->createSqlRepository();

        $events = [];
        $this->eventDispatcher->addListener(EntityEvents::PRE_SAVE->value, function () use (&$events) {
            $events[] = 'pre_save';
        });
        $this->eventDispatcher->addListener(EntityEvents::POST_SAVE->value, function () use (&$events) {
            $events[] = 'post_save';
        });

        $repository->saveMany([$this->newEntity('1')]);

        $this->assertSame(['pre_save', 'post_save'], $events);
    }

    #[Test]
    public function saveManyThrowsWithoutDatabase(): void
    {
        $this->expectException(\LogicException::class);
        $this->repository->saveMany([$this->newEntity('1')]);
    }

    #[Test]
    public function deleteManyReturnsCount(): void
    {
        $repository = $this->createSqlRepository();
        $e1 = $this->newEntity('1', 'First');
        $e2 = $this->newEntity('2', 'Second');
        $repository->saveMany([$e1, $e2]);

        $count = $repository->deleteMany([$e1, $e2]);

        $this->assertSame(2, $count);
        $this->assertNull($repository->find('1'));
        $this->assertNull($repository->find('2'));
    }

    #[Test]
    public function deleteManyWithEmptyArrayReturnsZero(): void
    {
        $repository = $this->createSqlRepository();
        $this->assertSame(0, $repository->deleteMany([]));
    }

    #[Test]
    public function deleteManyThrowsWithoutDatabase(): void
    {
        $this->expectException(\LogicException::class);
        $this->repository->deleteMany([$this->newEntity('1')]);
    }

    // -----------------------------------------------------------------------
    // Lifecycle hooks
    // -----------------------------------------------------------------------

    #[Test]
    public function saveCallsLifecycleHooksInOrder(): void
    {
        $lifecycleType = new EntityType(
            id: 'test_entity',
            label: 'Test Entity',
            class: LifecycleTrackingEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );

        $db = DBALDatabase::createSqlite();
        $driver = new SqlStorageDriver(new SingleConnectionResolver($db));
        (new SqlSchemaHandler($lifecycleType, $db))->ensureTable();

        $repository = new EntityRepository(
            $lifecycleType,
            $driver,
            $this->eventDispatcher,
            database: $db,
        );

        $entity = new LifecycleTrackingEntity(
            values: ['id' => '1', 'label' => 'Test', 'bundle' => 'article', 'langcode' => 'en'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );
        $entity->enforceIsNew(true);

        $repository->save($entity);

        $this->assertSame(['preSave:new', 'postSave:new'], $entity->hookLog);
    }

    #[Test]
    public function deleteCallsLifecycleHooksInOrder(): void
    {
        $lifecycleType = new EntityType(
            id: 'test_entity',
            label: 'Test Entity',
            class: LifecycleTrackingEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );

        $db = DBALDatabase::createSqlite();
        $driver = new SqlStorageDriver(new SingleConnectionResolver($db));
        (new SqlSchemaHandler($lifecycleType, $db))->ensureTable();

        $repository = new EntityRepository(
            $lifecycleType,
            $driver,
            $this->eventDispatcher,
            database: $db,
        );

        $entity = new LifecycleTrackingEntity(
            values: ['id' => '1', 'label' => 'Test', 'bundle' => 'article', 'langcode' => 'en'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );
        $entity->enforceIsNew(true);
        $repository->save($entity);

        $entity->hookLog = []; // reset
        $repository->delete($entity);

        $this->assertSame(['preDelete', 'postDelete'], $entity->hookLog);
    }

    #[Test]
    public function updateCallsHooksWithIsNewFalse(): void
    {
        $lifecycleType = new EntityType(
            id: 'test_entity',
            label: 'Test Entity',
            class: LifecycleTrackingEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );

        $db = DBALDatabase::createSqlite();
        $driver = new SqlStorageDriver(new SingleConnectionResolver($db));
        (new SqlSchemaHandler($lifecycleType, $db))->ensureTable();

        $repository = new EntityRepository(
            $lifecycleType,
            $driver,
            $this->eventDispatcher,
            database: $db,
        );

        $entity = new LifecycleTrackingEntity(
            values: ['id' => '1', 'label' => 'Test', 'bundle' => 'article', 'langcode' => 'en'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );
        $entity->enforceIsNew(true);
        $repository->save($entity);

        $entity->hookLog = []; // reset
        $entity->set('label', 'Updated');
        $repository->save($entity);

        $this->assertSame(['preSave:update', 'postSave:update'], $entity->hookLog);
    }

    // -----------------------------------------------------------------------
    // Pre-save validation
    // -----------------------------------------------------------------------

    #[Test]
    public function saveThrowsValidationExceptionOnFailure(): void
    {
        $constrainedType = new EntityType(
            id: 'test_entity',
            label: 'Test Entity',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
            constraints: [
                'label' => [new \Symfony\Component\Validator\Constraints\NotBlank()],
            ],
        );

        $validator = new \Waaseyaa\Entity\Validation\EntityValidator(
            \Symfony\Component\Validator\Validation::createValidator(),
        );

        $repository = new EntityRepository(
            $constrainedType,
            $this->driver,
            $this->eventDispatcher,
            validator: $validator,
        );

        $entity = new TestStorageEntity(
            values: ['id' => '1', 'label' => '', 'bundle' => 'article', 'langcode' => 'en'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );
        $entity->enforceIsNew(true);

        $this->expectException(\Waaseyaa\Entity\Validation\EntityValidationException::class);
        $repository->save($entity);
    }

    #[Test]
    public function saveSkipsValidationWhenDisabled(): void
    {
        $constrainedType = new EntityType(
            id: 'test_entity',
            label: 'Test Entity',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
            constraints: [
                'label' => [new \Symfony\Component\Validator\Constraints\NotBlank()],
            ],
        );

        $db = DBALDatabase::createSqlite();
        $driver = new SqlStorageDriver(new SingleConnectionResolver($db));
        (new SqlSchemaHandler($constrainedType, $db))->ensureTable();

        $validator = new \Waaseyaa\Entity\Validation\EntityValidator(
            \Symfony\Component\Validator\Validation::createValidator(),
        );

        $repository = new EntityRepository(
            $constrainedType,
            $driver,
            $this->eventDispatcher,
            database: $db,
            validator: $validator,
        );

        $entity = new TestStorageEntity(
            values: ['id' => '1', 'label' => '', 'bundle' => 'article', 'langcode' => 'en'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );
        $entity->enforceIsNew(true);

        $result = $repository->save($entity, validate: false);
        $this->assertSame(EntityConstants::SAVED_NEW, $result);
    }

    #[Test]
    public function savePassesWhenValidationSucceeds(): void
    {
        $constrainedType = new EntityType(
            id: 'test_entity',
            label: 'Test Entity',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
            constraints: [
                'label' => [new \Symfony\Component\Validator\Constraints\NotBlank()],
            ],
        );

        $db = DBALDatabase::createSqlite();
        $driver = new SqlStorageDriver(new SingleConnectionResolver($db));
        (new SqlSchemaHandler($constrainedType, $db))->ensureTable();

        $validator = new \Waaseyaa\Entity\Validation\EntityValidator(
            \Symfony\Component\Validator\Validation::createValidator(),
        );

        $repository = new EntityRepository(
            $constrainedType,
            $driver,
            $this->eventDispatcher,
            database: $db,
            validator: $validator,
        );

        $entity = new TestStorageEntity(
            values: ['id' => '1', 'label' => 'Valid', 'bundle' => 'article', 'langcode' => 'en'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );
        $entity->enforceIsNew(true);

        $result = $repository->save($entity);
        $this->assertSame(EntityConstants::SAVED_NEW, $result);
    }

    #[Test]
    public function saveNewEntityPassesNullOriginalEntityToEvents(): void
    {
        $events = [];

        $this->eventDispatcher->addListener(
            EntityEvents::PRE_SAVE->value,
            function (EntityEvent $event) use (&$events) {
                $events[] = ['event' => 'pre_save', 'originalEntity' => $event->originalEntity];
            },
        );

        $this->eventDispatcher->addListener(
            EntityEvents::POST_SAVE->value,
            function (EntityEvent $event) use (&$events) {
                $events[] = ['event' => 'post_save', 'originalEntity' => $event->originalEntity];
            },
        );

        $entity = new TestStorageEntity(
            values: ['id' => '1', 'label' => 'New', 'bundle' => 'article', 'langcode' => 'en'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );
        $entity->enforceIsNew(true);
        $this->repository->save($entity);

        $this->assertCount(2, $events);
        $this->assertNull($events[0]['originalEntity'], 'PRE_SAVE originalEntity should be null for new entities');
        $this->assertNull($events[1]['originalEntity'], 'POST_SAVE originalEntity should be null for new entities');
    }

    #[Test]
    public function saveExistingEntityPassesOriginalEntityToEvents(): void
    {
        $entity = new TestStorageEntity(
            values: ['id' => '1', 'label' => 'Original', 'bundle' => 'article', 'langcode' => 'en'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );
        $entity->enforceIsNew(true);
        $this->repository->save($entity);

        // Modify and save again
        $entity->set('label', 'Modified');

        $events = [];

        $this->eventDispatcher->addListener(
            EntityEvents::PRE_SAVE->value,
            function (EntityEvent $event) use (&$events) {
                $events[] = [
                    'event' => 'pre_save',
                    'label' => $event->entity->label(),
                    'originalLabel' => $event->originalEntity?->label(),
                ];
            },
        );

        $this->eventDispatcher->addListener(
            EntityEvents::POST_SAVE->value,
            function (EntityEvent $event) use (&$events) {
                $events[] = [
                    'event' => 'post_save',
                    'label' => $event->entity->label(),
                    'originalLabel' => $event->originalEntity?->label(),
                ];
            },
        );

        $this->repository->save($entity);

        $this->assertCount(2, $events);
        $this->assertSame('Modified', $events[0]['label']);
        $this->assertSame('Original', $events[0]['originalLabel'], 'PRE_SAVE should receive DB state as originalEntity');
        $this->assertSame('Modified', $events[1]['label']);
        $this->assertSame('Original', $events[1]['originalLabel'], 'POST_SAVE should receive DB state as originalEntity');
    }

    #[Test]
    public function deletePassesEntityAsOriginalEntityToEvents(): void
    {
        $entity = new TestStorageEntity(
            values: ['id' => '1', 'label' => 'ToDelete', 'bundle' => 'article', 'langcode' => 'en'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );
        $entity->enforceIsNew(true);
        $this->repository->save($entity);

        $events = [];

        $this->eventDispatcher->addListener(
            EntityEvents::PRE_DELETE->value,
            function (EntityEvent $event) use (&$events) {
                $events[] = [
                    'event' => 'pre_delete',
                    'originalEntity' => $event->originalEntity,
                    'entity' => $event->entity,
                ];
            },
        );

        $this->eventDispatcher->addListener(
            EntityEvents::POST_DELETE->value,
            function (EntityEvent $event) use (&$events) {
                $events[] = [
                    'event' => 'post_delete',
                    'originalEntity' => $event->originalEntity,
                    'entity' => $event->entity,
                ];
            },
        );

        $this->repository->delete($entity);

        $this->assertCount(2, $events);
        $this->assertSame($events[0]['entity'], $events[0]['originalEntity'], 'PRE_DELETE originalEntity should be the entity itself');
        $this->assertSame($events[1]['entity'], $events[1]['originalEntity'], 'POST_DELETE originalEntity should be the entity itself');
    }
}

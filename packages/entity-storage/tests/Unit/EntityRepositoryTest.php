<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Validator\Constraints\Type;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityConstants;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\EntityStorageDriverInterface;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriverV2;
use Waaseyaa\EntityStorage\Driver\StorageBoundary;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\AttributeFirstEntities\RequiredLabelFixture;
use Waaseyaa\EntityStorage\Tests\Fixtures\CastPersistenceStringEnum;
use Waaseyaa\EntityStorage\Tests\Fixtures\HydratableFromStorageTestEntity;
use Waaseyaa\EntityStorage\Tests\Fixtures\LifecycleTrackingEntity;
use Waaseyaa\EntityStorage\Tests\Fixtures\SpyEntityEventFactory;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestEnumCastStorageEntity;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use Waaseyaa\EntityStorage\Tests\Fixtures\ThirdPartyOpaqueStorageDriver;
use Waaseyaa\Field\FieldDefinition;

require_once __DIR__ . '/../Fixtures/AttributeFirstEntities/RequiredLabelFixture.php';

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
        $boundary = new StorageBoundary();
        $this->repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
            $this->entityType,
            new InMemoryStorageDriverV2(
                $this->driver,
                $boundary->driverRowFactory(),
                $boundary->driverSnapshotReader(),
            ),
            $this->eventDispatcher,
            storageBoundary: $boundary,
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
    public function repository_hydrates_and_persists_through_the_opaque_v2_boundary(): void
    {
        $boundary = new StorageBoundary();
        $driver = new InMemoryStorageDriverV2(
            new InMemoryStorageDriver(),
            $boundary->driverRowFactory(),
            $boundary->driverSnapshotReader(),
        );
        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
            $this->entityType,
            $driver,
            $this->eventDispatcher,
            storageBoundary: $boundary,
        );
        $entity = new TestStorageEntity(
            values: ['id' => '9', 'label' => 'Opaque', 'bundle' => 'article', 'langcode' => 'en'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );
        $entity->enforceIsNew(true);

        self::assertSame(EntityConstants::SAVED_NEW, $repository->save($entity));
        self::assertSame('Opaque', $repository->find('9')?->label());
    }

    #[Test]
    public function activation_rejects_a_v1_driver_before_internal_values_reach_the_raw_adapter(): void
    {
        ContentEntityBase::setFieldRegistry(null);
        $driver = new InMemoryStorageDriver();
        $entityType = new EntityType(
            id: 'legacy_driver_observer',
            label: 'Legacy driver observer',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'label' => 'label'],
            _fieldDefinitions: [
                'label' => new FieldDefinition('label', 'string', read: FieldReadLevel::Public),
                'secret_marker' => new FieldDefinition('secret_marker', 'string', read: FieldReadLevel::Internal),
            ],
        );

        $rejected = false;
        try {
            $repository = new EntityRepository($entityType, $driver, new EventDispatcher());
            $entity = $repository->create([
                'id' => '7',
                'label' => 'Member',
                'secret_marker' => 'restricted-value',
            ]);
            $repository->save($entity);
        } catch (\TypeError) {
            $rejected = true;
        }

        self::assertArrayNotHasKey(
            'secret_marker',
            $driver->read('legacy_driver_observer', '7') ?? [],
            'The V1 raw-array adapter received an Internal-classified value.',
        );
        self::assertTrue($rejected, 'Activation accepted a V1 entity-storage driver.');
    }

    #[Test]
    public function activation_rejects_an_arbitrary_v1_driver_hidden_behind_the_public_v2_factory(): void
    {
        ContentEntityBase::setFieldRegistry(null);
        $driver = new class implements EntityStorageDriverInterface {
            /** @var array<string, mixed> */
            public array $written = [];

            public function read(string $entityType, string $id, ?string $langcode = null): ?array { return null; }
            public function readMultiple(string $entityType, array $ids, ?string $langcode = null): array { return []; }
            public function write(string $entityType, string $id, array $values): string { $this->written = $values; return $id; }
            public function remove(string $entityType, string $id): void {}
            public function exists(string $entityType, string $id): bool { return false; }
            public function count(string $entityType, array $criteria = []): int { return 0; }
            public function findBy(string $entityType, array $criteria = [], ?array $orderBy = null, ?int $limit = null): array { return []; }
            public function findTranslations(string $entityType, string $id, ?string $defaultLangcode = null): array { return []; }
        };
        $entityType = new EntityType(
            id: 'hidden_legacy_driver_observer',
            label: 'Hidden legacy driver observer',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'label' => 'label'],
            _fieldDefinitions: [
                'label' => new FieldDefinition('label', 'string', read: FieldReadLevel::Public),
                'secret_marker' => new FieldDefinition('secret_marker', 'string', read: FieldReadLevel::Internal),
            ],
        );

        $rejected = false;
        try {
            $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
                $entityType,
                $driver,
                new EventDispatcher(),
            );
            $entity = $repository->create([
                'id' => '7',
                'label' => 'Member',
                'secret_marker' => 'restricted-value',
            ]);
            $repository->save($entity);
        } catch (\TypeError) {
            $rejected = true;
        }

        self::assertArrayNotHasKey(
            'secret_marker',
            $driver->written,
            'The public V2 factory delivered an Internal-classified value to an arbitrary V1 driver.',
        );
        self::assertTrue($rejected, 'Activation accepted an arbitrary V1 driver through the public V2 factory.');
    }

    #[Test]
    public function third_party_v2_driver_never_exchanges_raw_rows_with_the_repository(): void
    {
        $boundary = new StorageBoundary();
        $driver = new ThirdPartyOpaqueStorageDriver(
            $boundary->driverRowFactory(),
            $boundary->driverSnapshotReader(),
        );
        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
            $this->entityType,
            $driver,
            $this->eventDispatcher,
            storageBoundary: $boundary,
        );
        $entity = $repository->create([
            'id' => '41',
            'label' => 'Extension row',
            'bundle' => 'article',
            'langcode' => 'en',
        ]);

        self::assertSame(EntityConstants::SAVED_NEW, $repository->save($entity));
        self::assertSame('Extension row', $repository->find('41')?->label());
        self::assertSame(['write', 'read'], array_values(array_unique($driver->operations)));
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
    public function findUsesSealedHydrationBeforeLegacyFromStorageForEntityBase(): void
    {
        $driver = new InMemoryStorageDriver();
        $entityType = new EntityType(
            id: 'hydratable_test_entity',
            label: 'Hydratable Test',
            class: HydratableFromStorageTestEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
        );
        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create($entityType, $driver, new EventDispatcher());
        $driver->write('hydratable_test_entity', '1', [
            'id' => '1',
            'label' => 'From row',
            'bundle' => 'article',
            'langcode' => 'en',
        ]);

        $found = $repository->find('1');

        $this->assertNotNull($found);
        $this->assertInstanceOf(HydratableFromStorageTestEntity::class, $found);
        $this->assertNull($found->get('_rehydrated_via_storage'));
        $this->assertNull($found->get('_context_type'));
        $this->assertSame('From row', $found->label());
    }

    #[Test]
    public function findManyPreservesOrderAndSkipsMissing(): void
    {
        $this->driver->write('test_entity', '1', [
            'id' => '1',
            'label' => 'First',
            'bundle' => 'article',
            'langcode' => 'en',
        ]);
        $this->driver->write('test_entity', '2', [
            'id' => '2',
            'label' => 'Second',
            'bundle' => 'article',
            'langcode' => 'en',
        ]);

        $entities = $this->repository->findMany(['2', '9', '1']);

        $this->assertCount(2, $entities);
        $this->assertSame('Second', $entities[0]->label());
        $this->assertSame('First', $entities[1]->label());
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
        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
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

    #[Test]
    public function preSaveOriginalEntityReflectsStoredRowNotInMemoryDuplicate(): void
    {
        $factory = new SpyEntityEventFactory();
        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
            $this->entityType,
            $this->driver,
            $this->eventDispatcher,
            eventFactory: $factory,
        );

        $this->driver->write('test_entity', '1', [
            'id' => '1',
            'uuid' => 'aaaaaaaa-bbbb-4ccc-dddd-eeeeeeeeeeee',
            'label' => 'Stored',
            'bundle' => 'article',
            'langcode' => 'en',
        ]);

        $entity = $repository->find('1');
        $this->assertNotNull($entity);
        $entity->set('label', 'ChangedInMemory');

        $inMemoryDuplicate = $entity->duplicate();
        $this->assertSame('ChangedInMemory', $inMemoryDuplicate->label());

        $repository->save($entity);

        $this->assertGreaterThanOrEqual(1, $factory->callCount);
        $first = $factory->calls[0];
        $this->assertSame($entity, $first['entity']);
        $this->assertNotNull($first['originalEntity']);
        $this->assertSame('Stored', $first['originalEntity']->label());
        $this->assertNotSame($inMemoryDuplicate, $first['originalEntity']);
    }

    // -----------------------------------------------------------------------
    // Batch operations
    // -----------------------------------------------------------------------

    private function createSqlRepository(): EntityRepository
    {
        $db = DBALDatabase::createSqlite();
        $driver = new SqlStorageDriver(new SingleConnectionResolver($db));
        new SqlSchemaHandler($this->entityType, $db)->ensureTable();

        return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
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

    /**
     * Renamed from saveManyDispatchesEventsAfterCommit (audit-remediation
     * batch 2026-07-02, WP2 review): its order assertion always held, but
     * the name pinned the old ALL-events-buffered semantics. The contract
     * is now split — PRE_SAVE fires immediately inside the batch
     * transaction (see saveManyDispatchesPreWriteEventsBeforeRowsAreWritten
     * for the pre-write pin), POST_SAVE stays buffered until after commit.
     */
    #[Test]
    public function saveManyDispatchesPreDuringAndPostAfterCommit(): void
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

    /**
     * PRE-write events (EntityEvents::PRE_SAVE + BeforeSaveEvent) must fire
     * IMMEDIATELY during saveMany — inside the batch transaction, BEFORE the
     * entity's row is written — not buffered until after commit.
     *
     * Contract rationale (audit-remediation batch 2026-07-02, WP2 review):
     * a PRE event announces intent; listeners that mutate the entity or
     * issue guarding DB writes (e.g. the attachment active-invariant
     * demote, the classification label resolver's $entity->set() calls)
     * only work if they run before the write. Post-commit buffering exists
     * so listeners never observe rolled-back work — but an immediate PRE
     * listener's DB writes JOIN the batch transaction and roll back with
     * it, which satisfies that goal without breaking the pre-write
     * contract. Buffering PRE events until after commit silently broke
     * both (a listener's entity mutations were never persisted in batches,
     * and BeforeSaveEvent's abort contract was unfulfillable).
     *
     * The listener here records the observed table row-count at dispatch
     * time: entity 1's PRE events must see 0 rows, entity 2's must see 1.
     */
    #[Test]
    public function saveManyDispatchesPreWriteEventsBeforeRowsAreWritten(): void
    {
        $db = DBALDatabase::createSqlite();
        $driver = new SqlStorageDriver(new SingleConnectionResolver($db));
        new SqlSchemaHandler($this->entityType, $db)->ensureTable();
        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $this->entityType,
            $driver,
            $this->eventDispatcher,
            database: $db,
        );

        $rowCount = function () use ($db): int {
            foreach ($db->query('SELECT COUNT(*) AS c FROM test_entity') as $row) {
                return (int) $row['c'];
            }

            return -1;
        };

        $observed = [];
        $this->eventDispatcher->addListener(
            EntityEvents::PRE_SAVE->value,
            function (EntityEvent $event) use (&$observed, $rowCount): void {
                $observed[] = ['pre_save', (string) $event->entity->id(), $rowCount()];
            },
        );
        $this->eventDispatcher->addListener(
            \Waaseyaa\EntityStorage\Event\BeforeSaveEvent::class,
            function (\Waaseyaa\EntityStorage\Event\BeforeSaveEvent $event) use (&$observed, $rowCount): void {
                $observed[] = ['before_save', (string) $event->entity()->id(), $rowCount()];
            },
        );

        $repository->saveMany([$this->newEntity('1', 'First'), $this->newEntity('2', 'Second')]);

        $this->assertSame(
            [
                ['pre_save', '1', 0],
                ['before_save', '1', 0],
                ['pre_save', '2', 1],
                ['before_save', '2', 1],
            ],
            $observed,
            'PRE-write events must fire before each entity\'s row is written, inside the batch transaction.',
        );
    }

    /**
     * BeforeSaveEvent's documented abort contract ("subscribers may abort
     * via AbortOperationException; no write occurs") must hold inside
     * saveMany: an abort thrown for the SECOND entity of a batch rolls back
     * the WHOLE batch — no partial writes. Under the old post-commit
     * buffering this was unfulfillable: the abort fired after both rows had
     * already committed.
     */
    #[Test]
    public function saveManyAbortFromBeforeSaveOnSecondEntityRollsBackWholeBatch(): void
    {
        $db = DBALDatabase::createSqlite();
        $driver = new SqlStorageDriver(new SingleConnectionResolver($db));
        new SqlSchemaHandler($this->entityType, $db)->ensureTable();
        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $this->entityType,
            $driver,
            $this->eventDispatcher,
            database: $db,
        );

        $this->eventDispatcher->addListener(
            \Waaseyaa\EntityStorage\Event\BeforeSaveEvent::class,
            function (\Waaseyaa\EntityStorage\Event\BeforeSaveEvent $event): void {
                if ((string) $event->entity()->id() === '2') {
                    throw new \Waaseyaa\EntityStorage\Event\AbortOperationException('second entity refused');
                }
            },
        );

        try {
            $repository->saveMany([$this->newEntity('1', 'First'), $this->newEntity('2', 'Second')]);
            $this->fail('AbortOperationException should have propagated out of saveMany().');
        } catch (\Waaseyaa\EntityStorage\Event\AbortOperationException $e) {
            $this->assertSame('second entity refused', $e->reason);
        }

        foreach ($db->query('SELECT COUNT(*) AS c FROM test_entity') as $row) {
            $this->assertSame(0, (int) $row['c'], 'An abort mid-batch must roll back the WHOLE batch — no partial writes.');
        }
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
    // Delete-path guard timing (#2728)
    //
    // PRE_DELETE is a GUARD event: it dispatches immediately, inside any open
    // delete transaction, so a refusing listener rolls back the row, its
    // revisions AND the mutation-authority tombstone. POST_DELETE stays a
    // NOTIFICATION event: buffered until after a successful commit, discarded
    // on rollback. Before #2728 doDelete() handed PRE_DELETE to the UnitOfWork
    // buffer, so the guard only ran after commit — on BOTH delete() and
    // deleteMany(), in every DBAL-backed composition.
    // -----------------------------------------------------------------------

    /**
     * @return array{EntityRepository, DBALDatabase}
     */
    private function createSqlRepositoryWithDatabase(): array
    {
        $db = DBALDatabase::createSqlite();
        $driver = new SqlStorageDriver(new SingleConnectionResolver($db));
        new SqlSchemaHandler($this->entityType, $db)->ensureTable();

        return [
            \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                $this->entityType,
                $driver,
                $this->eventDispatcher,
                database: $db,
            ),
            $db,
        ];
    }

    private function countTestEntityRows(DBALDatabase $db): int
    {
        return (int) $db->getConnection()->fetchOne('SELECT COUNT(*) FROM test_entity');
    }

    /**
     * @return array{aggregate_version: int, mutation_tag: string, lifecycle_state: string}|null
     */
    private function authorityRow(DBALDatabase $db, string $entityId): ?array
    {
        $row = $db->getConnection()->fetchAssociative(
            'SELECT aggregate_version, mutation_tag, lifecycle_state FROM waaseyaa_entity_mutation_authority'
            . " WHERE entity_type = 'test_entity' AND entity_id = ?",
            [$entityId],
        );

        if ($row === false) {
            return null;
        }

        return [
            'aggregate_version' => (int) $row['aggregate_version'],
            'mutation_tag' => (string) $row['mutation_tag'],
            'lifecycle_state' => (string) $row['lifecycle_state'],
        ];
    }

    #[Test]
    public function deleteDispatchesPreDeleteInsideTheTransactionBeforeAnyRowIsRemoved(): void
    {
        [$repository, $db] = $this->createSqlRepositoryWithDatabase();
        $entity = $this->newEntity('1', 'Doomed');
        $repository->save($entity);

        $observed = [];
        $this->eventDispatcher->addListener(
            EntityEvents::PRE_DELETE->value,
            function () use (&$observed, $db): void {
                $observed[] = ['pre', $this->countTestEntityRows($db), $db->getConnection()->isTransactionActive()];
            },
        );
        $this->eventDispatcher->addListener(
            EntityEvents::POST_DELETE->value,
            function () use (&$observed, $db): void {
                $observed[] = ['post', $this->countTestEntityRows($db), $db->getConnection()->isTransactionActive()];
            },
        );

        $repository->delete($entity);

        $this->assertSame(
            [['pre', 1, true], ['post', 0, false]],
            $observed,
            'PRE_DELETE must run inside the open delete transaction with the row still present; '
            . 'POST_DELETE must run after commit with the row gone.',
        );
    }

    #[Test]
    public function deleteCommitsRowRemovalAndTombstoneWhenNoListenerRefuses(): void
    {
        [$repository, $db] = $this->createSqlRepositoryWithDatabase();
        $entity = $this->newEntity('1', 'Allowed');
        $repository->save($entity);

        $before = $this->authorityRow($db, '1');
        $this->assertNotNull($before);
        $this->assertSame('active', $before['lifecycle_state']);

        $postLog = [];
        $this->eventDispatcher->addListener(EntityEvents::PRE_DELETE->value, static function (): void {});
        $this->eventDispatcher->addListener(
            EntityEvents::POST_DELETE->value,
            function () use (&$postLog, $db): void {
                $postLog[] = $db->getConnection()->isTransactionActive();
            },
        );

        $repository->delete($entity);

        $this->assertSame(0, $this->countTestEntityRows($db));
        $this->assertNull($repository->find('1'));

        $after = $this->authorityRow($db, '1');
        $this->assertNotNull($after);
        $this->assertSame($before['aggregate_version'] + 1, $after['aggregate_version']);
        $this->assertSame('tombstone', $after['lifecycle_state']);

        $this->assertSame([false], $postLog, 'POST_DELETE must fire exactly once, after commit.');
    }

    #[Test]
    public function deleteRefusedByPreDeleteListenerRollsBackRowAndMutationAuthority(): void
    {
        [$repository, $db] = $this->createSqlRepositoryWithDatabase();
        $entity = $this->newEntity('1', 'Protected');
        $repository->save($entity);

        $before = $this->authorityRow($db, '1');
        $this->assertNotNull($before);
        $this->assertSame('active', $before['lifecycle_state']);
        $tokenVersionBefore = $entity->mutationToken()?->aggregateVersion;

        $guard = [];
        $postLog = [];
        $this->eventDispatcher->addListener(
            EntityEvents::PRE_DELETE->value,
            function () use (&$guard, $db): never {
                $guard[] = [$this->countTestEntityRows($db), $db->getConnection()->isTransactionActive()];

                throw new \DomainException('refused');
            },
        );
        $this->eventDispatcher->addListener(
            EntityEvents::POST_DELETE->value,
            function () use (&$postLog): void {
                $postLog[] = 'post';
            },
        );

        try {
            $repository->delete($entity);
            $this->fail('A refusing PRE_DELETE listener must propagate out of delete().');
        } catch (\DomainException $e) {
            $this->assertSame('refused', $e->getMessage());
        }

        $this->assertSame([[1, true]], $guard, 'The guard must observe the row still present, inside a transaction.');
        $this->assertSame(1, $this->countTestEntityRows($db), 'A refused delete must not remove the row.');
        $this->assertNotNull($repository->find('1'));
        $this->assertSame($before, $this->authorityRow($db, '1'), 'The tombstone must roll back with the row.');
        $this->assertSame($tokenVersionBefore, $entity->mutationToken()?->aggregateVersion);
        $this->assertSame([], $postLog, 'POST_DELETE must not fire for a refused delete.');
    }

    #[Test]
    public function deleteManyRefusalOnSecondEntityRollsBackWholeBatchIncludingMutationAuthority(): void
    {
        [$repository, $db] = $this->createSqlRepositoryWithDatabase();
        $e1 = $this->newEntity('1', 'First');
        $e2 = $this->newEntity('2', 'Second');
        $repository->saveMany([$e1, $e2]);

        $before1 = $this->authorityRow($db, '1');
        $before2 = $this->authorityRow($db, '2');
        $this->assertNotNull($before1);
        $this->assertNotNull($before2);

        $postLog = [];
        $this->eventDispatcher->addListener(
            EntityEvents::PRE_DELETE->value,
            static function (EntityEvent $event): void {
                if ((string) $event->entity->id() === '2') {
                    throw new \DomainException('second entity refused');
                }
            },
        );
        $this->eventDispatcher->addListener(
            EntityEvents::POST_DELETE->value,
            function (EntityEvent $event) use (&$postLog): void {
                $postLog[] = (string) $event->entity->id();
            },
        );

        try {
            $repository->deleteMany([$e1, $e2]);
            $this->fail('A refusing PRE_DELETE listener must propagate out of deleteMany().');
        } catch (\DomainException $e) {
            $this->assertSame('second entity refused', $e->getMessage());
        }

        $this->assertSame(2, $this->countTestEntityRows($db), 'A mid-batch refusal must roll back the WHOLE batch.');
        $this->assertNotNull($repository->find('1'));
        $this->assertNotNull($repository->find('2'));
        $this->assertSame($before1, $this->authorityRow($db, '1'));
        $this->assertSame($before2, $this->authorityRow($db, '2'));
        $this->assertSame('active', $this->authorityRow($db, '1')['lifecycle_state']);
        $this->assertSame([], $postLog, 'Buffered POST_DELETE events must be discarded on rollback.');
    }

    /**
     * The one observable ordering change #2728 ships: with PRE_DELETE
     * immediate, a batch interleaves pre1, pre2, post1, post2 instead of
     * pre1, post1, pre2, post2. The row counts each guard observes document
     * the same-connection uncommitted-read visibility that follows from it.
     */
    #[Test]
    public function deleteManyDispatchesEveryPreDeleteBeforeAnyPostDelete(): void
    {
        [$repository, $db] = $this->createSqlRepositoryWithDatabase();
        $e1 = $this->newEntity('1', 'First');
        $e2 = $this->newEntity('2', 'Second');
        $repository->saveMany([$e1, $e2]);

        $log = [];
        $guardRowCounts = [];
        $this->eventDispatcher->addListener(
            EntityEvents::PRE_DELETE->value,
            function (EntityEvent $event) use (&$log, &$guardRowCounts, $db): void {
                $log[] = 'pre:' . (string) $event->entity->id();
                $guardRowCounts[] = $this->countTestEntityRows($db);
            },
        );
        $this->eventDispatcher->addListener(
            EntityEvents::POST_DELETE->value,
            static function (EntityEvent $event) use (&$log): void {
                $log[] = 'post:' . (string) $event->entity->id();
            },
        );

        $repository->deleteMany([$e1, $e2]);

        $this->assertSame(['pre:1', 'pre:2', 'post:1', 'post:2'], $log);
        $this->assertSame(
            [2, 1],
            $guardRowCounts,
            'Each in-transaction guard observes the rows already removed for earlier batch entities.',
        );
    }

    #[Test]
    public function deleteWithoutADatabaseStillRefusesBeforeTheRowIsRemoved(): void
    {
        $entity = $this->newEntity('1', 'Untransacted');
        $this->repository->save($entity);

        $this->eventDispatcher->addListener(
            EntityEvents::PRE_DELETE->value,
            static function (): never {
                throw new \DomainException('refused');
            },
        );

        try {
            $this->repository->delete($entity);
            $this->fail('A refusing PRE_DELETE listener must propagate out of the untransacted delete path.');
        } catch (\DomainException $e) {
            $this->assertSame('refused', $e->getMessage());
        }

        $this->assertNotNull(
            $this->repository->find('1'),
            'With no mutation authority there is no tombstone, and the immediate guard refuses before any write.',
        );
    }

    #[Test]
    public function constructingARepositoryWithAMutationAuthorityButNoDatabaseIsRefused(): void
    {
        $boundary = new StorageBoundary();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires the database');

        new EntityRepository(
            $this->entityType,
            new InMemoryStorageDriverV2(
                new InMemoryStorageDriver(),
                $boundary->driverRowFactory(),
                $boundary->driverSnapshotReader(),
            ),
            $this->eventDispatcher,
            null,
            null,
            new \Waaseyaa\EntityStorage\Concurrency\EntityMutationAuthority(DBALDatabase::createSqlite(), 'primary'),
        );
    }

    #[Test]
    public function constructingADbalRepositoryWithoutAMutationAuthorityIsRefused(): void
    {
        $boundary = new StorageBoundary();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires the universal entity mutation authority');

        new EntityRepository(
            $this->entityType,
            new InMemoryStorageDriverV2(
                new InMemoryStorageDriver(),
                $boundary->driverRowFactory(),
                $boundary->driverSnapshotReader(),
            ),
            $this->eventDispatcher,
            null,
            DBALDatabase::createSqlite(),
            null,
        );
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
        new SqlSchemaHandler($lifecycleType, $db)->ensureTable();

        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
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
    public function persistence_extraction_occurs_after_pre_save_mutation(): void
    {
        $lifecycleType = new EntityType(
            id: 'test_entity',
            label: 'Test Entity',
            class: LifecycleTrackingEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );
        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create($lifecycleType, $this->driver, $this->eventDispatcher);
        $entity = new LifecycleTrackingEntity(
            values: ['id' => '88', 'label' => 'Before', 'bundle' => 'article', 'langcode' => 'en'],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );
        $entity->labelDuringPreSave = 'After preSave';
        $entity->enforceIsNew(true);

        $repository->save($entity);

        self::assertSame('After preSave', $repository->find('88')?->label());
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
        new SqlSchemaHandler($lifecycleType, $db)->ensureTable();

        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
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
        new SqlSchemaHandler($lifecycleType, $db)->ensureTable();

        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
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

        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
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
        new SqlSchemaHandler($constrainedType, $db)->ensureTable();

        $validator = new \Waaseyaa\Entity\Validation\EntityValidator(
            \Symfony\Component\Validator\Validation::createValidator(),
        );

        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
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
        new SqlSchemaHandler($constrainedType, $db)->ensureTable();

        $validator = new \Waaseyaa\Entity\Validation\EntityValidator(
            \Symfony\Component\Validator\Validation::createValidator(),
        );

        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
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
    public function saveThrowsWhenOnlyFieldDefinitionsRequireLabel(): void
    {
        EntityType::clearFromClassCache();
        $type = EntityType::fromClass(class: RequiredLabelFixture::class);

        $db = DBALDatabase::createSqlite();
        $driver = new SqlStorageDriver(new SingleConnectionResolver($db));
        new SqlSchemaHandler($type, $db)->ensureTable();

        $validator = new \Waaseyaa\Entity\Validation\EntityValidator(
            \Symfony\Component\Validator\Validation::createValidator(),
        );

        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $type,
            $driver,
            $this->eventDispatcher,
            database: $db,
            validator: $validator,
        );

        $entity = new RequiredLabelFixture(
            values: ['id' => '1', 'label' => '', 'bundle' => 'article', 'langcode' => 'en'],
        );
        $entity->enforceIsNew(true);

        $this->expectException(\Waaseyaa\Entity\Validation\EntityValidationException::class);
        $repository->save($entity);
    }

    #[Test]
    public function saveReplacesDerivedFieldConstraintsWhenManualConstraintsPresentForField(): void
    {
        EntityType::clearFromClassCache();
        $type = EntityType::fromClass(
            class: RequiredLabelFixture::class,
            constraints: ['label' => [new \Symfony\Component\Validator\Constraints\Length(max: 80)]],
        );

        $db = DBALDatabase::createSqlite();
        $driver = new SqlStorageDriver(new SingleConnectionResolver($db));
        new SqlSchemaHandler($type, $db)->ensureTable();

        $validator = new \Waaseyaa\Entity\Validation\EntityValidator(
            \Symfony\Component\Validator\Validation::createValidator(),
        );

        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $type,
            $driver,
            $this->eventDispatcher,
            database: $db,
            validator: $validator,
        );

        $entity = new RequiredLabelFixture(
            values: ['id' => '1', 'label' => '', 'bundle' => 'article', 'langcode' => 'en'],
        );
        $entity->enforceIsNew(true);

        $result = $repository->save($entity);
        $this->assertSame(EntityConstants::SAVED_NEW, $result);
    }

    #[Test]
    public function savePassesWhenEntityTypeExpectsBackedEnumAndEntityCastsScalarStorage(): void
    {
        $enumConstrainedType = new EntityType(
            id: 'test_entity',
            label: 'Test Entity',
            class: TestEnumCastStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
            constraints: [
                'flag' => [new Type(CastPersistenceStringEnum::class)],
            ],
        );

        $driver = new InMemoryStorageDriver();
        $validator = new \Waaseyaa\Entity\Validation\EntityValidator(
            \Symfony\Component\Validator\Validation::createValidator(),
        );

        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
            $enumConstrainedType,
            $driver,
            $this->eventDispatcher,
            validator: $validator,
        );

        $entity = new TestEnumCastStorageEntity(
            values: [
                'id' => '1',
                'label' => 'Valid',
                'bundle' => 'article',
                'langcode' => 'en',
                'flag' => 'on',
            ],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );
        $entity->enforceIsNew(true);

        $result = $repository->save($entity);
        $this->assertSame(EntityConstants::SAVED_NEW, $result);
    }

    #[Test]
    public function saveThrowsWhenEntityTypeExpectsBackedEnumButEntityHasNoCast(): void
    {
        $enumConstrainedType = new EntityType(
            id: 'test_entity',
            label: 'Test Entity',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
            constraints: [
                'flag' => [new Type(CastPersistenceStringEnum::class)],
            ],
        );

        $driver = new InMemoryStorageDriver();
        $validator = new \Waaseyaa\Entity\Validation\EntityValidator(
            \Symfony\Component\Validator\Validation::createValidator(),
        );

        $repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
            $enumConstrainedType,
            $driver,
            $this->eventDispatcher,
            validator: $validator,
        );

        $entity = new TestStorageEntity(
            values: [
                'id' => '1',
                'label' => 'Valid',
                'bundle' => 'article',
                'langcode' => 'en',
                'flag' => 'on',
            ],
            entityTypeId: 'test_entity',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'bundle' => 'bundle', 'label' => 'label', 'langcode' => 'langcode'],
        );
        $entity->enforceIsNew(true);

        $this->expectException(\Waaseyaa\Entity\Validation\EntityValidationException::class);
        $repository->save($entity);
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

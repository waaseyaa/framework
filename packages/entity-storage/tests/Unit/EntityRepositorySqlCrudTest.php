<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use Waaseyaa\Database\DBALDatabase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Entity\EntityConstants;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\Tests\Helper\TestEntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Exception\MutationAuthorityBackfillException;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestConfigEntity;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * C-22 WP4: ported from the retired SqlEntityStorageTest — SqlEntityStorage is
 * deleted; EntityRepository is the sole persistence engine. This file keeps
 * only the scenarios that were SQL-backed and NOT already covered by the
 * InMemoryStorageDriver-based {@see EntityRepositoryTest}: config entities
 * with string ids, preset integer ids, JSON-field round trips, and
 * `EntityRepository::create()`'s field-default application (via the shared
 * `EntityInstantiator`).
 *
 * Dropped, not ported (see docs/notes/c22-consumer-inventory.md §5/§7 for the
 * disposition of each):
 * - Timestamp/clock auto-population (`created`/`changed`-shaped fields, ISO
 *   vs unix cast storage): a confirmed, accepted engine divergence —
 *   `EntityRepository` has no clock parameter and never auto-populates these
 *   fields, unlike the deleted `SqlEntityStorage::populateTimestamps()`.
 * - `loadByKey()`: no `EntityRepository` equivalent; production callsites were
 *   converted individually to `getQuery()->condition(...)->range(0, 1)->execute()`
 *   + `find()` during WP3, already covered by the `SqlEntityQuery*` test files.
 * - `getEntityTypeId()`: no `EntityRepository` equivalent.
 * - Plain save/delete/event-dispatch/event-factory/getQuery smoke tests:
 *   already covered by {@see EntityRepositoryTest}.
 */
final class EntityRepositorySqlCrudTest extends TestCase
{
    private DBALDatabase $database;
    private EntityType $entityType;
    private EventDispatcher $eventDispatcher;
    private EntityRepository $repository;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->entityType = TestEntityType::stub(
            'test_entity',
            [
                'created' => new FieldDefinition(name: 'created', type: 'timestamp'),
                'changed' => new FieldDefinition(name: 'changed', type: 'timestamp'),
            ],
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
            class: TestStorageEntity::class,
            label: 'Test Entity',
        );
        $this->eventDispatcher = new EventDispatcher();

        $schemaHandler = new SqlSchemaHandler($this->entityType, $this->database);
        $schemaHandler->ensureTable();

        $this->repository = $this->makeRepository($this->entityType);
    }

    private function makeRepository(EntityType $entityType, ?EventDispatcherInterface $dispatcher = null): EntityRepository
    {
        $resolver = new SingleConnectionResolver($this->database);
        $driver = new SqlStorageDriver($resolver, $entityType->getKeys()['id'] ?? 'id');

        return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            $driver,
            $dispatcher ?? $this->eventDispatcher,
            database: $this->database,
        );
    }

    public function testMutationAuthorityBackfillFailsClosedBeforeWritesForAnEmptyPersistedId(): void
    {
        $repository = $this->createConfigRepository();
        $this->database->insert('node_type')->values([
            'type' => '',
            'bundle' => 'test_entity',
            'name' => 'Malformed legacy row',
            'langcode' => 'en',
            '_data' => '{}',
        ])->execute();
        $this->database->insert('node_type')->values([
            'type' => 'valid-legacy',
            'bundle' => 'test_entity',
            'name' => 'Valid legacy row',
            'langcode' => 'en',
            '_data' => '{}',
        ])->execute();

        try {
            $repository->backfillMutationAuthorities('Malformed identity regression.');
            self::fail('Malformed persisted identity was accepted.');
        } catch (MutationAuthorityBackfillException $e) {
            self::assertSame(0, $e->committedCount);
        }

        $this->database->delete('node_type')->condition('type', '')->execute();
        self::assertSame(1, $repository->backfillMutationAuthorities('Retry after malformed row removal.'));
    }

    public function testMutationAuthorityBackfillFailsClosedBeforeWritesForANulBearingPersistedId(): void
    {
        $repository = $this->createConfigRepository();
        $this->database->insert('node_type')->values([
            'type' => "malformed\0legacy",
            'bundle' => 'test_entity',
            'name' => 'Malformed legacy row',
            'langcode' => 'en',
            '_data' => '{}',
        ])->execute();
        $this->database->insert('node_type')->values([
            'type' => 'valid-legacy',
            'bundle' => 'test_entity',
            'name' => 'Valid legacy row',
            'langcode' => 'en',
            '_data' => '{}',
        ])->execute();

        try {
            $repository->backfillMutationAuthorities('NUL identity regression.');
            self::fail('NUL-bearing persisted identity was accepted.');
        } catch (MutationAuthorityBackfillException $e) {
            self::assertSame(0, $e->committedCount);
        }

        $this->database->delete('node_type')->condition('type', "malformed\0legacy")->execute();
        self::assertSame(1, $repository->backfillMutationAuthorities('Retry after malformed row removal.'));
    }

    public function testMutationAuthorityBackfillReportsCommittedRowsWhenEventDispatchFails(): void
    {
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new \RuntimeException('Listener failed after commit.'));
        $repository = $this->createConfigRepository($dispatcher);
        $this->database->insert('node_type')->values([
            'type' => 'legacy-event',
            'bundle' => 'test_entity',
            'name' => 'Legacy event row',
            'langcode' => 'en',
            '_data' => '{}',
        ])->execute();

        try {
            $repository->backfillMutationAuthorities('Post-commit accounting regression.');
            self::fail('Listener failure was not surfaced.');
        } catch (MutationAuthorityBackfillException $e) {
            self::assertSame(1, $e->committedCount);
        }

        self::assertSame(0, $this->createConfigRepository()->backfillMutationAuthorities('Idempotent retry.'));
    }

    public function testMutationAuthorityBackfillRollsBackTheWholeTypeWhenAnyCreateFails(): void
    {
        $repository = $this->createConfigRepository();
        foreach (['first', 'second'] as $type) {
            $this->database->insert('node_type')->values([
                'type' => $type,
                'bundle' => 'test_entity',
                'name' => ucfirst($type),
                'langcode' => 'en',
                '_data' => '{}',
            ])->execute();
        }
        $this->database->query(<<<'SQL'
            CREATE TRIGGER refuse_second_authority
            BEFORE INSERT ON waaseyaa_entity_mutation_authority
            WHEN NEW.entity_id = 'second'
            BEGIN
                SELECT RAISE(ABORT, 'refused test authority');
            END
            SQL);

        try {
            $repository->backfillMutationAuthorities('Atomic type regression.');
            self::fail('A refused authority insert was not surfaced.');
        } catch (MutationAuthorityBackfillException $e) {
            self::assertSame(0, $e->committedCount);
        }

        $this->database->query('DROP TRIGGER refuse_second_authority');
        self::assertSame(2, $repository->backfillMutationAuthorities('Retry after rollback.'));
    }

    public function testCreateReturnsNewEntity(): void
    {
        $entity = $this->repository->create([
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
        $entity = $this->repository->create([
            'label' => 'New Entity',
            'bundle' => 'article',
        ]);

        $result = $this->repository->save($entity, validate: false);

        $this->assertSame(EntityConstants::SAVED_NEW, $result);
        $this->assertNotNull($entity->id());
        $this->assertFalse($entity->isNew());
    }

    public function testSaveExistingEntityUpdates(): void
    {
        $entity = $this->repository->create([
            'label' => 'Original Label',
            'bundle' => 'article',
        ]);
        $this->repository->save($entity, validate: false);
        $id = (string) $entity->id();

        $entity->set('label', 'Updated Label');
        $result = $this->repository->save($entity, validate: false);

        $this->assertSame(EntityConstants::SAVED_UPDATED, $result);

        $loaded = $this->repository->find($id);
        $this->assertNotNull($loaded);
        $this->assertSame('Updated Label', $loaded->label());
    }

    public function testLoadReturnsEntity(): void
    {
        $entity = $this->repository->create([
            'label' => 'Load Me',
            'bundle' => 'page',
        ]);
        $this->repository->save($entity, validate: false);
        $id = (string) $entity->id();

        $loaded = $this->repository->find($id);

        $this->assertNotNull($loaded);
        $this->assertInstanceOf(TestStorageEntity::class, $loaded);
        $this->assertSame((string) $entity->id(), (string) $loaded->id());
        $this->assertSame('Load Me', $loaded->label());
        $this->assertSame('page', $loaded->bundle());
        $this->assertFalse($loaded->isNew());
    }

    public function testLoadReturnsNullForMissing(): void
    {
        $loaded = $this->repository->find('9999');

        $this->assertNull($loaded);
    }

    public function testLoadMultiple(): void
    {
        $entity1 = $this->repository->create(['label' => 'Entity 1', 'bundle' => 'article']);
        $entity2 = $this->repository->create(['label' => 'Entity 2', 'bundle' => 'page']);
        $entity3 = $this->repository->create(['label' => 'Entity 3', 'bundle' => 'article']);

        $this->repository->save($entity1, validate: false);
        $this->repository->save($entity2, validate: false);
        $this->repository->save($entity3, validate: false);

        $entities = $this->repository->findMany([(string) $entity1->id(), (string) $entity3->id()]);

        $this->assertCount(2, $entities);
        $labels = array_map(static fn($e) => $e->label(), $entities);
        $this->assertContains('Entity 1', $labels);
        $this->assertContains('Entity 3', $labels);
    }

    public function testLoadMultipleWithEmptyIds(): void
    {
        $entities = $this->repository->findMany([]);

        $this->assertSame([], $entities);
    }

    public function testDeleteRemovesEntities(): void
    {
        $entity1 = $this->repository->create(['label' => 'Delete Me 1', 'bundle' => 'article']);
        $entity2 = $this->repository->create(['label' => 'Delete Me 2', 'bundle' => 'article']);
        $entity3 = $this->repository->create(['label' => 'Keep Me', 'bundle' => 'article']);

        $this->repository->save($entity1, validate: false);
        $this->repository->save($entity2, validate: false);
        $this->repository->save($entity3, validate: false);

        $this->repository->deleteMany([$entity1, $entity2]);

        $this->assertNull($this->repository->find((string) $entity1->id()));
        $this->assertNull($this->repository->find((string) $entity2->id()));
        $this->assertNotNull($this->repository->find((string) $entity3->id()));
    }

    public function testDeleteWithEmptyArray(): void
    {
        $this->assertSame(0, $this->repository->deleteMany([]));
    }

    public function testEntityPreservesUuidAfterSaveAndLoad(): void
    {
        $entity = $this->repository->create([
            'label' => 'UUID Test',
            'bundle' => 'article',
        ]);
        $originalUuid = $entity->uuid();

        $this->repository->save($entity, validate: false);
        $loaded = $this->repository->find((string) $entity->id());

        $this->assertNotNull($loaded);
        $this->assertSame($originalUuid, $loaded->uuid());
    }

    public function testConfigEntityCreateSaveAndLoad(): void
    {
        $configRepository = $this->createConfigRepository();

        $entity = $configRepository->create([
            'type' => 'article',
            'name' => 'Article',
            'bundle' => '',
        ]);

        $this->assertTrue($entity->isNew());
        $this->assertSame('article', $entity->id());

        $result = $configRepository->save($entity, validate: false);
        $this->assertSame(EntityConstants::SAVED_NEW, $result);
        $this->assertFalse($entity->isNew());
        // String ID should be preserved, not cast to int.
        $this->assertSame('article', $entity->id());

        $loaded = $configRepository->find('article');
        $this->assertNotNull($loaded);
        $this->assertSame('article', $loaded->id());
        $this->assertSame('Article', $loaded->label());
        $this->assertFalse($loaded->isNew());
    }

    public function testConfigEntityUpdate(): void
    {
        $configRepository = $this->createConfigRepository();

        $entity = $configRepository->create([
            'type' => 'page',
            'name' => 'Basic Page',
            'bundle' => '',
        ]);
        $configRepository->save($entity, validate: false);

        $loaded = $configRepository->find('page');
        $loaded->set('name', 'Updated Page');
        $result = $configRepository->save($loaded, validate: false);

        $this->assertSame(EntityConstants::SAVED_UPDATED, $result);

        $reloaded = $configRepository->find('page');
        $this->assertSame('Updated Page', $reloaded->label());
    }

    public function testConfigEntityDeleteWithStringIds(): void
    {
        $configRepository = $this->createConfigRepository();

        $entity1 = $configRepository->create(['type' => 'article', 'name' => 'Article', 'bundle' => '']);
        $entity2 = $configRepository->create(['type' => 'page', 'name' => 'Page', 'bundle' => '']);
        $entity3 = $configRepository->create(['type' => 'blog', 'name' => 'Blog', 'bundle' => '']);

        $configRepository->save($entity1, validate: false);
        $configRepository->save($entity2, validate: false);
        $configRepository->save($entity3, validate: false);

        $configRepository->deleteMany([$entity1, $entity2]);

        $this->assertNull($configRepository->find('article'));
        $this->assertNull($configRepository->find('page'));
        $this->assertNotNull($configRepository->find('blog'));
    }

    public function testConfigEntityLoadMultipleWithStringIds(): void
    {
        $configRepository = $this->createConfigRepository();

        $entity1 = $configRepository->create(['type' => 'article', 'name' => 'Article', 'bundle' => '']);
        $entity2 = $configRepository->create(['type' => 'page', 'name' => 'Page', 'bundle' => '']);
        $entity3 = $configRepository->create(['type' => 'blog', 'name' => 'Blog', 'bundle' => '']);

        $configRepository->save($entity1, validate: false);
        $configRepository->save($entity2, validate: false);
        $configRepository->save($entity3, validate: false);

        $entities = $configRepository->findMany(['article', 'blog']);

        $this->assertCount(2, $entities);
        $labels = array_map(static fn($e) => $e->label(), $entities);
        $this->assertContains('Article', $labels);
        $this->assertContains('Blog', $labels);
    }

    public function testContentEntityWithPresetIntegerId(): void
    {
        $entity = $this->repository->create([
            'id' => 42,
            'label' => 'Pre-set ID',
            'bundle' => 'article',
        ]);
        $entity->enforceIsNew();

        $result = $this->repository->save($entity, validate: false);

        $this->assertSame(EntityConstants::SAVED_NEW, $result);
        $this->assertSame(42, $entity->id());
        $this->assertFalse($entity->isNew());

        $loaded = $this->repository->find('42');
        $this->assertNotNull($loaded);
        $this->assertSame(42, $loaded->id());
        $this->assertSame('Pre-set ID', $loaded->label());
    }

    /** @param array<string, array<string, mixed>> $fields */
    private function createRepositoryWithFields(string $id, array $fields): EntityRepository
    {
        $defs = [];
        foreach ($fields as $name => $meta) {
            $defs[$name] = new FieldDefinition(
                name: $name,
                type: (string) ($meta['type'] ?? 'string'),
                defaultValue: $meta['default'] ?? null,
            );
        }
        $entityType = TestEntityType::stub(
            $id,
            $defs,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'bundle' => 'bundle',
                'label' => 'label',
                'langcode' => 'langcode',
            ],
            class: TestStorageEntity::class,
            label: ucfirst($id),
        );

        $schemaHandler = new SqlSchemaHandler($entityType, $this->database);
        $schemaHandler->ensureTable();

        return $this->makeRepository($entityType);
    }

    private function createConfigRepository(?EventDispatcherInterface $dispatcher = null): EntityRepository
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
            _fieldDefinitions: [
                'name' => new FieldDefinition(name: 'name', type: 'string', read: \Waaseyaa\Entity\FieldReadLevel::Public),
            ],
        );

        $schemaHandler = new SqlSchemaHandler($configType, $this->database);
        $schemaHandler->ensureTable();

        return $this->makeRepository($configType, $dispatcher);
    }

    public function testCreateAppliesFieldDefaults(): void
    {
        $repository = $this->createRepositoryWithFields('default_test', [
            'status' => ['type' => 'integer', 'default' => 1],
        ]);

        $entity = $repository->create([]);

        $this->assertSame(1, $entity->get('status'));
    }

    public function testCreateExplicitValuesOverrideDefaults(): void
    {
        $repository = $this->createRepositoryWithFields('override_test', [
            'status' => ['type' => 'integer', 'default' => 1],
        ]);

        $entity = $repository->create(['status' => 0]);

        $this->assertSame(0, $entity->get('status'));
    }

    public function testCreateSkipsFieldsWithoutDefaultKey(): void
    {
        $repository = $this->createRepositoryWithFields('nodefault_test', [
            'title' => ['type' => 'string'],
        ]);

        $entity = $repository->create([]);

        $this->assertNull($entity->get('title'));
    }

    public function testJsonFieldRoundTrip(): void
    {
        $repository = $this->createRepositoryWithFields('json_test', [
            'metadata' => ['type' => 'json'],
        ]);

        $entity = $repository->create([
            'label' => 'JSON Test',
            'bundle' => 'article',
            'metadata' => ['tags' => ['php', 'waaseyaa'], 'version' => 2],
        ]);
        $repository->save($entity, validate: false);

        $loaded = $repository->find((string) $entity->id());
        $this->assertNotNull($loaded);
        $this->assertIsArray($loaded->get('metadata'));
        $this->assertSame(['tags' => ['php', 'waaseyaa'], 'version' => 2], $loaded->get('metadata'));
    }

    public function testJsonFieldNullRoundTrip(): void
    {
        $repository = $this->createRepositoryWithFields('json_null_test', [
            'metadata' => ['type' => 'json'],
        ]);

        $entity = $repository->create([
            'label' => 'JSON Null Test',
            'bundle' => 'article',
            'metadata' => null,
        ]);
        $repository->save($entity, validate: false);

        $loaded = $repository->find((string) $entity->id());
        $this->assertNotNull($loaded);
        $this->assertNull($loaded->get('metadata'));
    }

    public function testPreSaveMutationsArePersisted(): void
    {
        $this->eventDispatcher->addListener(
            EntityEvents::PRE_SAVE->value,
            function (EntityEvent $event): void {
                $event->entity->set('label', 'Mutated by listener');
            },
        );

        $entity = $this->repository->create(['label' => 'Original', 'bundle' => 'article']);
        $this->repository->save($entity, validate: false);

        $loaded = $this->repository->find((string) $entity->id());
        $this->assertNotNull($loaded);
        $this->assertSame('Mutated by listener', $loaded->label());
    }
}

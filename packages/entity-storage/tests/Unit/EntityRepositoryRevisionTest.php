<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityValueComparator;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Exception\EntityMutationConflictException;
use Waaseyaa\EntityStorage\Exception\RevisionConflictException;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;

#[CoversClass(EntityRepository::class)]
final class EntityRepositoryRevisionTest extends TestCase
{
    private DBALDatabase $db;
    private EntityRepository $repo;
    /** @var string[] */
    private array $dispatchedEvents = [];
    /** @var array<string, list<object>> */
    private array $dispatchedEventPayloads = [];

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $entityType = new EntityType(
            id: 'test_revisionable',
            label: 'Test',
            class: TestRevisionableEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );

        $handler = new SqlSchemaHandler($entityType, $this->db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $resolver = new SingleConnectionResolver($this->db);
        $driver = new SqlStorageDriver($resolver);
        $revisionDriver = new RevisionableStorageDriver($resolver, $entityType);

        $this->dispatchedEvents = [];
        $this->dispatchedEventPayloads = [];
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($event, $eventName) {
            $this->dispatchedEvents[] = $eventName;
            $this->dispatchedEventPayloads[$eventName][] = $event;
            return $event;
        });

        $this->repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            $driver,
            $dispatcher,
            $revisionDriver,
            $this->db,
        );
    }

    #[Test]
    public function save_new_entity_creates_revision_1(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'Hello', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $loaded = $this->repo->find('1');
        $this->assertNotNull($loaded);
        $this->assertInstanceOf(RevisionableInterface::class, $loaded);
        $this->assertSame(1, $loaded->getRevisionId());
    }

    #[Test]
    public function save_creates_new_revision_when_revision_default_true(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $entity = $this->repo->find('1');
        $entity->set('title', 'v2');
        $this->repo->save($entity);

        $loaded = $this->repo->find('1');
        $this->assertSame(2, $loaded->getRevisionId());
        $this->assertSame('v2', $loaded->label());
    }

    #[Test]
    public function save_with_new_revision_false_updates_in_place(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $entity = $this->repo->find('1');
        $entity->setNewRevision(false);
        $entity->set('title', 'v1-updated');
        $this->repo->save($entity);

        $loaded = $this->repo->find('1');
        $this->assertSame(1, $loaded->getRevisionId());
        $this->assertSame('v1-updated', $loaded->label());
    }

    #[Test]
    public function load_revision_returns_specific_revision(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $entity = $this->repo->find('1');
        $entity->set('title', 'v2');
        $this->repo->save($entity);

        $rev1 = $this->repo->loadRevision('1', 1);
        $this->assertNotNull($rev1);
        $this->assertSame('v1', $rev1->label());
        self::assertSame(1, $rev1->entityStructure()->revisionId);
        self::assertFalse($rev1->entityStructure()->revisionTip);
        self::assertFalse($rev1->entityStructure()->defaultRevision);

        $rev2 = $this->repo->loadRevision('1', 2);
        self::assertNotNull($rev2);
        self::assertSame(2, $rev2->entityStructure()->revisionId);
        self::assertTrue($rev2->entityStructure()->revisionTip);
        self::assertTrue($rev2->entityStructure()->defaultRevision);
    }

    #[Test]
    public function rollback_creates_new_revision_with_target_values(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $entity = $this->repo->find('1');
        $entity->set('title', 'v2');
        $this->repo->save($entity);

        $entity = $this->repo->find('1');
        $entity->set('title', 'v3');
        $this->repo->save($entity);

        $rolledBack = $this->repo->rollback('1', 1, $this->mutationToken('1'));

        $this->assertSame(4, $rolledBack->getRevisionId());
        $this->assertSame('v1', $rolledBack->label());
        $this->assertSame('Reverted to revision 1', $rolledBack->getRevisionLog());
    }

    #[Test]
    public function guarded_rollback_rejects_a_stale_mutation_token_without_events_or_a_new_revision(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);
        $staleToken = $this->mutationToken('1');

        $entity = $this->repo->find('1');
        $entity->set('title', 'v2');
        $this->repo->save($entity);
        $this->dispatchedEvents = [];

        try {
            $this->repo->rollback('1', 1, $staleToken);
            self::fail('A stale guarded rollback was accepted.');
        } catch (EntityMutationConflictException $exception) {
            self::assertSame('test_revisionable', $exception->entityTypeId);
            self::assertSame('1', $exception->entityId);
            self::assertNull($exception->currentToken);
            self::assertCount(2, $this->repo->listRevisions('1'));
            self::assertSame([], $this->dispatchedEvents);
        }
    }

    #[Test]
    public function guarded_rollback_rejects_a_stale_revision_without_events_or_a_new_revision(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $entity = $this->repo->find('1');
        $entity->set('title', 'v2');
        $this->repo->save($entity);
        $this->dispatchedEvents = [];

        try {
            $this->repo->rollback('1', 1, $this->mutationToken('1'), 1);
            self::fail('A rollback guarded by a stale current revision was accepted.');
        } catch (RevisionConflictException $exception) {
            self::assertSame('test_revisionable', $exception->entityTypeId);
            self::assertSame('1', $exception->entityId);
            self::assertSame(1, $exception->expectedRevisionId);
            self::assertSame(2, $exception->currentRevisionId);
            self::assertCount(2, $this->repo->listRevisions('1'));
            self::assertSame([], $this->dispatchedEvents);
        }
    }

    #[Test]
    public function rollback_dispatches_revision_events(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $entity = $this->repo->find('1');
        $entity->set('title', 'v2');
        $this->repo->save($entity);

        $this->dispatchedEvents = [];
        $this->repo->rollback('1', 1, $this->mutationToken('1'));

        $this->assertContains(EntityEvents::REVISION_CREATED->value, $this->dispatchedEvents);
        $this->assertContains(EntityEvents::REVISION_REVERTED->value, $this->dispatchedEvents);
    }

    #[Test]
    public function rollback_preserves_the_live_published_pointer_and_status(): void
    {
        // #1920 WP-2 rework fix wave (review finding I-1 / #4 containment):
        // rollback() must restore CONTENT only. It must never let the target
        // revision's frozen published_revision_id/status snapshot overwrite the
        // LIVE base row's pointer/status — moving the published pointer or
        // flipping status is exclusively TransitionService's job (CW-v1
        // decision 2).
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->set('status', 0);
        $entity->enforceIsNew();
        $this->repo->save($entity); // revision 1: title=v1, status=0 (frozen), published_revision_id=null (frozen)

        $entity = $this->repo->find('1');
        $entity->set('title', 'v2');
        $entity->set('status', 1);
        $this->repo->save($entity); // revision 2: title=v2, status=1; base row now status=1

        // Publish revision 1 — a targeted column update, touching ONLY the
        // base row's published_revision_id (never revision 1's own snapshot,
        // which still reads published_revision_id=null).
        $this->repo->setPublishedRevision('1', 1, $this->mutationToken('1'));
        $this->assertSame(1, $this->repo->find('1')->get('published_revision_id'), 'live pointer set before rollback');
        $this->assertSame(true, $this->repo->find('1')->get('status'), 'live status before rollback');

        // Roll back CONTENT to revision 1 (whose frozen snapshot has
        // status=0 and published_revision_id=null — stale values that must
        // NOT clobber the live base row).
        $rolledBack = $this->repo->rollback('1', 1, $this->mutationToken('1'));

        $this->assertSame('v1', $rolledBack->label(), 'content restored from the target revision');
        $this->assertSame(true, $this->repo->find('1')->get('status'), 'live status untouched by rollback');
        $this->assertSame(
            1,
            $this->repo->find('1')->get('published_revision_id'),
            'live published pointer untouched by rollback',
        );
    }

    #[Test]
    public function revision_restore_preserves_live_credentials_instead_of_replaying_historical_values(): void
    {
        $entity = new TestRevisionableEntity(values: [
            'title' => 'v1',
            'id' => '1',
            'uuid' => 'a',
            'pass' => 'old-hash',
            'password_hash' => 'old-password-hash',
        ]);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $entity = $this->repo->find('1');
        self::assertNotNull($entity);
        $entity->set('title', 'v2');
        $entity->set('pass', 'live-hash');
        $entity->set('password_hash', 'live-password-hash');
        $this->repo->save($entity);

        $rolledBack = $this->repo->rollback('1', 1, $this->mutationToken('1'));
        self::assertSame('v1', $rolledBack->label());
        $liveValues = $this->rawDataValues('1');
        self::assertSame('live-hash', $liveValues['pass'] ?? null);
        self::assertSame('live-password-hash', $liveValues['password_hash'] ?? null);

        $this->dispatchedEventPayloads = [];
        $reverted = $this->repo->setCurrentRevision('1', 1, $this->mutationToken('1'));
        self::assertSame('v1', $reverted->label());
        $this->assertLiveCredentials($reverted);

        $revertedEvents = $this->dispatchedEventPayloads[EntityEvents::REVISION_REVERTED->value] ?? [];
        self::assertCount(1, $revertedEvents);
        self::assertInstanceOf(EntityEvent::class, $revertedEvents[0]);
        $this->assertLiveCredentials($revertedEvents[0]->entity);

        // The returned entity carries a fresh mutation token. Saving it must
        // not turn that token into a replay path for historical credentials.
        $reverted->set('title', 'v1-after-save');
        $this->repo->save($reverted);
        $liveValues = $this->rawDataValues('1');
        self::assertSame('live-hash', $liveValues['pass'] ?? null);
        self::assertSame('live-password-hash', $liveValues['password_hash'] ?? null);
    }

    private function assertLiveCredentials(EntityInterface $entity): void
    {
        $expected = new TestRevisionableEntity(values: [
            'pass' => 'live-hash',
            'password_hash' => 'live-password-hash',
        ]);
        self::assertSame(
            [],
            new EntityValueComparator()->changedFieldNames($entity, $expected, ['pass', 'password_hash']),
        );
    }

    /** @return array<string, mixed> */
    private function rawDataValues(string $entityId): array
    {
        $rows = iterator_to_array($this->db->query(
            'SELECT _data FROM test_revisionable WHERE id = ?',
            [$entityId],
        ));
        self::assertCount(1, $rows);

        return json_decode((string) ((array) $rows[0])['_data'], true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function rollback_throws_for_nonexistent_target(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $this->expectException(\InvalidArgumentException::class);
        $this->repo->rollback('1', 99, $this->mutationToken('1'));
    }

    #[Test]
    public function save_dispatches_revision_created_event(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->dispatchedEvents = [];
        $this->repo->save($entity);

        $this->assertContains(EntityEvents::REVISION_CREATED->value, $this->dispatchedEvents);
    }

    #[Test]
    public function delete_removes_all_revisions(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $entity = $this->repo->find('1');
        $entity->set('title', 'v2');
        $this->repo->save($entity);

        $entity = $this->repo->find('1');
        $this->repo->delete($entity);

        $this->assertNull($this->repo->find('1'));
        $this->assertNull($this->repo->loadRevision('1', 1));
    }

    /**
     * #2728, acceptance criterion 5 (revision cleanup where applicable). A
     * PRE_DELETE refusal now fires inside the delete transaction, so the
     * revision rows deleteAllRevisions() would have removed roll back with
     * the base row and the mutation-authority tombstone.
     *
     * This test builds its own repository over a REAL dispatcher (setUp()'s
     * recording stub cannot throw) and reads revision state via raw SQL,
     * never via driver memory: RevisionableStorageDriver keeps an in-process
     * langcode-pointer map that no database rollback touches.
     */
    #[Test]
    public function refusedDeleteOfARevisionableEntityRestoresEveryRevisionRow(): void
    {
        $db = DBALDatabase::createSqlite();
        $entityType = new EntityType(
            id: 'test_revisionable',
            label: 'Test',
            class: TestRevisionableEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );
        $handler = new SqlSchemaHandler($entityType, $db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $dispatcher = new EventDispatcher();
        $buildRepository = static function () use ($entityType, $db, $dispatcher): EntityRepository {
            $resolver = new SingleConnectionResolver($db);

            return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                $entityType,
                new SqlStorageDriver($resolver),
                $dispatcher,
                new RevisionableStorageDriver($resolver, $entityType),
                $db,
            );
        };

        $repo = $buildRepository();
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $repo->save($entity);
        $entity = $repo->find('1');
        $entity->set('title', 'v2');
        $repo->save($entity);

        $revisionIds = static fn(): array => array_map(
            static fn(array $row): int => (int) $row['revision_id'],
            $db->getConnection()->fetchAllAssociative(
                'SELECT revision_id FROM test_revisionable_revision WHERE entity_id = ? ORDER BY revision_id',
                ['1'],
            ),
        );
        $authorityRow = static fn(): array|false => $db->getConnection()->fetchAssociative(
            'SELECT aggregate_version, mutation_tag, lifecycle_state FROM waaseyaa_entity_mutation_authority'
            . " WHERE entity_type = 'test_revisionable' AND entity_id = '1'",
        );

        $revisionsBefore = $revisionIds();
        $this->assertCount(2, $revisionsBefore);
        $authorityBefore = $authorityRow();
        $this->assertIsArray($authorityBefore);
        $this->assertSame('active', $authorityBefore['lifecycle_state']);

        $refuse = static function (): never {
            throw new \DomainException('refused');
        };
        $dispatcher->addListener(EntityEvents::PRE_DELETE->value, $refuse);

        $toDelete = $repo->find('1');
        $this->assertNotNull($toDelete);
        try {
            $repo->delete($toDelete);
            $this->fail('A refusing PRE_DELETE listener must propagate out of delete().');
        } catch (\DomainException $e) {
            $this->assertSame('refused', $e->getMessage());
        }

        $this->assertSame(
            1,
            (int) $db->getConnection()->fetchOne('SELECT COUNT(*) FROM test_revisionable WHERE id = ?', ['1']),
        );
        $this->assertSame($revisionsBefore, $revisionIds(), 'Revision rows must roll back with the refused delete.');
        $this->assertSame($authorityBefore, $authorityRow());

        $dispatcher->removeListener(EntityEvents::PRE_DELETE->value, $refuse);

        // Fresh driver instance: the revisionable driver's in-process langcode
        // pointer map is not rolled back, so re-read through new state.
        $freshRepo = $buildRepository();
        $reloaded = $freshRepo->find('1');
        $this->assertNotNull($reloaded);
        $freshRepo->delete($reloaded);

        $this->assertSame([], $revisionIds(), 'An allowed delete still clears every revision row.');
        $this->assertSame(
            0,
            (int) $db->getConnection()->fetchOne('SELECT COUNT(*) FROM test_revisionable WHERE id = ?', ['1']),
        );
        $after = $authorityRow();
        $this->assertIsArray($after);
        $this->assertSame('tombstone', $after['lifecycle_state']);
    }

    private function mutationToken(string $entityId): \Waaseyaa\Entity\Concurrency\EntityMutationToken
    {
        $token = $this->repo->find($entityId)?->mutationToken();
        self::assertNotNull($token);

        return $token;
    }
}

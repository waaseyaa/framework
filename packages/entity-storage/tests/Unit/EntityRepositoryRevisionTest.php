<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;

#[CoversClass(EntityRepository::class)]
final class EntityRepositoryRevisionTest extends TestCase
{
    private DBALDatabase $db;
    private EntityRepository $repo;
    /** @var string[] */
    private array $dispatchedEvents = [];

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
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($event, $eventName) {
            $this->dispatchedEvents[] = $eventName;
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

        $rolledBack = $this->repo->rollback('1', 1);

        $this->assertSame(4, $rolledBack->getRevisionId());
        $this->assertSame('v1', $rolledBack->label());
        $this->assertSame('Reverted to revision 1', $rolledBack->getRevisionLog());
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
        $this->repo->rollback('1', 1);

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
        $this->repo->setPublishedRevision('1', 1);
        $this->assertSame(1, $this->repo->find('1')->get('published_revision_id'), 'live pointer set before rollback');
        $this->assertSame(true, $this->repo->find('1')->get('status'), 'live status before rollback');

        // Roll back CONTENT to revision 1 (whose frozen snapshot has
        // status=0 and published_revision_id=null — stale values that must
        // NOT clobber the live base row).
        $rolledBack = $this->repo->rollback('1', 1);

        $this->assertSame('v1', $rolledBack->label(), 'content restored from the target revision');
        $this->assertSame(true, $this->repo->find('1')->get('status'), 'live status untouched by rollback');
        $this->assertSame(
            1,
            $this->repo->find('1')->get('published_revision_id'),
            'live published pointer untouched by rollback',
        );
    }

    #[Test]
    public function rollback_throws_for_nonexistent_target(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $this->expectException(\InvalidArgumentException::class);
        $this->repo->rollback('1', 99);
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
}

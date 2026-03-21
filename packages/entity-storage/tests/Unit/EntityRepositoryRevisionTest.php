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

        $this->repo = new EntityRepository(
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

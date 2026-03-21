<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Revision;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;

/**
 * Full lifecycle integration test matching the spec's example scenario.
 *
 * @see docs/superpowers/specs/2026-03-21-revision-lifecycle-design.md Section F
 */
#[CoversNothing]
final class RevisionLifecycleIntegrationTest extends TestCase
{
    private EntityRepository $repo;
    private DBALDatabase $db;
    /** @var string[] */
    private array $events = [];

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

        $this->events = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EntityEvents::REVISION_CREATED->value, function () {
            $this->events[] = 'revision_created';
        });
        $dispatcher->addListener(EntityEvents::REVISION_REVERTED->value, function () {
            $this->events[] = 'revision_reverted';
        });

        $this->repo = new EntityRepository($entityType, $driver, $dispatcher, $revisionDriver, $this->db);
    }

    /**
     * Reproduces the exact 5-step lifecycle from the design spec.
     */
    #[Test]
    public function full_lifecycle_from_spec(): void
    {
        // Step 1: Create → rev 1
        $entity = new TestRevisionableEntity(values: ['title' => 'Hello', 'id' => '1', 'uuid' => 'abc-123']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $loaded = $this->repo->find('1');
        $this->assertSame(1, $loaded->getRevisionId());
        $this->assertSame('Hello', $loaded->label());

        // Step 2: Edit → rev 2
        $loaded->set('title', 'Hello World');
        $this->repo->save($loaded);

        $loaded = $this->repo->find('1');
        $this->assertSame(2, $loaded->getRevisionId());
        $this->assertSame('Hello World', $loaded->label());

        // Step 3: Edit again → rev 3
        $loaded->set('title', 'Greetings');
        $this->repo->save($loaded);

        $loaded = $this->repo->find('1');
        $this->assertSame(3, $loaded->getRevisionId());
        $this->assertSame('Greetings', $loaded->label());

        // Step 4: Rollback to rev 1 → rev 4 (copy-forward)
        $rolledBack = $this->repo->rollback('1', 1);
        $this->assertSame(4, $rolledBack->getRevisionId());
        $this->assertSame('Hello', $rolledBack->label());

        // Verify rollback log
        $rev4 = $this->repo->loadRevision('1', 4);
        $this->assertSame('Reverted to revision 1', $rev4->getRevisionLog());

        // Step 5: In-place update (no new revision)
        $loaded = $this->repo->find('1');
        $loaded->setNewRevision(false);
        $loaded->set('title', 'Hello!');
        $this->repo->save($loaded);

        $loaded = $this->repo->find('1');
        $this->assertSame(4, $loaded->getRevisionId());
        $this->assertSame('Hello!', $loaded->label());

        // Verify historical revisions are intact
        $rev1 = $this->repo->loadRevision('1', 1);
        $this->assertSame('Hello', $rev1->label());

        $rev2 = $this->repo->loadRevision('1', 2);
        $this->assertSame('Hello World', $rev2->label());

        $rev3 = $this->repo->loadRevision('1', 3);
        $this->assertSame('Greetings', $rev3->label());
    }

    #[Test]
    public function revision_events_are_dispatched(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->events = [];
        $this->repo->save($entity);
        $this->assertContains('revision_created', $this->events);

        $entity = $this->repo->find('1');
        $entity->set('title', 'v2');
        $this->events = [];
        $this->repo->save($entity);
        $this->assertContains('revision_created', $this->events);

        $this->events = [];
        $this->repo->rollback('1', 1);
        $this->assertContains('revision_reverted', $this->events);
    }

    #[Test]
    public function delete_cascades_all_revisions(): void
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
        $this->assertNull($this->repo->loadRevision('1', 2));
    }

    #[Test]
    public function non_revisionable_entity_type_ignores_revision_logic(): void
    {
        $entityType = new EntityType(
            id: 'test_simple',
            label: 'Simple',
            class: TestRevisionableEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            revisionable: false,
        );

        $handler = new SqlSchemaHandler($entityType, $this->db);
        $handler->ensureTable();

        $resolver = new SingleConnectionResolver($this->db);
        $driver = new SqlStorageDriver($resolver);
        $dispatcher = new EventDispatcher();
        $repo = new EntityRepository($entityType, $driver, $dispatcher);

        $entity = new TestRevisionableEntity(
            values: ['title' => 'No revisions', 'id' => '1', 'uuid' => 'x'],
            entityTypeId: 'test_simple',
            entityKeys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
        );
        $entity->enforceIsNew();
        $repo->save($entity);

        $loaded = $repo->find('1');
        $this->assertSame('No revisions', $loaded->label());
        $this->assertNull($loaded->getRevisionId());
    }

    #[Test]
    public function rollback_to_nonexistent_revision_throws(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Revision 99 does not exist');
        $this->repo->rollback('1', 99);
    }

    #[Test]
    public function monotonic_revision_ids(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        for ($i = 2; $i <= 4; $i++) {
            $entity = $this->repo->find('1');
            $entity->set('title', "v{$i}");
            $this->repo->save($entity);
        }

        $this->assertSame(4, $this->repo->find('1')->getRevisionId());
    }
}

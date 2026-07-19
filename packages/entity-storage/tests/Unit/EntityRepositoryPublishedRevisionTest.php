<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;

/**
 * The published-revision pointer is separate from the current/latest revision:
 * the live ("published") view can render an older revision while newer draft
 * revisions exist, and publishing an older revision is how a live rollback works.
 */
#[CoversClass(EntityRepository::class)]
final class EntityRepositoryPublishedRevisionTest extends TestCase
{
    private DBALDatabase $db;
    private EntityRepository $repo;

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

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        $this->repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            $driver,
            $dispatcher,
            $revisionDriver,
            $this->db,
        );
    }

    #[Test]
    public function published_revision_is_null_until_something_is_published(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $this->assertNull($this->repo->loadPublishedRevision('1'));
    }

    #[Test]
    public function publishing_a_revision_does_not_move_the_current_draft(): void
    {
        // Two revisions: current/latest is v2 (the draft).
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);
        $entity = $this->repo->find('1');
        $entity->set('title', 'v2');
        $this->repo->save($entity);

        // Publish the OLDER revision (1).
        $published = $this->repo->setPublishedRevision('1', 1);

        // Published view = v1; current/latest (find) still = v2.
        $this->assertSame('v1', $published->label());
        $this->assertSame('v1', $this->repo->loadPublishedRevision('1')->label());
        $this->assertSame('v2', $this->repo->find('1')->label());
        $this->assertSame(2, $this->repo->find('1')->getRevisionId());
    }

    #[Test]
    public function republishing_promotes_a_different_revision(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);
        $entity = $this->repo->find('1');
        $entity->set('title', 'v2');
        $this->repo->save($entity);

        $this->repo->setPublishedRevision('1', 1);
        $this->assertSame('v1', $this->repo->loadPublishedRevision('1')->label());

        // Promote the newer revision: live view rolls forward to v2.
        $this->repo->setPublishedRevision('1', 2);
        $this->assertSame('v2', $this->repo->loadPublishedRevision('1')->label());
    }

    #[Test]
    public function publishing_a_nonexistent_revision_throws(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        $this->expectException(\InvalidArgumentException::class);
        $this->repo->setPublishedRevision('1', 99);
    }
}

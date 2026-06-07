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
use Waaseyaa\EntityStorage\Revision\RevisionPruningPolicy;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;

/**
 * WP-revisions: repository-level surface added for the revision UX —
 * listRevisions(), setCurrentRevision(), pruneRevisions(), backfillInitialRevisions().
 */
#[CoversClass(EntityRepository::class)]
final class EntityRepositoryRevisionSurfaceTest extends TestCase
{
    private DBALDatabase $db;
    private EntityRepository $repo;
    private EntityType $entityType;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $this->entityType = new EntityType(
            id: 'test_revisionable',
            label: 'Test',
            class: TestRevisionableEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );

        $handler = new SqlSchemaHandler($this->entityType, $this->db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $resolver = new SingleConnectionResolver($this->db);
        $driver = new SqlStorageDriver($resolver);
        $revisionDriver = new RevisionableStorageDriver($resolver, $this->entityType);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        $this->repo = new EntityRepository(
            $this->entityType,
            $driver,
            $dispatcher,
            $revisionDriver,
            $this->db,
        );
    }

    private function createWithEdits(string $id, string ...$titles): void
    {
        $first = array_shift($titles);
        $entity = new TestRevisionableEntity(values: ['title' => $first, 'id' => $id, 'uuid' => 'u' . $id]);
        $entity->enforceIsNew();
        $this->repo->save($entity);

        foreach ($titles as $title) {
            $entity = $this->repo->find($id);
            $entity->set('title', $title);
            $this->repo->save($entity);
        }
    }

    #[Test]
    public function list_revisions_returns_history_newest_first(): void
    {
        $this->createWithEdits('1', 'v1', 'v2', 'v3');

        $revisions = $this->repo->listRevisions('1');

        $this->assertCount(3, $revisions);
        $this->assertSame('v3', $revisions[0]->label(), 'newest first');
        $this->assertSame('v2', $revisions[1]->label());
        $this->assertSame('v1', $revisions[2]->label());
    }

    #[Test]
    public function list_revisions_is_empty_for_unknown_entity(): void
    {
        $this->assertSame([], $this->repo->listRevisions('999'));
    }

    #[Test]
    public function set_current_revision_moves_pointer_without_creating_a_revision(): void
    {
        $this->createWithEdits('1', 'v1', 'v2', 'v3');
        $this->assertCount(3, $this->repo->listRevisions('1'));

        $reverted = $this->repo->setCurrentRevision('1', 1);

        $this->assertSame('v1', $reverted->label());
        // The current/default entity now reads v1...
        $this->assertSame('v1', $this->repo->find('1')->label());
        // ...and NO new revision was created (still exactly 3).
        $this->assertCount(3, $this->repo->listRevisions('1'));
    }

    #[Test]
    public function set_current_revision_rejects_a_missing_revision(): void
    {
        $this->createWithEdits('1', 'v1');
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->setCurrentRevision('1', 999);
    }

    #[Test]
    public function prune_revisions_trims_to_policy_and_keeps_current(): void
    {
        $this->createWithEdits('1', 'v1', 'v2', 'v3', 'v4'); // revisions 1..4, current = 4

        $report = $this->repo->pruneRevisions('1', RevisionPruningPolicy::keepLastUniform(2));

        $this->assertSame(2, $report->pruned, 'oldest 2 deleted');
        $remaining = $this->repo->listRevisions('1');
        $this->assertCount(2, $remaining);
        $this->assertSame('v4', $remaining[0]->label());
        $this->assertSame('v3', $remaining[1]->label());
    }

    #[Test]
    public function prune_revisions_never_deletes_the_current_revision(): void
    {
        $this->createWithEdits('1', 'v1', 'v2', 'v3', 'v4');
        // Make an OLD revision current, then prune hard (keep last 1).
        $this->repo->setCurrentRevision('1', 1);

        $report = $this->repo->pruneRevisions('1', RevisionPruningPolicy::keepLastUniform(1));

        // Revision 1 is current → immortal; only revisions 2 and 3 are deletable
        // (revision 4 is the single newest kept).
        $this->assertSame(2, $report->pruned);
        $ids = array_map(static fn($e) => $e->getRevisionId(), $this->repo->listRevisions('1'));
        $this->assertContains(1, $ids, 'current revision survived');
        $this->assertContains(4, $ids, 'newest kept');
    }

    #[Test]
    public function prune_revisions_noop_policy_deletes_nothing(): void
    {
        $this->createWithEdits('1', 'v1', 'v2', 'v3');
        $report = $this->repo->pruneRevisions('1', RevisionPruningPolicy::default());

        $this->assertSame(0, $report->pruned);
        $this->assertCount(3, $this->repo->listRevisions('1'));
    }

    #[Test]
    public function backfill_initial_revisions_versions_unversioned_rows(): void
    {
        // Simulate a row that predates revisions: write a base row directly with
        // no revision_id and no revision rows.
        $this->db->getConnection()->insert('test_revisionable', [
            'id' => 7,
            'uuid' => 'u7',
            'title' => 'legacy',
            '_data' => '{}',
        ]);
        $this->assertSame([], $this->repo->listRevisions('7'), 'no revisions before backfill');

        $count = $this->repo->backfillInitialRevisions();

        $this->assertSame(1, $count);
        $revisions = $this->repo->listRevisions('7');
        $this->assertCount(1, $revisions);
        $this->assertSame('legacy', $revisions[0]->label());

        // Idempotent: a second backfill does nothing.
        $this->assertSame(0, $this->repo->backfillInitialRevisions());
    }
}

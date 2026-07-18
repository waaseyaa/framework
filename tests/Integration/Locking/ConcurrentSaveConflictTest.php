<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Locking;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Event\BeforeSaveEvent;
use Waaseyaa\EntityStorage\Exception\RevisionConflictException;
use Waaseyaa\EntityStorage\SaveContext;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;

/**
 * Mission optimistic-locking-01KTXCHY WP01/T005 — exactly-one-winner pin
 * (NFR-002 / SC-004, contracts/conflict-detection.md §10).
 *
 * Deterministic interleave, no threads, no sleeps: a BeforeSaveEvent
 * subscriber performs a competing expectation-stated save of the same entity.
 * Events fire after the outer save's fail-fast pre-check and before the outer
 * write transaction opens, so the outer pre-check passes against the old
 * head, the inner save commits and moves the head, and the outer save's
 * guarded pointer-claim UPDATE matches 0 rows → RevisionConflictException.
 * Exactly one winner; the loser's freshly written revision row rolls back
 * with its transaction (no orphan revisions, contract §8).
 */
#[CoversNothing]
final class ConcurrentSaveConflictTest extends TestCase
{
    private DBALDatabase $db;
    private EntityRepository $repo;

    /**
     * Minimal real dispatcher with a single BeforeSaveEvent hook — the
     * interleave needs a listener actually invoked mid-save, which the
     * repository reaches through the PSR dispatch(event, eventName) call.
     */
    private object $dispatcher;

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

        $this->dispatcher = new class implements EventDispatcherInterface {
            public ?\Closure $onBeforeSave = null;

            public function dispatch(object $event, ?string $eventName = null): object
            {
                if ($event instanceof BeforeSaveEvent && $this->onBeforeSave !== null) {
                    ($this->onBeforeSave)($event);
                }

                return $event;
            }
        };

        $this->repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::create(
            $entityType,
            new SqlStorageDriver($resolver),
            $this->dispatcher,
            new RevisionableStorageDriver($resolver, $entityType),
            $this->db,
        );
    }

    private function revisionIds(): array
    {
        $ids = [];
        foreach ($this->db->query('SELECT revision_id FROM test_revisionable_revision ORDER BY revision_id') as $row) {
            $ids[] = (int) $row['revision_id'];
        }

        return $ids;
    }

    #[Test]
    public function twoInterleavedSavesStatingTheSameExpectationProduceExactlyOneWinner(): void
    {
        // Seed at revision 1.
        $seed = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $seed->enforceIsNew();
        $this->repo->save($seed);

        $outer = $this->repo->find('1');
        self::assertInstanceOf(TestRevisionableEntity::class, $outer);
        $outer->set('title', 'outer-loser');

        // Once, mid-outer-save (between the outer pre-check and the outer
        // transaction), a competing save of the same entity states the SAME
        // expectation and completes.
        $fired = false;
        $repo = $this->repo;
        $this->dispatcher->onBeforeSave = function () use (&$fired, $repo): void {
            if ($fired) {
                return;
            }
            $fired = true;

            $inner = $repo->find('1');
            $inner->set('title', 'inner-winner');
            $repo->save($inner, context: SaveContext::default()->withExpectedRevisionId(1));
        };

        $caught = null;
        try {
            $this->repo->save($outer, context: SaveContext::default()->withExpectedRevisionId(1));
        } catch (RevisionConflictException $e) {
            $caught = $e;
        }

        self::assertTrue($fired, 'The competing save must have run mid-outer-save');
        self::assertNotNull($caught, 'The outer save must lose with RevisionConflictException');

        // The exception carries the winner's head as current (contract §8).
        self::assertSame(1, $caught->expectedRevisionId);
        self::assertSame(2, $caught->currentRevisionId);

        // Exactly one winner: the entity carries the winner's values.
        $final = $this->repo->find('1');
        self::assertInstanceOf(TestRevisionableEntity::class, $final);
        self::assertSame('inner-winner', $final->label());
        self::assertSame(2, $final->getRevisionId());

        // The loser's revision row rolled back: only revisions 1 and 2 exist
        // (the outer save had written revision 3 before its claim failed).
        self::assertSame([1, 2], $this->revisionIds());
    }
}

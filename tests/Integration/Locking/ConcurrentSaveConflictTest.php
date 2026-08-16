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
 * Transactional write-capable event regression retained from the legacy
 * revision-lock contract.
 *
 * A listener that writes through the same database connection is nested work,
 * not a concurrent transaction. DB-03 claims aggregate authority before
 * write-capable events and runs them inside the mutation transaction, so a
 * later failure must roll back both the outer mutation and listener effects.
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

        $this->repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
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
    public function nestedListenerMutationRollsBackWithTheFailingOuterCommand(): void
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

        // The listener ran on the same connection inside the outer unit of
        // work, so the later failure rolls the entire command back.
        $final = $this->repo->find('1');
        self::assertInstanceOf(TestRevisionableEntity::class, $final);
        self::assertSame('v1', $final->label());
        self::assertSame(1, $final->getRevisionId());

        // The loser's revision row rolled back: only revisions 1 and 2 exist
        // (the outer save had written revision 3 before its claim failed).
        self::assertSame([1], $this->revisionIds());
    }
}

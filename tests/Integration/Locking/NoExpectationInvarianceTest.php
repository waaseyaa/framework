<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Locking;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\DeleteInterface;
use Waaseyaa\Database\InsertInterface;
use Waaseyaa\Database\SchemaInterface;
use Waaseyaa\Database\SelectInterface;
use Waaseyaa\Database\TransactionInterface;
use Waaseyaa\Database\UpdateInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Exception\RevisionConflictException;
use Waaseyaa\EntityStorage\SaveContext;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;

/**
 * Mission optimistic-locking-01KTXCHY WP01/T006 — no-expectation invariance
 * pins (contracts/conflict-detection.md §14–§16, FR-003, NFR-001, C-002).
 */
#[CoversNothing]
final class NoExpectationInvarianceTest extends TestCase
{
    /**
     * NFR-001 / contract §15 — the query-count contract for a no-expectation
     * update save of a revisionable entity, measured through the counting
     * decorator (one count per select/insert/update/delete builder creation
     * and per raw query() call):
     *
     *   1. select — original-entity load (`find()` → `SqlStorageDriver::read()`)
     *   2. select — `RevisionableStorageDriver::writeRevision()` next-revision-id lookup
     *   3. insert — the new revision row
     *   4. select — `SqlStorageDriver::write()` existence check
     *   5. update — the base-row write
     *
     * This count predates the optimistic-locking mission and must NOT grow
     * for saves that state no expectation: every conflict-detection branch is
     * gated on `SaveContext::expectedRevisionId() !== null`. If this pin
     * breaks, a mission branch leaked onto the legacy path.
     */
    private const int NO_EXPECTATION_UPDATE_QUERY_COUNT = 5;

    private DBALDatabase $inner;
    private DatabaseInterface $counting;
    private EntityRepository $repo;

    protected function setUp(): void
    {
        $this->inner = DBALDatabase::createSqlite();
        $entityType = new EntityType(
            id: 'test_revisionable',
            label: 'Test',
            class: TestRevisionableEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );

        // Schema setup runs on the raw connection — not counted.
        $handler = new SqlSchemaHandler($entityType, $this->inner);
        $handler->ensureTable();
        $handler->ensureRevisionTable();

        $this->counting = $this->countingDatabase($this->inner);

        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnArgument(0);

        $resolver = new SingleConnectionResolver($this->counting);
        $this->repo = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            new SqlStorageDriver($resolver),
            $dispatcher,
            new RevisionableStorageDriver($resolver, $entityType),
            $this->counting,
        );
    }

    /**
     * Counting DatabaseInterface decorator: forwards every method to the
     * wrapped database, incrementing a public counter for each query-issuing
     * call (select/insert/update/delete builder creations + raw query()).
     * schema()/transaction()/quoteIdentifier() are forwarded uncounted.
     */
    private function countingDatabase(DatabaseInterface $wrapped): DatabaseInterface
    {
        return new class ($wrapped) implements DatabaseInterface {
            public int $queries = 0;

            public function __construct(private readonly DatabaseInterface $wrapped) {}

            public function select(string $table, string $alias = ''): SelectInterface
            {
                ++$this->queries;

                return $this->wrapped->select($table, $alias);
            }

            public function insert(string $table): InsertInterface
            {
                ++$this->queries;

                return $this->wrapped->insert($table);
            }

            public function update(string $table): UpdateInterface
            {
                ++$this->queries;

                return $this->wrapped->update($table);
            }

            public function delete(string $table): DeleteInterface
            {
                ++$this->queries;

                return $this->wrapped->delete($table);
            }

            public function schema(): SchemaInterface
            {
                return $this->wrapped->schema();
            }

            public function transaction(string $name = ''): TransactionInterface
            {
                return $this->wrapped->transaction($name);
            }

            public function query(string $sql, array $args = []): \Traversable
            {
                ++$this->queries;

                return $this->wrapped->query($sql, $args);
            }

            public function quoteIdentifier(string $identifier): string
            {
                return $this->wrapped->quoteIdentifier($identifier);
            }
        };
    }

    private function seed(): void
    {
        $entity = new TestRevisionableEntity(values: ['title' => 'v1', 'id' => '1', 'uuid' => 'a']);
        $entity->enforceIsNew();
        $this->repo->save($entity);
    }

    #[Test]
    public function noExpectationUpdateSaveIssuesThePinnedQueryCount(): void
    {
        $this->seed();

        $entity = $this->repo->find('1');
        self::assertInstanceOf(TestRevisionableEntity::class, $entity);
        $entity->set('title', 'v2');

        $counter = $this->counting;
        $counter->queries = 0;
        $this->repo->save($entity);

        self::assertSame(
            self::NO_EXPECTATION_UPDATE_QUERY_COUNT,
            $counter->queries,
            'NFR-001: a no-expectation update save must issue the pre-mission query count — '
            . 'a conflict-detection branch leaked onto the legacy path.',
        );
    }

    #[Test]
    public function disjointFieldWritersWithoutExpectationsMergeCleanly(): void
    {
        $this->seed();

        // Writer 1: fresh head, sets only field_a.
        $writer1 = $this->repo->find('1');
        $writer1->set('field_a', 'A');
        $this->repo->save($writer1);

        // Writer 2: fresh head (set-only-supplied-fields onto a fresh head —
        // the read-modify-write mechanic both update surfaces use), sets only
        // field_b.
        $writer2 = $this->repo->find('1');
        $writer2->set('field_b', 'B');
        $this->repo->save($writer2);

        $final = $this->repo->find('1');
        self::assertSame('A', $final->get('field_a'));
        self::assertSame('B', $final->get('field_b'));
    }

    #[Test]
    public function disjointFieldWritersWithTheSameExpectationProduceOneWinnerThenCleanRetry(): void
    {
        $this->seed(); // head = 1

        // Both writers loaded at head 1.
        $writer1 = $this->repo->find('1');
        $writer2 = $this->repo->find('1');

        // Writer 1 wins: disjoint field, expectation 1 → head 2, merge intact.
        $writer1->set('field_a', 'A');
        $this->repo->save($writer1, context: SaveContext::default()->withExpectedRevisionId(1));

        $afterWin = $this->repo->find('1');
        self::assertInstanceOf(TestRevisionableEntity::class, $afterWin);
        self::assertSame(2, $afterWin->getRevisionId());
        self::assertSame('A', $afterWin->get('field_a'));

        // Writer 2 loses: same stated expectation, head moved.
        $writer2->set('field_b', 'B');
        try {
            $this->repo->save($writer2, context: SaveContext::default()->withExpectedRevisionId(1));
            self::fail('Expected RevisionConflictException for the second writer');
        } catch (RevisionConflictException $e) {
            self::assertSame(1, $e->expectedRevisionId);
            self::assertSame(2, $e->currentRevisionId);
        }

        // Re-read + restate: the retry merges cleanly onto the new head.
        $retry = $this->repo->find('1');
        $retry->set('field_b', 'B');
        $this->repo->save($retry, context: SaveContext::default()->withExpectedRevisionId(2));

        $final = $this->repo->find('1');
        self::assertInstanceOf(TestRevisionableEntity::class, $final);
        self::assertSame(3, $final->getRevisionId());
        self::assertSame('A', $final->get('field_a'));
        self::assertSame('B', $final->get('field_b'));
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Driver;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestRevisionableEntity;

/**
 * #1706 — revision-id allocation under concurrency.
 *
 * Revision ids are allocated `MAX(revision_id)+1`, which is NOT atomic: two
 * concurrent writers for the same entity can read the same MAX and try to claim
 * the same id. The composite PRIMARY KEY (`entity_id, revision_id`) is the
 * integrity backstop — it makes a duplicate id structurally impossible — and
 * `RevisionableStorageDriver` retries the losing write to the next free id
 * rather than surfacing the unique-constraint violation. These tests pin both:
 * that the same id is NEVER assigned twice, and that the loser advances.
 */
#[CoversClass(RevisionableStorageDriver::class)]
final class RevisionIdAllocationRaceTest extends TestCase
{
    private DBALDatabase $db;
    private EntityType $entityType;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite();
        $this->entityType = new EntityType(
            id: 'test_revisionable',
            label: 'Test Revisionable',
            class: TestRevisionableEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'revision' => 'revision_id'],
            revisionable: true,
            revisionDefault: true,
        );

        $handler = new SqlSchemaHandler($this->entityType, $this->db);
        $handler->ensureTable();
        $handler->ensureRevisionTable();
    }

    #[Test]
    public function sequential_writes_allocate_distinct_increasing_ids(): void
    {
        $driver = new RevisionableStorageDriver(new SingleConnectionResolver($this->db), $this->entityType);

        self::assertSame(1, $driver->writeRevision('1', ['title' => 'v1', 'uuid' => 'a'], null));
        self::assertSame(2, $driver->writeRevision('1', ['title' => 'v2', 'uuid' => 'a'], null));
        self::assertSame(3, $driver->writeRevision('1', ['title' => 'v3', 'uuid' => 'a'], null));
    }

    #[Test]
    public function concurrent_collision_is_retried_to_the_next_free_id(): void
    {
        // Simulate a concurrent writer that wins the race for the first allocated
        // (entity_id, revision_id): the decorated DB writes the row once (the
        // "winner") and then re-inserts the same id, which the composite PK
        // rejects with a REAL UniqueConstraintViolationException (no hand-built
        // exception — the integrity backstop produces it).
        $stealing = $this->stealingDatabaseOnceFor('test_revisionable_revision');

        $driver = new RevisionableStorageDriver(new SingleConnectionResolver($stealing), $this->entityType);

        $revisionId = $driver->writeRevision('1', ['title' => 'v1', 'uuid' => 'a'], null);

        // The first attempt allocated id 1, lost the race, caught the PK
        // violation, re-read MAX (now 1) and succeeded at id 2 — never a duplicate
        // 1. A regression that dropped the retry would surface the violation; one
        // that dropped the PK would return 1 twice.
        self::assertSame(2, $revisionId, 'allocation must advance past the stolen id, never duplicate it');
        self::assertNotNull($driver->readRevision('1', 1), 'the winner revision exists');
        self::assertNotNull($driver->readRevision('1', 2), 'our retried revision exists');
    }

    /**
     * A {@see DatabaseInterface} that, exactly once and only for the revision
     * table, turns the first INSERT into a lost race: it writes the row (the
     * concurrent winner) and then re-inserts the same id so the composite PK
     * throws. Every other call delegates to the real database.
     */
    private function stealingDatabaseOnceFor(string $revisionTable): DatabaseInterface
    {
        return new class ($this->db, $revisionTable) implements DatabaseInterface {
            private bool $armed = true;

            public function __construct(
                private readonly DatabaseInterface $inner,
                private readonly string $revisionTable,
            ) {}

            public function insert(string $table): InsertInterface
            {
                if (!$this->armed || $table !== $this->revisionTable) {
                    return $this->inner->insert($table);
                }
                $this->armed = false;

                return new class ($this->inner, $table) implements InsertInterface {
                    /** @var list<string> */
                    private array $fields = [];
                    /** @var array<string, mixed> */
                    private array $values = [];

                    public function __construct(
                        private readonly DatabaseInterface $inner,
                        private readonly string $table,
                    ) {}

                    public function fields(array $fields): static
                    {
                        $this->fields = array_values($fields);

                        return $this;
                    }

                    public function values(array $values): static
                    {
                        $this->values = $values;

                        return $this;
                    }

                    public function execute(): int|string
                    {
                        // Concurrent winner claims the id...
                        $this->inner->insert($this->table)->fields($this->fields)->values($this->values)->execute();

                        // ...so OUR insert of the same id hits the composite PK and
                        // raises a real UniqueConstraintViolationException.
                        return $this->inner->insert($this->table)->fields($this->fields)->values($this->values)->execute();
                    }
                };
            }

            public function select(string $table, string $alias = ''): SelectInterface
            {
                return $this->inner->select($table, $alias);
            }

            public function update(string $table): UpdateInterface
            {
                return $this->inner->update($table);
            }

            public function delete(string $table): DeleteInterface
            {
                return $this->inner->delete($table);
            }

            public function schema(): SchemaInterface
            {
                return $this->inner->schema();
            }

            public function transaction(string $name = ''): TransactionInterface
            {
                return $this->inner->transaction($name);
            }

            public function query(string $sql, array $args = []): \Traversable
            {
                return $this->inner->query($sql, $args);
            }

            public function quoteIdentifier(string $identifier): string
            {
                return $this->inner->quoteIdentifier($identifier);
            }
        };
    }
}

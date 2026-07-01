<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DeleteInterface;
use Waaseyaa\Database\InsertInterface;
use Waaseyaa\Database\SchemaInterface;
use Waaseyaa\Database\SelectInterface;
use Waaseyaa\Database\TransactionInterface;
use Waaseyaa\Database\UpdateInterface;
use Waaseyaa\Queue\Transport\DbalTransport;

#[CoversClass(DbalTransport::class)]
final class DbalTransportTest extends TestCase
{
    private DBALDatabase $database;
    private DbalTransport $transport;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->createTable();
        $this->transport = new DbalTransport($this->database);
    }

    private function createTable(): void
    {
        $this->database->schema()->createTable('waaseyaa_queue_jobs', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'queue' => ['type' => 'varchar', 'not null' => true],
                'payload' => ['type' => 'text', 'not null' => true],
                'attempts' => ['type' => 'int', 'not null' => true, 'default' => 0],
                'available_at' => ['type' => 'int', 'not null' => true],
                'reserved_at' => ['type' => 'int'],
                'created_at' => ['type' => 'int', 'not null' => true],
            ],
            'primary key' => ['id'],
            'indexes' => [
                'idx_queue_available' => ['queue', 'available_at'],
            ],
        ]);
    }

    #[Test]
    public function pushAndPopJob(): void
    {
        $this->transport->push('default', 'test-payload');

        $job = $this->transport->pop('default');

        self::assertNotNull($job);
        self::assertSame('test-payload', $job['payload']);
        self::assertSame(0, $job['attempts']);
    }

    #[Test]
    public function popReturnsNullWhenEmpty(): void
    {
        self::assertNull($this->transport->pop('default'));
    }

    #[Test]
    public function popRespectsAvailableAt(): void
    {
        // Push a job with 1-hour delay
        $this->transport->push('default', 'delayed', 3600);

        // Should not be available yet
        self::assertNull($this->transport->pop('default'));
    }

    #[Test]
    public function ackRemovesJob(): void
    {
        $this->transport->push('default', 'payload');
        $job = $this->transport->pop('default');

        $this->transport->ack($job['id']);

        // Job should be gone from the table entirely
        self::assertSame(0, $this->transport->size('default'));
    }

    #[Test]
    public function rejectRemovesJob(): void
    {
        $this->transport->push('default', 'payload');
        $job = $this->transport->pop('default');

        $this->transport->reject($job['id']);

        self::assertSame(0, $this->transport->size('default'));
    }

    #[Test]
    public function releaseReturnsJobToQueue(): void
    {
        $this->transport->push('default', 'payload');
        $job = $this->transport->pop('default');

        $this->transport->release($job['id'], 0);

        // Job should be available again
        self::assertSame(1, $this->transport->size('default'));
    }

    #[Test]
    public function releaseIncrementsAttempts(): void
    {
        $this->transport->push('default', 'payload');
        $job = $this->transport->pop('default');
        self::assertSame(0, $job['attempts']);

        $this->transport->release($job['id'], 0);
        $job2 = $this->transport->pop('default');

        self::assertNotNull($job2);
        self::assertSame(1, $job2['attempts']);
    }

    #[Test]
    public function sizeCountsPendingJobs(): void
    {
        self::assertSame(0, $this->transport->size('default'));

        $this->transport->push('default', 'a');
        $this->transport->push('default', 'b');

        self::assertSame(2, $this->transport->size('default'));

        // Reserved jobs don't count
        $this->transport->pop('default');
        self::assertSame(1, $this->transport->size('default'));
    }

    #[Test]
    public function purgeRemovesAllJobs(): void
    {
        $this->transport->push('default', 'a');
        $this->transport->push('default', 'b');

        $this->transport->purge('default');

        self::assertSame(0, $this->transport->size('default'));
    }

    #[Test]
    public function queuesAreIsolated(): void
    {
        $this->transport->push('high', 'important');
        $this->transport->push('low', 'background');

        self::assertSame(1, $this->transport->size('high'));
        self::assertSame(1, $this->transport->size('low'));

        $job = $this->transport->pop('high');
        self::assertSame('important', $job['payload']);
    }

    #[Test]
    public function popProcessesInFifoOrder(): void
    {
        $this->transport->push('default', 'first');
        $this->transport->push('default', 'second');
        $this->transport->push('default', 'third');

        $job1 = $this->transport->pop('default');
        $job2 = $this->transport->pop('default');
        $job3 = $this->transport->pop('default');

        self::assertSame('first', $job1['payload']);
        self::assertSame('second', $job2['payload']);
        self::assertSame('third', $job3['payload']);
    }

    #[Test]
    public function popReclaimsExpiredLeaseAndIncrementsAttempts(): void
    {
        // 60s visibility timeout for a deterministic, sleep-free test.
        $transport = new DbalTransport($this->database, 60);
        $transport->push('default', 'payload');

        // A worker claims the job, then "dies" (never acks/releases).
        $first = $transport->pop('default');
        self::assertNotNull($first);
        self::assertSame(0, $first['attempts']);

        // Without reclaim it would be stranded: a fresh pop sees nothing.
        self::assertNull($transport->pop('default'));

        // Simulate the lease expiring by back-dating reserved_at past the timeout.
        $this->backdateReservedAt((int) $first['id'], time() - 120);

        // The crashed worker's job is reclaimed (not lost) with attempts bumped.
        $reclaimed = $transport->pop('default');
        self::assertNotNull($reclaimed);
        self::assertSame((int) $first['id'], (int) $reclaimed['id']);
        self::assertSame('payload', $reclaimed['payload']);
        self::assertSame(1, $reclaimed['attempts']);
    }

    #[Test]
    public function popDoesNotReclaimWithinVisibilityWindow(): void
    {
        $transport = new DbalTransport($this->database, 60);
        $transport->push('default', 'payload');

        $first = $transport->pop('default');
        self::assertNotNull($first);

        // Lease is still valid (reserved just now) — must NOT be reclaimed.
        self::assertNull($transport->pop('default'));

        // Back-date to just within the window (30s < 60s timeout) — still not expired.
        $this->backdateReservedAt((int) $first['id'], time() - 30);
        self::assertNull($transport->pop('default'));
    }

    #[Test]
    public function popReclaimIncrementsAttemptsEachCycle(): void
    {
        $transport = new DbalTransport($this->database, 60);
        $transport->push('default', 'payload');

        $job = $transport->pop('default');
        self::assertNotNull($job);
        self::assertSame(0, $job['attempts']);
        $id = (int) $job['id'];

        for ($expected = 1; $expected <= 3; $expected++) {
            $this->backdateReservedAt($id, time() - 120);
            $job = $transport->pop('default');
            self::assertNotNull($job);
            self::assertSame($expected, $job['attempts'], "attempts after reclaim #{$expected}");
        }
    }

    // --- M3: proportional-retry pop() under contention ---

    #[Test]
    public function popReturnsJobAfterMoreThanThreeLostClaimRaces(): void
    {
        // The old loop had a hard cap of 3. Under contention (K=5 > 3 lost races
        // before success) the old code returned null — a false-empty while the job
        // existed and was claimable. The new proportional-retry loop must succeed.
        $racesBeforeSuccess = 5;

        $spyDb = new ContentiousDatabase($this->database, $racesBeforeSuccess);
        $transport = new DbalTransport($spyDb);
        $transport->push('default', 'contended-payload');

        $job = $transport->pop('default');

        self::assertNotNull($job, 'pop() must not return false-empty when the job exists but early claim races are lost');
        self::assertSame('contended-payload', $job['payload']);
    }

    #[Test]
    public function popReturnsNullWhenQueueIsGenuinelyEmpty(): void
    {
        // Verify the genuine-empty path: no job in the DB → pop returns null
        // immediately without hitting any safety bounds.
        $spyDb = new ContentiousDatabase($this->database, 0);
        $transport = new DbalTransport($spyDb);

        self::assertNull($transport->pop('default'));
    }

    #[Test]
    public function popReturnsNullWhenSafetyBoundIsReachedWithoutWinningAClaim(): void
    {
        // If the claim UPDATE returns 0 for every attempt (extreme adversarial
        // contention or a bug), pop() must return null rather than loop forever.
        // ContentiousDatabase with PHP_INT_MAX races simulates this ceiling.
        $spyDb = new ContentiousDatabase($this->database, PHP_INT_MAX);
        $transport = new DbalTransport($spyDb);
        $transport->push('default', 'always-loses');

        // This must terminate (not hang) and return null.
        $result = $transport->pop('default');

        self::assertNull($result, 'pop() must return null at the safety bound rather than loop forever');
    }

    private function backdateReservedAt(int $id, int $timestamp): void
    {
        $this->database->update('waaseyaa_queue_jobs')
            ->fields(['reserved_at' => $timestamp])
            ->condition('id', $id)
            ->execute();
    }
}

/**
 * Spy DatabaseInterface decorator that makes UPDATE return 0 rows affected for
 * the first $racesBeforeSuccess calls, simulating lost claim races under contention.
 *
 * SELECT/INSERT/DELETE delegate to the real database so SELECT keeps returning the
 * same unclaimed candidate (the UPDATE no-op leaves the row unreserved).
 */
final class ContentiousDatabase implements DatabaseInterface
{
    private int $failsLeft;

    public function __construct(
        private readonly DatabaseInterface $real,
        int $racesBeforeSuccess,
    ) {
        $this->failsLeft = $racesBeforeSuccess;
    }

    public function update(string $table): UpdateInterface
    {
        if ($this->failsLeft > 0) {
            $this->failsLeft--;

            // Return a no-op builder: collects the fluent calls but execute()
            // never touches the DB, leaving the row unclaimed so the next SELECT
            // can find it again.
            return new ZeroRowsUpdate();
        }

        return $this->real->update($table);
    }

    public function select(string $table, string $alias = ''): SelectInterface
    {
        return $this->real->select($table, $alias);
    }

    public function insert(string $table): InsertInterface
    {
        return $this->real->insert($table);
    }

    public function delete(string $table): DeleteInterface
    {
        return $this->real->delete($table);
    }

    public function schema(): SchemaInterface
    {
        return $this->real->schema();
    }

    public function transaction(string $name = ''): TransactionInterface
    {
        return $this->real->transaction($name);
    }

    /** @return \Traversable<mixed> */
    public function query(string $sql, array $args = []): \Traversable
    {
        return $this->real->query($sql, $args);
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->real->quoteIdentifier($identifier);
    }
}

/**
 * No-op UpdateInterface implementation that returns 0 from execute() without
 * touching the database, simulating a lost claim race.
 */
final class ZeroRowsUpdate implements UpdateInterface
{
    public function fields(array $fields): static
    {
        return $this;
    }

    public function condition(string $field, mixed $value, string $operator = '='): static
    {
        return $this;
    }

    public function execute(): int
    {
        return 0;
    }
}

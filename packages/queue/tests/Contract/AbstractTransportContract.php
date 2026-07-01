<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Queue\Transport\TransportInterface;

/**
 * Abstract contract test for {@see TransportInterface::listJobs()}.
 *
 * Concrete subclasses provide a fresh transport via {@see makeTransport()};
 * the assertions here exercise the documented FR-001..FR-004 surface against
 * every backend so the SQL-vs-in-memory implementations stay in lockstep.
 *
 * Each concrete subclass MUST extend this and only override `makeTransport()`
 * (and any backend-specific schema fixtures it needs in setUp/tearDown).
 *
 * @api
 */
#[CoversNothing]
abstract class AbstractTransportContract extends TestCase
{
    /**
     * Produce a fresh, empty transport for one test.
     */
    abstract protected function makeTransport(): TransportInterface;

    #[Test]
    public function listJobsReturnsEmptyShapeWhenNoJobs(): void
    {
        $transport = $this->makeTransport();

        $result = $transport->listJobs(20, 0);

        self::assertSame([], $result['data']);
        self::assertSame(0, $result['total']);
    }

    #[Test]
    public function listJobsReturnsAllQueuedJobsOrderedByIdAsc(): void
    {
        $transport = $this->makeTransport();
        $transport->push('default', 'first');
        $transport->push('default', 'second');
        $transport->push('high', 'third');

        $result = $transport->listJobs(20, 0);

        self::assertSame(3, $result['total']);
        self::assertCount(3, $result['data']);
        self::assertSame('first', $result['data'][0]['payload']);
        self::assertSame('second', $result['data'][1]['payload']);
        self::assertSame('third', $result['data'][2]['payload']);
        foreach ($result['data'] as $row) {
            self::assertSame('queued', $row['status']);
            self::assertNull($row['reserved_at']);
            self::assertArrayHasKey('id', $row);
            self::assertArrayHasKey('queue', $row);
            self::assertArrayHasKey('attempts', $row);
            self::assertArrayHasKey('available_at', $row);
        }
    }

    #[Test]
    public function listJobsSplitsQueuedAndInProgressByReservedAt(): void
    {
        $transport = $this->makeTransport();
        $transport->push('default', 'a');
        $transport->push('default', 'b');
        $transport->push('default', 'c');

        // Pop one — moves it from queued to in_progress.
        $reserved = $transport->pop('default');
        self::assertNotNull($reserved);

        $result = $transport->listJobs(20, 0);

        self::assertSame(3, $result['total']);
        self::assertCount(3, $result['data']);

        $statuses = array_column($result['data'], 'status');
        sort($statuses);
        self::assertSame(['in_progress', 'queued', 'queued'], $statuses);

        // The popped (smallest id) row should be the one in_progress.
        self::assertSame('in_progress', $result['data'][0]['status']);
        self::assertNotNull($result['data'][0]['reserved_at']);
        self::assertSame('queued', $result['data'][1]['status']);
        self::assertNull($result['data'][1]['reserved_at']);
    }

    #[Test]
    public function listJobsFiltersByQueuedStatus(): void
    {
        $transport = $this->makeTransport();
        $transport->push('default', 'a');
        $transport->push('default', 'b');
        $transport->pop('default'); // first job → in_progress

        $result = $transport->listJobs(20, 0, 'queued');

        self::assertSame(1, $result['total']);
        self::assertCount(1, $result['data']);
        self::assertSame('queued', $result['data'][0]['status']);
        self::assertSame('b', $result['data'][0]['payload']);
    }

    #[Test]
    public function listJobsFiltersByInProgressStatus(): void
    {
        $transport = $this->makeTransport();
        $transport->push('default', 'a');
        $transport->push('default', 'b');
        $transport->pop('default'); // first job → in_progress

        $result = $transport->listJobs(20, 0, 'in_progress');

        self::assertSame(1, $result['total']);
        self::assertCount(1, $result['data']);
        self::assertSame('in_progress', $result['data'][0]['status']);
        self::assertSame('a', $result['data'][0]['payload']);
    }

    #[Test]
    public function listJobsPaginatesViaLimitAndOffset(): void
    {
        $transport = $this->makeTransport();
        foreach (['a', 'b', 'c', 'd'] as $payload) {
            $transport->push('default', $payload);
        }

        $page1 = $transport->listJobs(2, 0);
        $page2 = $transport->listJobs(2, 2);

        self::assertSame(4, $page1['total']);
        self::assertSame(4, $page2['total']);
        self::assertCount(2, $page1['data']);
        self::assertCount(2, $page2['data']);
        self::assertSame('a', $page1['data'][0]['payload']);
        self::assertSame('b', $page1['data'][1]['payload']);
        self::assertSame('c', $page2['data'][0]['payload']);
        self::assertSame('d', $page2['data'][1]['payload']);
    }

    #[Test]
    public function listJobsReturnsEmptyDataWhenLimitIsZero(): void
    {
        $transport = $this->makeTransport();
        $transport->push('default', 'a');
        $transport->push('default', 'b');

        $result = $transport->listJobs(0, 0);

        self::assertSame([], $result['data']);
        self::assertSame(2, $result['total']);
    }

    #[Test]
    public function listJobsRejectsUnknownStatusFilter(): void
    {
        $transport = $this->makeTransport();

        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore-next-line argument.type — deliberate contract violation under test */
        $transport->listJobs(10, 0, 'unknown');
    }

    #[Test]
    public function releaseIncrementsAttemptsAtomically(): void
    {
        $transport = $this->makeTransport();
        $transport->push('default', '{"job":"test"}');

        // Pop the job — fresh claim, attempts = 0.
        $job = $transport->pop('default');
        self::assertNotNull($job, 'pop() must return the job');
        self::assertSame(0, $job['attempts'], 'fresh claim must start with attempts=0');

        // Release it (simulate a soft failure / retry).
        $transport->release($job['id'], 0);

        // Pop again — attempts must have been incremented atomically to 1.
        $released = $transport->pop('default');
        self::assertNotNull($released, 'released job must be re-claimable');
        self::assertSame(1, $released['attempts'],
            'release() must increment attempts atomically (no read-then-write race)');
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Queue\Handler\JobHandler;
use Waaseyaa\Queue\Security\SignedQueuePayload;
use Waaseyaa\Queue\Storage\InMemoryFailedJobRepository;
use Waaseyaa\Queue\Tests\Unit\Fixtures\CrashingJob;
use Waaseyaa\Queue\Transport\DbalTransport;
use Waaseyaa\Queue\Worker\Worker;
use Waaseyaa\Queue\Worker\WorkerOptions;

/**
 * Crash-recovery (lease / visibility timeout) end-to-end coverage — queue M1.
 *
 * Exercises the real {@see DbalTransport} reclaim path through the {@see Worker}:
 * a job claimed by a worker that then "dies" (we simulate the death by popping
 * without ack/release and back-dating the lease) must be reclaimed, its attempts
 * bumped, processed if it still has budget, and recorded failed — NOT reclaimed
 * forever — once its max-tries budget is exhausted.
 */
#[CoversClass(Worker::class)]
#[CoversClass(DbalTransport::class)]
final class CrashRecoveryTest extends TestCase
{
    private const VISIBILITY_TIMEOUT = 60;

    private DBALDatabase $database;
    private DbalTransport $transport;
    private InMemoryFailedJobRepository $failedRepo;
    private Worker $worker;
    private SignedQueuePayload $signer;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->createTable();
        $this->transport = new DbalTransport($this->database, self::VISIBILITY_TIMEOUT);
        $this->failedRepo = new InMemoryFailedJobRepository();
        $this->signer = new SignedQueuePayload(str_repeat('q', 32));
        $this->worker = new Worker($this->transport, $this->failedRepo, [new JobHandler()], $this->signer);
        CrashingJob::reset();
    }

    #[Test]
    public function reclaimedJobWithRemainingBudgetIsProcessedNotLost(): void
    {
        $this->transport->push('default', $this->signer->seal(serialize(new CrashingJob()))); // tries = 3

        // A worker claims it and dies before processing.
        $claimed = $this->transport->pop('default');
        self::assertNotNull($claimed);
        $this->expireLease((int) $claimed['id']);

        // A healthy worker reclaims and runs it (attempts now 1, well under tries=3).
        $ran = $this->worker->runNextJob('default', new WorkerOptions());

        self::assertTrue($ran);
        self::assertSame(1, CrashingJob::$handleCount, 'reclaimed job must be processed, not lost');
        self::assertCount(0, $this->failedRepo->all());
        self::assertSame(0, $this->transport->size('default'));
    }

    #[Test]
    public function repeatedlyAbandonedJobIsRecordedFailedNotReclaimedForever(): void
    {
        $this->transport->push('default', $this->signer->seal(serialize(new CrashingJob()))); // tries = 3

        // Simulate the worker dying after every claim, three times. Each reclaim
        // bumps attempts: fresh(0) → reclaim(1) → reclaim(2). Never processed.
        $job = $this->transport->pop('default'); // fresh claim, attempts 0
        self::assertNotNull($job);
        $id = (int) $job['id'];
        $this->expireLease($id);

        $job = $this->transport->pop('default'); // reclaim → attempts 1
        self::assertSame(1, $job['attempts']);
        $this->expireLease($id);

        $job = $this->transport->pop('default'); // reclaim → attempts 2
        self::assertSame(2, $job['attempts']);
        $this->expireLease($id);

        // A healthy worker now reclaims it a final time (attempts → 3 == tries):
        // the safety net records it failed instead of processing it again.
        $ran = $this->worker->runNextJob('default', new WorkerOptions());

        self::assertTrue($ran);
        self::assertSame(0, CrashingJob::$handleCount, 'an always-crashing job must never have its handler run to completion');
        self::assertTrue(CrashingJob::$failedCalled, 'Job::failed() should fire on exhaustion');
        self::assertCount(1, $this->failedRepo->all(), 'exhausted reclaimed job must land in the failed repository');
        self::assertSame(0, $this->transport->size('default'), 'job must be removed, not reclaimed forever');

        // And it is genuinely gone — a subsequent pop (even after the lease window)
        // finds nothing, proving no infinite reclaim loop.
        self::assertNull($this->transport->pop('default'));
    }

    private function expireLease(int $id): void
    {
        $this->database->update('waaseyaa_queue_jobs')
            ->fields(['reserved_at' => time() - (self::VISIBILITY_TIMEOUT + 60)])
            ->condition('id', $id)
            ->execute();
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
}

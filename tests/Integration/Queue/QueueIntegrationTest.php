<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Queue;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Queue\DbalQueue;
use Waaseyaa\Queue\Handler\JobHandler;
use Waaseyaa\Queue\Storage\DatabaseFailedJobRepository;
use Waaseyaa\Queue\Tests\Unit\Fixtures\FailingJob;
use Waaseyaa\Queue\Tests\Unit\Fixtures\HighPriorityJob;
use Waaseyaa\Queue\Tests\Unit\Fixtures\SuccessfulJob;
use Waaseyaa\Queue\Transport\DbalTransport;
use Waaseyaa\Queue\Worker\Worker;
use Waaseyaa\Queue\Worker\WorkerOptions;

/**
 * Integration tests exercising the full queue pipeline through real SQLite.
 *
 * DbalQueue → DbalTransport → Worker → DatabaseFailedJobRepository
 */
#[CoversNothing]
final class QueueIntegrationTest extends TestCase
{
    private DBALDatabase $database;
    private DbalTransport $transport;
    private DbalQueue $queue;
    private DatabaseFailedJobRepository $failedRepo;
    private Worker $worker;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->createTables();

        $this->transport = new DbalTransport($this->database);
        $this->queue = new DbalQueue($this->transport);
        $this->failedRepo = new DatabaseFailedJobRepository($this->database);
        $this->worker = new Worker(
            $this->transport,
            $this->failedRepo,
            [new JobHandler()],
        );

        SuccessfulJob::reset();
        FailingJob::reset();
    }

    private function createTables(): void
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

        $this->database->schema()->createTable('waaseyaa_failed_jobs', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'queue' => ['type' => 'varchar', 'not null' => true],
                'payload' => ['type' => 'text', 'not null' => true],
                'exception' => ['type' => 'text', 'not null' => true],
                'failed_at' => ['type' => 'varchar', 'not null' => true],
                'retried_at' => ['type' => 'varchar'],
            ],
            'primary key' => ['id'],
        ]);
    }

    // ── Dispatch + Process through durable backend ──

    #[Test]
    public function dispatchAndProcessSuccessfulJob(): void
    {
        $this->queue->dispatch(new SuccessfulJob());

        $this->worker->runNextJob('default', new WorkerOptions());

        self::assertSame(1, SuccessfulJob::$handleCount);
        self::assertSame(0, $this->transport->size('default'));
        self::assertCount(0, $this->failedRepo->all());
    }

    #[Test]
    public function namedQueueRoutingThroughDbalTransport(): void
    {
        $this->queue->dispatch(new HighPriorityJob());

        self::assertSame(1, $this->transport->size('high'));
        self::assertSame(0, $this->transport->size('default'));

        $this->worker->runNextJob('high', new WorkerOptions());

        self::assertSame(0, $this->transport->size('high'));
    }

    // ── Retries through durable backend ──

    #[Test]
    public function retriesFailingJobThroughDbalTransportThenRecordsFailure(): void
    {
        $job = new FailingJob();
        $job->tries = 3;
        $this->queue->dispatch($job);

        $options = new WorkerOptions();

        // Attempt 1 — released for retry
        $this->worker->runNextJob('default', $options);
        self::assertSame(1, $this->transport->size('default'));
        self::assertCount(0, $this->failedRepo->all());

        // Attempt 2 — released for retry
        $this->worker->runNextJob('default', $options);
        self::assertSame(1, $this->transport->size('default'));
        self::assertCount(0, $this->failedRepo->all());

        // Attempt 3 — permanent failure, recorded to failed_jobs table
        $this->worker->runNextJob('default', $options);
        self::assertSame(0, $this->transport->size('default'));
        self::assertCount(1, $this->failedRepo->all());
    }

    #[Test]
    public function failedJobRecordContainsOperatorUsefulInfo(): void
    {
        $job = new FailingJob();
        $job->tries = 1;
        $this->queue->dispatch($job);

        $this->worker->runNextJob('default', new WorkerOptions());

        $failures = $this->failedRepo->all();
        self::assertCount(1, $failures);

        $record = reset($failures);
        self::assertSame('default', $record['queue']);
        self::assertStringContainsString('FailingJob', $record['payload']);
        self::assertStringContainsString('RuntimeException', $record['exception']);
        self::assertNotEmpty($record['failed_at']);
    }

    #[Test]
    public function failedCallbackInvokedOnFinalFailure(): void
    {
        $job = new FailingJob();
        $job->tries = 1;
        $this->queue->dispatch($job);

        $this->worker->runNextJob('default', new WorkerOptions());

        self::assertTrue(FailingJob::$failedCalled);
    }

    // ── Worker run() with durable backend ──

    #[Test]
    public function workerRunProcessesMultipleJobsThroughDbalTransport(): void
    {
        $this->queue->dispatch(new SuccessfulJob());
        $this->queue->dispatch(new SuccessfulJob());
        $this->queue->dispatch(new SuccessfulJob());

        $processed = $this->worker->run('default', new WorkerOptions(maxJobs: 3));

        self::assertSame(3, $processed);
        self::assertSame(3, SuccessfulJob::$handleCount);
        self::assertSame(0, $this->transport->size('default'));
    }

    #[Test]
    public function workerRunMixesSuccessAndFailure(): void
    {
        $this->queue->dispatch(new SuccessfulJob());
        $fail = new FailingJob();
        $fail->tries = 1;
        $this->queue->dispatch($fail);
        $this->queue->dispatch(new SuccessfulJob());

        $processed = $this->worker->run('default', new WorkerOptions(maxJobs: 3));

        self::assertSame(3, $processed);
        self::assertSame(2, SuccessfulJob::$handleCount);
        self::assertCount(1, $this->failedRepo->all());
    }

    // ── Failed job retry through durable backend ──

    #[Test]
    public function retryRestoredFailedJobToQueue(): void
    {
        $job = new FailingJob();
        $job->tries = 1;
        $this->queue->dispatch($job);

        $this->worker->runNextJob('default', new WorkerOptions());
        self::assertCount(1, $this->failedRepo->all());

        $failures = $this->failedRepo->all();
        $failedId = (string) array_key_first($failures);
        $record = $this->failedRepo->retry($failedId);

        self::assertNotNull($record);
        self::assertCount(0, $this->failedRepo->all());
    }
}

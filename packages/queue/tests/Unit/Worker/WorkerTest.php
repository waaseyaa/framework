<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Queue\Handler\JobHandler;
use Waaseyaa\Queue\Storage\InMemoryFailedJobRepository;
use Waaseyaa\Queue\Tests\Unit\Fixtures\FailingJob;
use Waaseyaa\Queue\Tests\Unit\Fixtures\SuccessfulJob;
use Waaseyaa\Queue\Transport\InMemoryTransport;
use Waaseyaa\Queue\Worker\Worker;
use Waaseyaa\Queue\Worker\WorkerOptions;

#[CoversClass(Worker::class)]
#[CoversClass(WorkerOptions::class)]
final class WorkerTest extends TestCase
{
    private InMemoryTransport $transport;
    private InMemoryFailedJobRepository $failedRepo;
    private Worker $worker;

    protected function setUp(): void
    {
        $this->transport = new InMemoryTransport();
        $this->failedRepo = new InMemoryFailedJobRepository();
        $this->worker = new Worker(
            $this->transport,
            $this->failedRepo,
            [new JobHandler()],
        );
        SuccessfulJob::reset();
        FailingJob::reset();
    }

    #[Test]
    public function processesJobSuccessfully(): void
    {
        $this->transport->push('default', serialize(new SuccessfulJob()));

        $result = $this->worker->runNextJob('default', new WorkerOptions());

        self::assertTrue($result);
        self::assertSame(1, SuccessfulJob::$handleCount);
        self::assertSame(0, $this->transport->size('default'));
    }

    #[Test]
    public function returnsFalseWhenNoJobAvailable(): void
    {
        $result = $this->worker->runNextJob('default', new WorkerOptions());

        self::assertFalse($result);
    }

    #[Test]
    public function retriesFailingJobUpToMaxAttempts(): void
    {
        $this->transport->push('default', serialize(new FailingJob()));
        $options = new WorkerOptions();

        // First attempt — should release for retry
        $this->worker->runNextJob('default', $options);
        self::assertSame(1, $this->transport->size('default'));
        self::assertCount(0, $this->failedRepo->all());

        // Second attempt — should release for retry
        $this->worker->runNextJob('default', $options);
        self::assertSame(1, $this->transport->size('default'));
        self::assertCount(0, $this->failedRepo->all());

        // Third attempt — should fail permanently
        $this->worker->runNextJob('default', $options);
        self::assertSame(0, $this->transport->size('default'));
        self::assertCount(1, $this->failedRepo->all());
    }

    #[Test]
    public function recordsCorruptPayloadAsFailure(): void
    {
        $this->transport->push('default', 'not-valid-serialized-data');

        $this->worker->runNextJob('default', new WorkerOptions());

        self::assertCount(1, $this->failedRepo->all());
        self::assertSame(0, $this->transport->size('default'));
    }

    #[Test]
    public function runProcessesMultipleJobsUpToMaxJobs(): void
    {
        $this->transport->push('default', serialize(new SuccessfulJob()));
        $this->transport->push('default', serialize(new SuccessfulJob()));
        $this->transport->push('default', serialize(new SuccessfulJob()));

        $processed = $this->worker->run('default', new WorkerOptions(maxJobs: 2));

        self::assertSame(2, $processed);
        self::assertSame(2, SuccessfulJob::$handleCount);
        self::assertSame(1, $this->transport->size('default'));
    }

    #[Test]
    public function callsFailedCallbackOnFinalFailure(): void
    {
        $job = new FailingJob();
        $job->tries = 1;

        $this->transport->push('default', serialize($job));

        $this->worker->runNextJob('default', new WorkerOptions());

        self::assertTrue(FailingJob::$failedCalled);
    }

    #[Test]
    public function stopCausesWorkerToExitAfterCurrentJob(): void
    {
        $this->transport->push('default', serialize(new SuccessfulJob()));
        $this->transport->push('default', serialize(new SuccessfulJob()));
        $this->transport->push('default', serialize(new SuccessfulJob()));

        // Request stop before run — worker should process zero jobs
        $this->worker->stop();

        $processed = $this->worker->run('default', new WorkerOptions(maxJobs: 10));

        self::assertSame(0, $processed);
        self::assertSame(3, $this->transport->size('default'));
    }
}

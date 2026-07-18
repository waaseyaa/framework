<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Queue\Envelope\QueueAuthorityRuntimeInterface;
use Waaseyaa\Queue\Envelope\QueueEnvelopeV1;
use Waaseyaa\Queue\Envelope\QueueSystemReason;
use Waaseyaa\Queue\Handler\HandlerInterface;
use Waaseyaa\Queue\Handler\JobHandler;
use Waaseyaa\Queue\PersistentQueueBoundaryConfig;
use Waaseyaa\Queue\Security\SignedQueuePayload;
use Waaseyaa\Queue\Storage\InMemoryFailedJobRepository;
use Waaseyaa\Queue\Tests\Unit\Fixtures\FailingJob;
use Waaseyaa\Queue\Tests\Unit\Fixtures\SuccessfulJob;
use Waaseyaa\Queue\Tests\Unit\Fixtures\ThrowingFailureHookJob;
use Waaseyaa\Queue\Transport\InMemoryTransport;
use Waaseyaa\Queue\Worker\Worker;
use Waaseyaa\Queue\Worker\WorkerOptions;

#[CoversClass(Worker::class)]
#[CoversClass(WorkerOptions::class)]
final class WorkerTest extends TestCase
{
    public function test_activation_refuses_no_authority_runtime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Worker(
            $this->transport,
            $this->failedRepo,
            [],
            $this->signer,
            boundaryConfig: PersistentQueueBoundaryConfig::enforced(),
        );
    }

    public function test_activation_rejects_legacy_payload_before_handler_execution(): void
    {
        $handled = false;
        $runtime = new class implements QueueAuthorityRuntimeInterface {
            public function run(QueueEnvelopeV1 $envelope, \Closure $handler): mixed
            {
                return $handler();
            }
        };
        $handler = new class ($handled) implements HandlerInterface {
            public function __construct(private bool &$handled) {}
            public function supports(object $message): bool
            {
                return true;
            }
            public function handle(object $message): void
            {
                $this->handled = true;
            }
        };
        $worker = new Worker(
            $this->transport,
            $this->failedRepo,
            [$handler],
            $this->signer,
            authorityRuntime: $runtime,
            boundaryConfig: PersistentQueueBoundaryConfig::enforced(),
        );
        $this->transport->push('default', $this->signer->seal(serialize(new SuccessfulJob())));

        $worker->runNextJob('default', new WorkerOptions());

        self::assertFalse($handled);
        self::assertCount(1, $this->failedRepo->all());
        self::assertSame(0, $this->transport->size('default'));
    }

    private InMemoryTransport $transport;
    private InMemoryFailedJobRepository $failedRepo;
    private Worker $worker;
    private SignedQueuePayload $signer;

    protected function setUp(): void
    {
        $this->transport = new InMemoryTransport();
        $this->failedRepo = new InMemoryFailedJobRepository();
        $this->signer = new SignedQueuePayload(str_repeat('q', 32));
        $this->worker = new Worker(
            $this->transport,
            $this->failedRepo,
            [new JobHandler()],
            $this->signer,
        );
        SuccessfulJob::reset();
        FailingJob::reset();
    }

    #[Test]
    public function processesJobSuccessfully(): void
    {
        $this->transport->push('default', $this->signer->seal(serialize(new SuccessfulJob())));

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
        $this->transport->push('default', $this->signer->seal(serialize(new FailingJob())));
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
        $this->transport->push('default', $this->signer->seal(serialize(new SuccessfulJob())));
        $this->transport->push('default', $this->signer->seal(serialize(new SuccessfulJob())));
        $this->transport->push('default', $this->signer->seal(serialize(new SuccessfulJob())));

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

        $this->transport->push('default', $this->signer->seal(serialize($job)));

        $this->worker->runNextJob('default', new WorkerOptions());

        self::assertTrue(FailingJob::$failedCalled);
    }

    #[Test]
    public function stopCausesWorkerToExitAfterCurrentJob(): void
    {
        $this->transport->push('default', $this->signer->seal(serialize(new SuccessfulJob())));
        $this->transport->push('default', $this->signer->seal(serialize(new SuccessfulJob())));
        $this->transport->push('default', $this->signer->seal(serialize(new SuccessfulJob())));

        // Request stop before run — worker should process zero jobs
        $this->worker->stop();

        $processed = $this->worker->run('default', new WorkerOptions(maxJobs: 10));

        self::assertSame(0, $processed);
        self::assertSame(3, $this->transport->size('default'));
    }

    #[Test]
    public function logsWhenAJobFailureHookThrows(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'queue.failure_hook_failed',
                $this->callback(static fn(array $context): bool => $context['exception'] instanceof \LogicException),
            );
        $worker = new Worker(
            $this->transport,
            $this->failedRepo,
            [new JobHandler()],
            $this->signer,
            $logger,
        );
        $this->transport->push('default', $this->signer->seal(serialize(new ThrowingFailureHookJob())));

        $worker->runNextJob('default', new WorkerOptions());

        self::assertCount(1, $this->failedRepo->all());
    }

    #[Test]
    public function envelopeAuthorityExistsOnlyDuringHandlerExecution(): void
    {
        $active = false;
        $runtime = new class ($active) implements QueueAuthorityRuntimeInterface {
            public function __construct(private bool &$active) {}
            public function run(QueueEnvelopeV1 $envelope, \Closure $handler): mixed
            {
                $this->active = true;
                try {
                    return $handler();
                } finally {
                    $this->active = false;
                }
            }
        };
        $handler = new class ($active) implements HandlerInterface {
            public function __construct(private bool &$active) {}
            public function supports(object $message): bool
            {
                return $message instanceof SuccessfulJob;
            }
            public function handle(object $message): void
            {
                TestCase::assertTrue($this->active);
            }
        };
        $worker = new Worker($this->transport, $this->failedRepo, [$handler], $this->signer, authorityRuntime: $runtime);
        $envelope = QueueEnvelopeV1::forSystem(
            serialize(new SuccessfulJob()),
            QueueSystemReason::SystemJob,
            'worker-test',
            null,
            null,
            'job-17',
        );
        $this->transport->push('default', $this->signer->seal(serialize($envelope)));

        $worker->runNextJob('default', new WorkerOptions());

        self::assertFalse($active);
        self::assertSame(0, $this->transport->size('default'));
    }
}

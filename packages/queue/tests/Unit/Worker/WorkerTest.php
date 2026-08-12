<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Runtime\RuntimeEpochInterface;
use Waaseyaa\Foundation\Runtime\StableRuntimeEpoch;
use Waaseyaa\Queue\Envelope\QueueAuthorityRuntimeInterface;
use Waaseyaa\Queue\Envelope\QueueEnvelopeV1;
use Waaseyaa\Queue\Envelope\QueueOccurrenceV1;
use Waaseyaa\Queue\Envelope\QueueSystemReason;
use Waaseyaa\Queue\Handler\HandlerInterface;
use Waaseyaa\Queue\Handler\JobHandler;
use Waaseyaa\Queue\PersistentQueueBoundaryConfig;
use Waaseyaa\Queue\Occurrence\OccurrenceContextInterface;
use Waaseyaa\Queue\Occurrence\OccurrenceRunResult;
use Waaseyaa\Queue\Occurrence\OccurrenceRuntimeInterface;
use Waaseyaa\Queue\Security\SignedQueuePayload;
use Waaseyaa\Queue\Storage\InMemoryFailedJobRepository;
use Waaseyaa\Queue\Tests\Unit\Fixtures\FailingJob;
use Waaseyaa\Queue\Tests\Unit\Fixtures\FailingOccurrenceAwareJob;
use Waaseyaa\Queue\Tests\Unit\Fixtures\OccurrenceAwareJob;
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

    public function test_activation_refuses_no_runtime_epoch_authority(): void
    {
        $runtime = new class implements QueueAuthorityRuntimeInterface {
            public function run(QueueEnvelopeV1 $envelope, \Closure $handler): mixed { return $handler(); }
        };
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('runtime epoch authority');
        new Worker(
            $this->transport,
            $this->failedRepo,
            [],
            $this->signer,
            authorityRuntime: $runtime,
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
            runtimeEpoch: new StableRuntimeEpoch(),
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
        OccurrenceAwareJob::$handleCount = 0;
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
    public function changedRuntimeEpochRefusesAJobBeforeItIsClaimed(): void
    {
        $epoch = new class implements RuntimeEpochInterface {
            public function hasChanged(): bool { return true; }
            public function fingerprint(): string { return 'test:changed'; }
        };
        $worker = new Worker(
            $this->transport,
            $this->failedRepo,
            [new JobHandler()],
            $this->signer,
            runtimeEpoch: $epoch,
        );
        $this->transport->push('default', $this->signer->seal(serialize(new SuccessfulJob())));

        self::assertFalse($worker->runNextJob('default', new WorkerOptions()));
        self::assertSame(1, $this->transport->size('default'));
        self::assertSame(0, SuccessfulJob::$handleCount);
    }

    #[Test]
    public function epochChangeDuringAJobCompletesThatUnitThenDrainsBeforeTheNext(): void
    {
        $epoch = new class implements RuntimeEpochInterface {
            public bool $changed = false;
            public function hasChanged(): bool { return $this->changed; }
            public function fingerprint(): string { return 'test:' . ($this->changed ? 'changed' : 'initial'); }
        };
        $handled = 0;
        $handler = new class ($epoch, $handled) implements HandlerInterface {
            public function __construct(
                private readonly object $epoch,
                private int &$handled,
            ) {}
            public function supports(object $message): bool { return true; }
            public function handle(object $message): void
            {
                ++$this->handled;
                $this->epoch->changed = true;
            }
        };
        $worker = new Worker(
            $this->transport,
            $this->failedRepo,
            [$handler],
            $this->signer,
            runtimeEpoch: $epoch,
        );
        $this->transport->push('default', $this->signer->seal(serialize(new SuccessfulJob())));
        $this->transport->push('default', $this->signer->seal(serialize(new SuccessfulJob())));

        $processed = $worker->run('default', new WorkerOptions(sleep: 0, maxJobs: 2));

        self::assertSame(1, $processed);
        self::assertSame(1, $handled);
        self::assertSame(1, $this->transport->size('default'));
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

    #[Test]
    public function occurrenceRuntimeOwnsExecutionAndAcknowledgesCompletion(): void
    {
        $runtime = self::occurrenceRuntime(OccurrenceRunResult::Executed);
        $worker = new Worker(
            $this->transport,
            $this->failedRepo,
            [],
            $this->signer,
            authorityRuntime: self::authorityRuntime(),
            occurrenceRuntime: $runtime,
        );
        $this->pushOccurrence(new OccurrenceAwareJob());

        $worker->runNextJob('default', new WorkerOptions());

        self::assertSame(1, OccurrenceAwareJob::$handleCount);
        self::assertSame(0, $this->transport->size('default'));
        self::assertSame([], $this->transport->getReserved());
    }

    #[Test]
    public function duplicateOccurrenceIsAcknowledgedWithoutExecutingMessage(): void
    {
        $worker = new Worker(
            $this->transport,
            $this->failedRepo,
            [],
            $this->signer,
            authorityRuntime: self::authorityRuntime(),
            occurrenceRuntime: self::occurrenceRuntime(OccurrenceRunResult::Duplicate),
        );
        $this->pushOccurrence(new OccurrenceAwareJob());

        $worker->runNextJob('default', new WorkerOptions());

        self::assertSame(0, OccurrenceAwareJob::$handleCount);
        self::assertSame(0, $this->transport->size('default'));
    }

    #[Test]
    public function contendedOccurrenceIsDeferredWithoutConsumingAttempt(): void
    {
        $worker = new Worker(
            $this->transport,
            $this->failedRepo,
            [],
            $this->signer,
            authorityRuntime: self::authorityRuntime(),
            occurrenceRuntime: self::occurrenceRuntime(OccurrenceRunResult::Contended),
        );
        $this->pushOccurrence(new OccurrenceAwareJob());

        $worker->runNextJob('default', new WorkerOptions());

        $rows = $this->transport->listJobs(10);
        self::assertSame(1, $rows['total']);
        self::assertSame(0, $rows['data'][0]['attempts']);
        self::assertSame(0, OccurrenceAwareJob::$handleCount);
    }

    #[Test]
    public function finalFailureDeadLettersOccurrenceBeforeRejectingDelivery(): void
    {
        $deadLettered = false;
        $runtime = new class ($deadLettered) implements OccurrenceRuntimeInterface {
            public function __construct(private bool &$deadLettered) {}
            public function run(QueueOccurrenceV1 $occurrence, callable $execute): OccurrenceRunResult
            {
                $execute(new class implements OccurrenceContextInterface {
                    public function occurrenceId(): string { return str_repeat('a', 64); }
                    public function fence(): int { return 1; }
                    public function checkpoint(): void {}
                    public function effect(string $resource, string $effectId, callable $effect): mixed { return $effect(); }
                });

                return OccurrenceRunResult::Executed;
            }
            public function deadLetter(QueueOccurrenceV1 $occurrence, string $failureClass): bool
            {
                $this->deadLettered = true;

                return true;
            }
        };
        $worker = new Worker(
            $this->transport,
            $this->failedRepo,
            [],
            $this->signer,
            authorityRuntime: self::authorityRuntime(),
            occurrenceRuntime: $runtime,
        );
        $this->pushOccurrence(new FailingOccurrenceAwareJob());

        $worker->runNextJob('default', new WorkerOptions(maxTries: 1));

        self::assertTrue($deadLettered);
        self::assertCount(1, $this->failedRepo->all());
        self::assertSame([], $this->transport->getReserved());
    }

    private function pushOccurrence(object $message): void
    {
        $envelope = QueueEnvelopeV1::forSystem(
            serialize($message),
            QueueSystemReason::Scheduler,
            'scheduler',
            null,
            null,
            'queue-occurrence',
            new QueueOccurrenceV1(str_repeat('a', 64), 'retention', str_repeat('b', 64), 300_000),
        );
        $this->transport->push('default', $this->signer->seal(serialize($envelope)));
    }

    private static function authorityRuntime(): QueueAuthorityRuntimeInterface
    {
        return new class implements QueueAuthorityRuntimeInterface {
            public function run(QueueEnvelopeV1 $envelope, \Closure $handler): mixed
            {
                return $handler();
            }
        };
    }

    private static function occurrenceRuntime(OccurrenceRunResult $result): OccurrenceRuntimeInterface
    {
        return new class ($result) implements OccurrenceRuntimeInterface {
            public function __construct(private readonly OccurrenceRunResult $result) {}

            public function run(QueueOccurrenceV1 $occurrence, callable $execute): OccurrenceRunResult
            {
                if ($this->result === OccurrenceRunResult::Executed) {
                    $execute(new class implements OccurrenceContextInterface {
                        public function occurrenceId(): string { return str_repeat('a', 64); }
                        public function fence(): int { return 1; }
                        public function checkpoint(): void {}
                        public function effect(string $resource, string $effectId, callable $effect): mixed
                        {
                            return $effect();
                        }
                    });
                }

                return $this->result;
            }

            public function deadLetter(QueueOccurrenceV1 $occurrence, string $failureClass): bool
            {
                return true;
            }
        };
    }
}

<?php

declare(strict_types=1);

namespace Aurora\Queue\Tests\Unit;

use Aurora\Queue\BatchedJobs;
use Aurora\Queue\Job;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BatchedJobs::class)]
final class BatchedJobsTest extends TestCase
{
    #[Test]
    public function executesAllJobsOnSuccess(): void
    {
        $order = [];

        $job1 = $this->createTrackingJob($order, 'first');
        $job2 = $this->createTrackingJob($order, 'second');

        $batch = new BatchedJobs([$job1, $job2]);
        $batch->handle();

        $this->assertSame(['first', 'second'], $order);
    }

    #[Test]
    public function thenCallbackCalledOnAllSuccess(): void
    {
        $thenCalled = false;

        $batch = new BatchedJobs([
            $this->createSimpleJob(),
            $this->createSimpleJob(),
        ]);

        $batch->then(function () use (&$thenCalled): void {
            $thenCalled = true;
        });

        $batch->handle();

        $this->assertTrue($thenCalled);
    }

    #[Test]
    public function thenCallbackNotCalledOnFailure(): void
    {
        $thenCalled = false;

        $batch = new BatchedJobs([
            $this->createFailingJob(new \RuntimeException('fail')),
        ]);

        $batch->then(function () use (&$thenCalled): void {
            $thenCalled = true;
        });

        try {
            $batch->handle();
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertFalse($thenCalled);
    }

    #[Test]
    public function catchCallbackCalledOnFailure(): void
    {
        $caughtFailures = null;

        $batch = new BatchedJobs([
            $this->createFailingJob(new \RuntimeException('boom')),
        ]);

        $batch->catch(function (array $failures) use (&$caughtFailures): void {
            $caughtFailures = $failures;
        });

        try {
            $batch->handle();
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertNotNull($caughtFailures);
        $this->assertCount(1, $caughtFailures);
        $this->assertSame('boom', $caughtFailures[0]->getMessage());
    }

    #[Test]
    public function catchCallbackNotCalledOnSuccess(): void
    {
        $catchCalled = false;

        $batch = new BatchedJobs([$this->createSimpleJob()]);

        $batch->catch(function () use (&$catchCalled): void {
            $catchCalled = true;
        });

        $batch->handle();

        $this->assertFalse($catchCalled);
    }

    #[Test]
    public function finallyCallbackAlwaysCalledOnSuccess(): void
    {
        $finallyCalled = false;

        $batch = new BatchedJobs([$this->createSimpleJob()]);

        $batch->finally(function () use (&$finallyCalled): void {
            $finallyCalled = true;
        });

        $batch->handle();

        $this->assertTrue($finallyCalled);
    }

    #[Test]
    public function finallyCallbackAlwaysCalledOnFailure(): void
    {
        $finallyCalled = false;

        $batch = new BatchedJobs([
            $this->createFailingJob(new \RuntimeException('fail')),
        ]);

        $batch->finally(function () use (&$finallyCalled): void {
            $finallyCalled = true;
        });

        try {
            $batch->handle();
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertTrue($finallyCalled);
    }

    #[Test]
    public function stopsOnFailureByDefault(): void
    {
        $order = [];

        $batch = new BatchedJobs([
            $this->createTrackingJob($order, 'first'),
            $this->createFailingJob(new \RuntimeException('fail')),
            $this->createTrackingJob($order, 'third'),
        ]);

        try {
            $batch->handle();
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertSame(['first'], $order);
    }

    #[Test]
    public function rethrowsFirstExceptionWhenNotAllowingFailures(): void
    {
        $batch = new BatchedJobs([
            $this->createFailingJob(new \RuntimeException('first-error')),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('first-error');

        $batch->handle();
    }

    #[Test]
    public function allowFailuresContinuesAfterErrors(): void
    {
        $order = [];

        $batch = new BatchedJobs([
            $this->createTrackingJob($order, 'first'),
            $this->createFailingJob(new \RuntimeException('fail')),
            $this->createTrackingJob($order, 'third'),
        ]);

        $batch->allowFailures();
        $batch->handle();

        $this->assertSame(['first', 'third'], $order);
    }

    #[Test]
    public function allowFailuresDoesNotRethrow(): void
    {
        $batch = new BatchedJobs([
            $this->createFailingJob(new \RuntimeException('fail')),
        ]);

        $batch->allowFailures();

        // Should not throw.
        $batch->handle();

        $this->assertTrue(true);
    }

    #[Test]
    public function allowFailuresStillCallsCatchCallbacks(): void
    {
        $caughtFailures = null;

        $batch = new BatchedJobs([
            $this->createFailingJob(new \RuntimeException('err1')),
            $this->createFailingJob(new \LogicException('err2')),
        ]);

        $batch->allowFailures();
        $batch->catch(function (array $failures) use (&$caughtFailures): void {
            $caughtFailures = $failures;
        });

        $batch->handle();

        $this->assertNotNull($caughtFailures);
        $this->assertCount(2, $caughtFailures);
    }

    #[Test]
    public function multipleCallbacksCanBeRegistered(): void
    {
        $calls = [];

        $batch = new BatchedJobs([$this->createSimpleJob()]);

        $batch->then(function () use (&$calls): void {
            $calls[] = 'then1';
        });
        $batch->then(function () use (&$calls): void {
            $calls[] = 'then2';
        });
        $batch->finally(function () use (&$calls): void {
            $calls[] = 'finally1';
        });

        $batch->handle();

        $this->assertSame(['then1', 'then2', 'finally1'], $calls);
    }

    #[Test]
    public function isAllowingFailuresReturnsFalseByDefault(): void
    {
        $batch = new BatchedJobs([]);

        $this->assertFalse($batch->isAllowingFailures());
    }

    #[Test]
    public function isAllowingFailuresReturnsTrueAfterCalled(): void
    {
        $batch = new BatchedJobs([]);
        $batch->allowFailures();

        $this->assertTrue($batch->isAllowingFailures());
    }

    #[Test]
    public function getJobsReturnsTheJobs(): void
    {
        $job1 = $this->createSimpleJob();
        $job2 = $this->createSimpleJob();

        $batch = new BatchedJobs([$job1, $job2]);

        $this->assertSame([$job1, $job2], $batch->getJobs());
    }

    #[Test]
    public function emptyBatchHandlesSuccessfully(): void
    {
        $thenCalled = false;

        $batch = new BatchedJobs([]);
        $batch->then(function () use (&$thenCalled): void {
            $thenCalled = true;
        });

        $batch->handle();

        $this->assertTrue($thenCalled);
    }

    #[Test]
    public function callsFailedOnEachFailingJob(): void
    {
        $failedJobs = [];

        $failingJob1 = new class ($failedJobs) extends Job {
            public function __construct(private array &$failed) {}

            public function handle(): void
            {
                throw new \RuntimeException('err1');
            }

            public function failed(\Throwable $e): void
            {
                $this->failed[] = $e->getMessage();
            }
        };

        $failingJob2 = new class ($failedJobs) extends Job {
            public function __construct(private array &$failed) {}

            public function handle(): void
            {
                throw new \RuntimeException('err2');
            }

            public function failed(\Throwable $e): void
            {
                $this->failed[] = $e->getMessage();
            }
        };

        $batch = new BatchedJobs([$failingJob1, $failingJob2]);
        $batch->allowFailures();
        $batch->handle();

        $this->assertSame(['err1', 'err2'], $failedJobs);
    }

    #[Test]
    public function incrementsAttemptCounterOnEachJob(): void
    {
        $job1 = $this->createSimpleJob();
        $job2 = $this->createSimpleJob();

        $batch = new BatchedJobs([$job1, $job2]);
        $batch->handle();

        $this->assertSame(1, $job1->getAttempts());
        $this->assertSame(1, $job2->getAttempts());
    }

    #[Test]
    public function fluentInterface(): void
    {
        $batch = new BatchedJobs([]);

        $result = $batch
            ->then(function (): void {})
            ->catch(function (): void {})
            ->finally(function (): void {})
            ->allowFailures();

        $this->assertSame($batch, $result);
    }

    private function createSimpleJob(): Job
    {
        return new class extends Job {
            public function handle(): void {}
        };
    }

    /**
     * @param string[] $order
     */
    private function createTrackingJob(array &$order, string $label): Job
    {
        return new class ($order, $label) extends Job {
            public function __construct(
                private array &$log,
                private readonly string $label,
            ) {}

            public function handle(): void
            {
                $this->log[] = $this->label;
            }
        };
    }

    private function createFailingJob(\Throwable $e): Job
    {
        return new class ($e) extends Job {
            public function __construct(private readonly \Throwable $e) {}

            public function handle(): void
            {
                throw $this->e;
            }
        };
    }
}

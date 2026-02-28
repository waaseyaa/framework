<?php

declare(strict_types=1);

namespace Aurora\Queue\Tests\Unit;

use Aurora\Queue\ChainedJobs;
use Aurora\Queue\Job;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChainedJobs::class)]
final class ChainedJobsTest extends TestCase
{
    #[Test]
    public function executesAllJobsInOrder(): void
    {
        $order = [];

        $job1 = $this->createTrackingJob($order, 'first');
        $job2 = $this->createTrackingJob($order, 'second');
        $job3 = $this->createTrackingJob($order, 'third');

        $chain = new ChainedJobs([$job1, $job2, $job3]);
        $chain->handle();

        $this->assertSame(['first', 'second', 'third'], $order);
    }

    #[Test]
    public function stopsOnFirstFailure(): void
    {
        $order = [];

        $job1 = $this->createTrackingJob($order, 'first');
        $job2 = $this->createFailingJob(new \RuntimeException('fail'));
        $job3 = $this->createTrackingJob($order, 'third');

        $chain = new ChainedJobs([$job1, $job2, $job3]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fail');

        $chain->handle();
    }

    #[Test]
    public function callsFailedOnTheFailingJob(): void
    {
        $failedException = null;
        $exception = new \RuntimeException('oops');

        $failingJob = new class ($exception, $failedException) extends Job {
            public function __construct(
                private readonly \RuntimeException $ex,
                private ?\Throwable &$captured,
            ) {}

            public function handle(): void
            {
                throw $this->ex;
            }

            public function failed(\Throwable $e): void
            {
                $this->captured = $e;
            }
        };

        $chain = new ChainedJobs([$failingJob]);

        try {
            $chain->handle();
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertSame($exception, $failedException);
    }

    #[Test]
    public function doesNotExecuteJobsAfterFailure(): void
    {
        $executed = false;

        $job1 = $this->createFailingJob(new \RuntimeException('fail'));
        $job2 = new class ($executed) extends Job {
            public function __construct(private bool &$ran) {}

            public function handle(): void
            {
                $this->ran = true;
            }
        };

        $chain = new ChainedJobs([$job1, $job2]);

        try {
            $chain->handle();
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertFalse($executed);
    }

    #[Test]
    public function getJobsReturnsTheJobs(): void
    {
        $job1 = $this->createTrackingJob($unused, 'a');
        $job2 = $this->createTrackingJob($unused, 'b');

        $chain = new ChainedJobs([$job1, $job2]);

        $this->assertSame([$job1, $job2], $chain->getJobs());
    }

    #[Test]
    public function emptyChainHandlesWithoutError(): void
    {
        $chain = new ChainedJobs([]);
        $chain->handle();

        $this->assertSame([], $chain->getJobs());
    }

    #[Test]
    public function incrementsAttemptCounterOnEachJob(): void
    {
        $job1 = new class extends Job {
            public function handle(): void {}
        };
        $job2 = new class extends Job {
            public function handle(): void {}
        };

        $chain = new ChainedJobs([$job1, $job2]);
        $chain->handle();

        $this->assertSame(1, $job1->getAttempts());
        $this->assertSame(1, $job2->getAttempts());
    }

    /**
     * @param string[] $order
     */
    private function createTrackingJob(?array &$order, string $label): Job
    {
        $order ??= [];

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

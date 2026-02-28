<?php

declare(strict_types=1);

namespace Aurora\Queue\Tests\Unit;

use Aurora\Queue\Job;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Job::class)]
final class JobTest extends TestCase
{
    #[Test]
    public function defaultProperties(): void
    {
        $job = new class extends Job {
            public function handle(): void {}
        };

        $this->assertSame(1, $job->tries);
        $this->assertSame(60, $job->timeout);
        $this->assertSame(0, $job->retryAfter);
    }

    #[Test]
    public function customProperties(): void
    {
        $job = new class extends Job {
            public int $tries = 5;
            public int $timeout = 120;
            public int $retryAfter = 30;

            public function handle(): void {}
        };

        $this->assertSame(5, $job->tries);
        $this->assertSame(120, $job->timeout);
        $this->assertSame(30, $job->retryAfter);
    }

    #[Test]
    public function handleIsCalledSuccessfully(): void
    {
        $called = false;
        $job = new class ($called) extends Job {
            public function __construct(private bool &$called) {}

            public function handle(): void
            {
                $this->called = true;
            }
        };

        $job->handle();
        $this->assertTrue($called);
    }

    #[Test]
    public function failedMethodDefaultIsNoOp(): void
    {
        $job = new class extends Job {
            public function handle(): void {}
        };

        // Should not throw.
        $job->failed(new \RuntimeException('test'));
        $this->assertTrue(true);
    }

    #[Test]
    public function failedMethodCanBeOverridden(): void
    {
        $caughtException = null;
        $job = new class ($caughtException) extends Job {
            public function __construct(private ?\Throwable &$caught) {}

            public function handle(): void {}

            public function failed(\Throwable $e): void
            {
                $this->caught = $e;
            }
        };

        $exception = new \RuntimeException('Something broke');
        $job->failed($exception);

        $this->assertSame($exception, $caughtException);
    }

    #[Test]
    public function releaseMarksJobAsReleased(): void
    {
        $job = new class extends Job {
            public function handle(): void {}
        };

        $this->assertFalse($job->isReleased());
        $this->assertSame(0, $job->getReleaseDelay());

        $job->release(30);

        $this->assertTrue($job->isReleased());
        $this->assertSame(30, $job->getReleaseDelay());
    }

    #[Test]
    public function releaseWithZeroDelay(): void
    {
        $job = new class extends Job {
            public function handle(): void {}
        };

        $job->release();

        $this->assertTrue($job->isReleased());
        $this->assertSame(0, $job->getReleaseDelay());
    }

    #[Test]
    public function attemptCounterStartsAtZero(): void
    {
        $job = new class extends Job {
            public function handle(): void {}
        };

        $this->assertSame(0, $job->getAttempts());
    }

    #[Test]
    public function incrementAttemptsReturnsNewCount(): void
    {
        $job = new class extends Job {
            public function handle(): void {}
        };

        $this->assertSame(1, $job->incrementAttempts());
        $this->assertSame(2, $job->incrementAttempts());
        $this->assertSame(2, $job->getAttempts());
    }

    #[Test]
    public function hasExceededMaxAttemptsReturnsFalseInitially(): void
    {
        $job = new class extends Job {
            public int $tries = 3;
            public function handle(): void {}
        };

        $this->assertFalse($job->hasExceededMaxAttempts());
    }

    #[Test]
    public function hasExceededMaxAttemptsReturnsTrueAfterEnoughAttempts(): void
    {
        $job = new class extends Job {
            public int $tries = 2;
            public function handle(): void {}
        };

        $job->incrementAttempts();
        $this->assertFalse($job->hasExceededMaxAttempts());

        $job->incrementAttempts();
        $this->assertTrue($job->hasExceededMaxAttempts());
    }
}

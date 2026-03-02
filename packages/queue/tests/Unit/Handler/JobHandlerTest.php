<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit\Handler;

use Waaseyaa\Foundation\Middleware\JobMiddlewareInterface;
use Waaseyaa\Foundation\Middleware\JobHandlerInterface;
use Waaseyaa\Foundation\Middleware\JobPipeline;
use Waaseyaa\Queue\Handler\HandlerInterface;
use Waaseyaa\Queue\Handler\JobHandler;
use Waaseyaa\Queue\Job;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JobHandler::class)]
final class JobHandlerTest extends TestCase
{
    #[Test]
    public function implementsHandlerInterface(): void
    {
        $this->assertInstanceOf(HandlerInterface::class, new JobHandler());
    }

    #[Test]
    public function supportsReturnsTrueForJobInstances(): void
    {
        $handler = new JobHandler();
        $job = new class extends Job {
            public function handle(): void {}
        };

        $this->assertTrue($handler->supports($job));
    }

    #[Test]
    public function supportsReturnsFalseForNonJobObjects(): void
    {
        $handler = new JobHandler();

        $this->assertFalse($handler->supports(new \stdClass()));
        $this->assertFalse($handler->supports(new \RuntimeException('x')));
    }

    #[Test]
    public function handleCallsJobHandle(): void
    {
        $called = false;
        $job = new class ($called) extends Job {
            public function __construct(private bool &$called) {}

            public function handle(): void
            {
                $this->called = true;
            }
        };

        (new JobHandler())->handle($job);

        $this->assertTrue($called);
    }

    #[Test]
    public function handleIncrementsAttemptCounter(): void
    {
        $job = new class extends Job {
            public function handle(): void {}
        };

        $this->assertSame(0, $job->getAttempts());

        (new JobHandler())->handle($job);

        $this->assertSame(1, $job->getAttempts());
    }

    #[Test]
    public function handleIncrementsAttemptsBeforeCallingHandle(): void
    {
        $attemptsAtHandleTime = null;
        $job = new class ($attemptsAtHandleTime) extends Job {
            public function __construct(private ?int &$recorded) {}

            public function handle(): void
            {
                $this->recorded = $this->getAttempts();
            }
        };

        (new JobHandler())->handle($job);

        // incrementAttempts() runs before handle(), so the counter must be 1
        // when handle() executes.
        $this->assertSame(1, $attemptsAtHandleTime);
    }

    #[Test]
    public function handles_job_through_pipeline(): void
    {
        $pipelineUsed = false;
        $jobHandled = false;

        $mw = new class($pipelineUsed) implements JobMiddlewareInterface {
            public function __construct(private bool &$used) {}
            public function process(Job $job, JobHandlerInterface $next): void
            {
                $this->used = true;
                $next->handle($job);
            }
        };

        $pipeline = new JobPipeline([$mw]);

        $job = new class($jobHandled) extends Job {
            public function __construct(private bool &$handled) {}
            public function handle(): void
            {
                $this->handled = true;
            }
        };

        $handler = new JobHandler(pipeline: $pipeline);
        $handler->handle($job);

        $this->assertTrue($pipelineUsed);
        $this->assertTrue($jobHandled);
        $this->assertSame(1, $job->getAttempts());
    }

    #[Test]
    public function increments_attempts_before_pipeline(): void
    {
        $attemptsDuringPipeline = 0;

        $mw = new class($attemptsDuringPipeline) implements JobMiddlewareInterface {
            public function __construct(private int &$attempts) {}
            public function process(Job $job, JobHandlerInterface $next): void
            {
                $this->attempts = $job->getAttempts();
                $next->handle($job);
            }
        };

        $pipeline = new JobPipeline([$mw]);
        $job = new class extends Job {
            public function handle(): void {}
        };

        $handler = new JobHandler(pipeline: $pipeline);
        $handler->handle($job);

        $this->assertSame(1, $attemptsDuringPipeline);
    }
}

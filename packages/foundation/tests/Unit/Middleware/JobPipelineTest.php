<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Middleware;

use Waaseyaa\Foundation\Middleware\JobMiddlewareInterface;
use Waaseyaa\Foundation\Middleware\JobNextHandlerInterface;
use Waaseyaa\Foundation\Middleware\JobPipeline;
use Waaseyaa\Queue\Job;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JobPipeline::class)]
final class JobPipelineTest extends TestCase
{
    #[Test]
    public function empty_pipeline_delegates_to_final_handler(): void
    {
        $handled = false;
        $pipeline = new JobPipeline();

        $handler = new class($handled) implements JobNextHandlerInterface {
            public function __construct(private bool &$handled) {}
            public function handle(Job $job): void
            {
                $this->handled = true;
            }
        };

        $pipeline->handle($this->createJob(), $handler);
        $this->assertTrue($handled);
    }

    #[Test]
    public function middleware_wraps_in_onion_order(): void
    {
        $log = [];

        $mw1 = new class($log) implements JobMiddlewareInterface {
            public function __construct(private array &$log) {}
            public function process(Job $job, JobNextHandlerInterface $next): void
            {
                $this->log[] = 'mw1-before';
                $next->handle($job);
                $this->log[] = 'mw1-after';
            }
        };

        $mw2 = new class($log) implements JobMiddlewareInterface {
            public function __construct(private array &$log) {}
            public function process(Job $job, JobNextHandlerInterface $next): void
            {
                $this->log[] = 'mw2-before';
                $next->handle($job);
                $this->log[] = 'mw2-after';
            }
        };

        $handler = new class($log) implements JobNextHandlerInterface {
            public function __construct(private array &$log) {}
            public function handle(Job $job): void
            {
                $this->log[] = 'handler';
            }
        };

        $pipeline = new JobPipeline([$mw1, $mw2]);
        $pipeline->handle($this->createJob(), $handler);

        $this->assertSame(['mw1-before', 'mw2-before', 'handler', 'mw2-after', 'mw1-after'], $log);
    }

    #[Test]
    public function middleware_can_skip_job(): void
    {
        $reached = false;

        $skip = new class implements JobMiddlewareInterface {
            public function process(Job $job, JobNextHandlerInterface $next): void
            {
                // Don't call next — skip the job
            }
        };

        $handler = new class($reached) implements JobNextHandlerInterface {
            public function __construct(private bool &$reached) {}
            public function handle(Job $job): void
            {
                $this->reached = true;
            }
        };

        $pipeline = new JobPipeline([$skip]);
        $pipeline->handle($this->createJob(), $handler);
        $this->assertFalse($reached);
    }

    private function createJob(): Job
    {
        return new class extends Job {
            public function handle(): void {}
        };
    }
}

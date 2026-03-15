<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Middleware;

use Waaseyaa\Queue\Job;

final class JobPipeline
{
    /** @param JobMiddlewareInterface[] $middleware */
    public function __construct(
        private readonly array $middleware = [],
    ) {}

    public function withMiddleware(JobMiddlewareInterface $middleware): self
    {
        return new self([...$this->middleware, $middleware]);
    }

    public function handle(Job $job, JobHandlerInterface $finalHandler): void
    {
        if ($this->middleware === []) {
            $finalHandler->handle($job);
            return;
        }

        $handler = $finalHandler;

        foreach (array_reverse($this->middleware) as $mw) {
            $next = $handler;
            $handler = new class ($mw, $next) implements JobHandlerInterface {
                public function __construct(
                    private readonly JobMiddlewareInterface $middleware,
                    private readonly JobHandlerInterface $next,
                ) {}

                public function handle(Job $job): void
                {
                    $this->middleware->process($job, $this->next);
                }
            };
        }

        $handler->handle($job);
    }
}

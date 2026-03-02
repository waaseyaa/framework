<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Handler;

use Waaseyaa\Foundation\Middleware\JobHandlerInterface as JobHandlerContract;
use Waaseyaa\Foundation\Middleware\JobPipeline;
use Waaseyaa\Queue\Job;

/**
 * Bridges HandlerInterface to Job::handle().
 *
 * This default handler is automatically prepended by SyncQueue so that any
 * Job subclass dispatched without a dedicated handler adapter will have its
 * handle() method called correctly, with the attempt counter incremented.
 *
 * When a JobPipeline is provided, the job is executed through the middleware
 * stack before reaching its handle() method.
 */
final class JobHandler implements HandlerInterface
{
    public function __construct(
        private readonly ?JobPipeline $pipeline = null,
    ) {}

    public function supports(object $message): bool
    {
        return $message instanceof Job;
    }

    public function handle(object $message): void
    {
        /** @var Job $message */
        $message->incrementAttempts();

        $jobHandler = new class implements JobHandlerContract {
            public function handle(Job $job): void
            {
                $job->handle();
            }
        };

        if ($this->pipeline !== null) {
            $this->pipeline->handle($message, $jobHandler);
        } else {
            $jobHandler->handle($message);
        }
    }
}

<?php

declare(strict_types=1);

namespace Aurora\Queue;

/**
 * Runs a sequence of jobs in order, stopping on the first failure.
 *
 * If any job in the chain throws an exception, subsequent jobs
 * are not executed and the exception propagates to the caller.
 */
final class ChainedJobs
{
    /** @var Job[] */
    private readonly array $jobs;

    /**
     * @param Job[] $jobs The ordered list of jobs to run in sequence.
     */
    public function __construct(array $jobs)
    {
        $this->jobs = array_values($jobs);
    }

    /**
     * Execute all jobs in sequence.
     *
     * @throws \Throwable If any job fails, the exception is re-thrown
     *                     after calling its failed() method.
     */
    public function handle(): void
    {
        foreach ($this->jobs as $job) {
            try {
                $job->incrementAttempts();
                $job->handle();
            } catch (\Throwable $e) {
                $job->failed($e);

                throw $e;
            }
        }
    }

    /**
     * Get the jobs in this chain.
     *
     * @return Job[]
     */
    public function getJobs(): array
    {
        return $this->jobs;
    }
}

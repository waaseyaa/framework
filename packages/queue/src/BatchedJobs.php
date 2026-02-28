<?php

declare(strict_types=1);

namespace Aurora\Queue;

/**
 * Groups multiple jobs as a batch with completion callbacks.
 *
 * Supports then/catch/finally semantics and optional failure tolerance.
 */
final class BatchedJobs
{
    /** @var Job[] */
    private readonly array $jobs;

    /** @var callable[] */
    private array $thenCallbacks = [];

    /** @var callable[] */
    private array $catchCallbacks = [];

    /** @var callable[] */
    private array $finallyCallbacks = [];

    private bool $allowingFailures = false;

    /**
     * @param Job[] $jobs The jobs to process as a batch.
     */
    public function __construct(array $jobs)
    {
        $this->jobs = array_values($jobs);
    }

    /**
     * Register a callback to be invoked when all jobs succeed.
     */
    public function then(callable $callback): self
    {
        $this->thenCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback to be invoked when any job fails.
     */
    public function catch(callable $callback): self
    {
        $this->catchCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback to be invoked after all jobs have been
     * attempted, regardless of success or failure.
     */
    public function finally(callable $callback): self
    {
        $this->finallyCallbacks[] = $callback;

        return $this;
    }

    /**
     * Allow individual job failures without stopping the batch.
     *
     * When enabled, the batch will continue processing remaining
     * jobs even if some fail.
     */
    public function allowFailures(): self
    {
        $this->allowingFailures = true;

        return $this;
    }

    /**
     * Whether failures are tolerated.
     */
    public function isAllowingFailures(): bool
    {
        return $this->allowingFailures;
    }

    /**
     * Execute all jobs in the batch.
     *
     * After execution, callbacks are invoked in this order:
     * 1. then() callbacks if no failures occurred
     * 2. catch() callbacks if any failures occurred (receives the list of exceptions)
     * 3. finally() callbacks always
     *
     * @throws \Throwable If a job fails and allowFailures() has not been called,
     *                     the first exception is re-thrown after catch/finally callbacks.
     */
    public function handle(): void
    {
        /** @var \Throwable[] $failures */
        $failures = [];

        foreach ($this->jobs as $job) {
            try {
                $job->incrementAttempts();
                $job->handle();
            } catch (\Throwable $e) {
                $job->failed($e);
                $failures[] = $e;

                if (!$this->allowingFailures) {
                    break;
                }
            }
        }

        if ($failures === []) {
            foreach ($this->thenCallbacks as $callback) {
                $callback();
            }
        } else {
            foreach ($this->catchCallbacks as $callback) {
                $callback($failures);
            }
        }

        foreach ($this->finallyCallbacks as $callback) {
            $callback();
        }

        // If failures occurred and we are not allowing them, re-throw the first.
        if ($failures !== [] && !$this->allowingFailures) {
            throw $failures[0];
        }
    }

    /**
     * Get the jobs in this batch.
     *
     * @return Job[]
     */
    public function getJobs(): array
    {
        return $this->jobs;
    }
}

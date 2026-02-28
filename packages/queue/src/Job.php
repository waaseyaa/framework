<?php

declare(strict_types=1);

namespace Aurora\Queue;

/**
 * Abstract base class for queue jobs.
 *
 * Provides a structured way to define dispatchable work units with
 * retry policies, timeouts, and failure handling.
 */
abstract class Job
{
    /**
     * Maximum number of times this job may be attempted.
     */
    public int $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 60;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $retryAfter = 0;

    /**
     * The number of attempts that have been made so far.
     */
    private int $attempts = 0;

    /**
     * The delay (in seconds) before the job should be available again after release.
     */
    private int $releaseDelay = 0;

    /**
     * Whether the job has been released back to the queue.
     */
    private bool $released = false;

    /**
     * Execute the job.
     */
    abstract public function handle(): void;

    /**
     * Handle a job failure.
     *
     * Called when the job has exhausted all retry attempts or an
     * unrecoverable error occurs.
     */
    public function failed(\Throwable $e): void
    {
        // Default implementation does nothing.
        // Subclasses can override to perform cleanup or alerting.
    }

    /**
     * Release the job back to the queue with an optional delay.
     */
    public function release(int $delay = 0): void
    {
        $this->releaseDelay = $delay;
        $this->released = true;
    }

    /**
     * Get the number of attempts that have been made.
     */
    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /**
     * Increment the attempt counter and return the new value.
     */
    public function incrementAttempts(): int
    {
        return ++$this->attempts;
    }

    /**
     * Whether the job has been released back to the queue.
     */
    public function isReleased(): bool
    {
        return $this->released;
    }

    /**
     * Get the release delay in seconds.
     */
    public function getReleaseDelay(): int
    {
        return $this->releaseDelay;
    }

    /**
     * Whether the job has exceeded the maximum number of attempts.
     */
    public function hasExceededMaxAttempts(): bool
    {
        return $this->attempts >= $this->tries;
    }
}

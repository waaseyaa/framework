<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Worker;

use Waaseyaa\Queue\FailedJobRepositoryInterface;
use Waaseyaa\Queue\Handler\HandlerInterface;
use Waaseyaa\Queue\Job;
use Waaseyaa\Queue\Transport\TransportInterface;

/**
 * Long-running worker that processes jobs from a queue transport.
 */
final class Worker
{
    /** @var list<HandlerInterface> */
    private array $handlers;

    /**
     * @param list<HandlerInterface> $handlers
     */
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly FailedJobRepositoryInterface $failedJobRepository,
        array $handlers,
    ) {
        $this->handlers = $handlers;
    }

    /**
     * Register an additional handler. Handlers added first take priority.
     */
    public function addHandler(HandlerInterface $handler): void
    {
        array_unshift($this->handlers, $handler);
    }

    /**
     * Run the worker loop, processing jobs until a stop condition is met.
     *
     * @return int Number of jobs processed
     */
    public function run(string $queue, WorkerOptions $options): int
    {
        $startTime = time();
        $processed = 0;

        while ($this->shouldContinue($options, $processed, $startTime)) {
            $raw = $this->transport->pop($queue);

            if ($raw === null) {
                if ($options->maxJobs > 0 || $options->maxTime > 0) {
                    // In bounded mode, sleep briefly then check conditions
                    sleep($options->sleep);
                    continue;
                }
                // In daemon mode, sleep and continue
                sleep($options->sleep);
                continue;
            }

            $this->processJob($raw, $queue, $options);
            $processed++;
        }

        return $processed;
    }

    /**
     * Process a single job from the queue (non-looping).
     *
     * Useful for testing and single-job processing.
     *
     * @return bool Whether a job was available and processed
     */
    public function runNextJob(string $queue, WorkerOptions $options): bool
    {
        $raw = $this->transport->pop($queue);
        if ($raw === null) {
            return false;
        }

        $this->processJob($raw, $queue, $options);

        return true;
    }

    /**
     * @param array{id: int|string, payload: string, attempts: int} $raw
     */
    private function processJob(array $raw, string $queue, WorkerOptions $options): void
    {
        try {
            $message = @unserialize($raw['payload']);
        } catch (\Throwable $e) {
            $this->failedJobRepository->record($queue, $raw['payload'], $e);
            $this->transport->reject($raw['id']);

            return;
        }

        if ($message === false || !is_object($message)) {
            $this->failedJobRepository->record(
                $queue,
                $raw['payload'],
                new \RuntimeException('Failed to unserialize job payload'),
            );
            $this->transport->reject($raw['id']);

            return;
        }

        try {
            foreach ($this->handlers as $handler) {
                if ($handler->supports($message)) {
                    $handler->handle($message);
                    break;
                }
            }

            // Check if a Job released itself back to the queue
            if ($message instanceof Job && $message->isReleased()) {
                $delay = $message->getReleaseDelay();
                $this->transport->release($raw['id'], $delay);

                return;
            }

            $this->transport->ack($raw['id']);
        } catch (\Throwable $e) {
            $this->handleFailure($raw, $queue, $message, $e, $options);
        }
    }

    private function handleFailure(
        array $raw,
        string $queue,
        object $message,
        \Throwable $e,
        WorkerOptions $options,
    ): void {
        $maxTries = $message instanceof Job ? $message->tries : $options->maxTries;
        $currentAttempts = $raw['attempts'] + 1; // +1 for the attempt we just made

        if ($currentAttempts < $maxTries) {
            $delay = $this->calculateBackoff($message, $currentAttempts);
            $this->transport->release($raw['id'], $delay);
        } else {
            $this->failedJobRepository->record($queue, $raw['payload'], $e);
            $this->transport->reject($raw['id']);

            if ($message instanceof Job) {
                try {
                    $message->failed($e);
                } catch (\Throwable) {
                    // Best-effort: don't let failure handler crash the worker
                }
            }
        }
    }

    private function calculateBackoff(object $message, int $attempts): int
    {
        $baseDelay = $message instanceof Job ? $message->retryAfter : 5;
        if ($baseDelay <= 0) {
            return 0;
        }

        return min($baseDelay * (2 ** ($attempts - 1)), 3600);
    }

    private function shouldContinue(WorkerOptions $options, int $processed, int $startTime): bool
    {
        if ($options->maxJobs > 0 && $processed >= $options->maxJobs) {
            return false;
        }

        if ($options->maxTime > 0 && (time() - $startTime) >= $options->maxTime) {
            return false;
        }

        if (memory_get_usage(true) / 1024 / 1024 >= $options->memoryLimit) {
            return false;
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler;

use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Queue\QueueInterface;
use Waaseyaa\Scheduler\Lock\LockInterface;
use Waaseyaa\Scheduler\Storage\ScheduleStateRepository;

final class ScheduleRunner
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ScheduleInterface $schedule,
        private readonly QueueInterface $queue,
        private readonly LockInterface $lock,
        private readonly ?ScheduleStateRepository $stateRepository = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function run(\DateTimeInterface $now): ScheduleRunResult
    {
        $ran = [];

        foreach ($this->schedule->tasks() as $task) {
            if (!$task->isDue($now)) {
                continue;
            }

            $result = $this->runTask($task, $now);
            // Schedule-wide `run()` reports a count of successfully-executed
            // tasks; failures and overlap-blocked invocations are not counted.
            // `ScheduleRunResult::count` here means "how many fired", not
            // "how many were eligible". `runOne()` returns the raw per-task
            // result so the caller can introspect status directly.
            if ($result->count === 1) {
                $ran[] = $task->name;
            }
        }

        return new ScheduleRunResult(count($ran), $ran);
    }

    /**
     * Execute a single registered task by name, on demand (M4B WP02 — admin
     * scheduler dashboard "Run now" action).
     *
     * Unlike `run()`, this bypasses the `isDue()` cron check — the operator
     * is explicitly asking for an immediate run. Overlap-prevention is still
     * honoured: if `preventOverlap=true` and the lock cannot be acquired,
     * the result reports `skipped: overlap` and `recordRun()` is called so
     * the dashboard reflects the attempt.
     *
     * @throws \InvalidArgumentException When no task with `$taskName` is registered.
     */
    public function runOne(string $taskName, \DateTimeInterface $now): ScheduleRunResult
    {
        $task = null;
        foreach ($this->schedule->tasks() as $candidate) {
            if ($candidate->name === $taskName) {
                $task = $candidate;
                break;
            }
        }

        if ($task === null) {
            throw new \InvalidArgumentException(
                sprintf('No scheduled task is registered with name "%s".', $taskName),
            );
        }

        return $this->runTask($task, $now);
    }

    /**
     * Execute one task's command, record the outcome, and return a result.
     *
     * Shared between `run()` (cron-driven sweep) and `runOne()` (operator
     * trigger). Always calls `stateRepository->recordRun()` when a repository
     * is bound — including for overlap-skipped invocations — so the dashboard
     * never goes stale relative to actual runner activity.
     */
    private function runTask(ScheduledTask $task, \DateTimeInterface $now): ScheduleRunResult
    {
        // Per-task overlap-lock TTL (scheduler m2): must exceed the task's
        // expected runtime, since a mid-run lease expiry is what opens the
        // split-brain reclaim window that scheduler m15's ownership token closes.
        $lockToken = null;
        if ($task->preventOverlap) {
            $lockToken = $this->lock->acquire($task->name, $task->lockTtl, $now);
            if ($lockToken === null) {
                $this->stateRepository?->recordRun($task->name, ScheduleRunResult::STATUS_SKIPPED_OVERLAP, $now);

                return new ScheduleRunResult(
                    count: 0,
                    taskNames: [],
                    status: ScheduleRunResult::STATUS_SKIPPED_OVERLAP,
                    message: sprintf('Task "%s" is already running (overlap lock held).', $task->name),
                );
            }
        }

        try {
            if (is_string($task->command)) {
                $this->queue->dispatch(new ($task->command)());
            } else {
                ($task->command)();
            }
            $this->stateRepository?->recordRun($task->name, ScheduleRunResult::STATUS_SUCCESS, $now);

            return new ScheduleRunResult(
                count: 1,
                taskNames: [$task->name],
                status: ScheduleRunResult::STATUS_SUCCESS,
                message: sprintf('Task "%s" completed.', $task->name),
            );
        } catch (\Throwable $e) {
            // FR-010 — surface a structured failure to the controller WITHOUT
            // passing the throwable through. The dashboard JSON payload is
            // built from `status`, `message`, and `exceptionClass` (FQCN
            // string), never from the raw object.
            $this->stateRepository?->recordRun($task->name, 'failed: ' . $e->getMessage(), $now);
            $this->logger->error("Task {$task->name} failed: {$e->getMessage()}");

            return new ScheduleRunResult(
                count: 0,
                taskNames: [],
                status: ScheduleRunResult::STATUS_FAILED,
                message: $e->getMessage(),
                exceptionClass: $e::class,
            );
        } finally {
            // Release only the lock THIS run acquired, scoped by its owner token
            // so a lease that expired mid-run (and was reclaimed by another node)
            // is never torn down here (scheduler m15).
            if ($lockToken !== null) {
                $this->lock->release($task->name, $lockToken);
            }
        }
    }
}

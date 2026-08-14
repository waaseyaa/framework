<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler;

use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Queue\QueueInterface;
use Waaseyaa\Scheduler\Execution\LeaseAwareCommandInterface;
use Waaseyaa\Scheduler\Execution\LeaseExecutionContext;
use Waaseyaa\Scheduler\Fence\FenceGuardInterface;
use Waaseyaa\Scheduler\Lease\LeaseAuthorityInterface;
use Waaseyaa\Scheduler\Occurrence\IdempotencyKeyRequiredException;
use Waaseyaa\Scheduler\Occurrence\InvalidIdempotencyKeyException;
use Waaseyaa\Scheduler\Occurrence\OccurrenceDispatchResult;
use Waaseyaa\Scheduler\Occurrence\OccurrenceOutboxDispatcher;
use Waaseyaa\Scheduler\Occurrence\OccurrenceOutboxRepository;
use Waaseyaa\Scheduler\Occurrence\OccurrenceRepositoryInterface;
use Waaseyaa\Scheduler\Occurrence\ScheduledOccurrence;
use Waaseyaa\Scheduler\Occurrence\UnsafeManualExecutionException;
use Waaseyaa\Scheduler\Storage\ScheduleStateRepository;

final class ScheduleRunner
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ScheduleInterface $schedule,
        private readonly QueueInterface $queue,
        private readonly LeaseAuthorityInterface $leaseAuthority,
        private readonly ?ScheduleStateRepository $stateRepository = null,
        ?LoggerInterface $logger = null,
        private readonly ?FenceGuardInterface $fenceGuard = null,
        private readonly ?OccurrenceRepositoryInterface $occurrenceRepository = null,
        private readonly ?OccurrenceOutboxRepository $occurrenceOutbox = null,
        private readonly ?OccurrenceOutboxDispatcher $occurrenceOutboxDispatcher = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function run(\DateTimeInterface $now): ScheduleRunResult
    {
        $this->occurrenceOutboxDispatcher?->dispatchPending();
        $ran = [];
        $failed = [];

        foreach ($this->schedule->tasks() as $task) {
            if (!$task->isDue($now)) {
                continue;
            }

            if (is_string($task->command)) {
                $result = $this->enqueueTask($task, $now);
                if ($result->count === 1) {
                    $ran[] = $task->name;
                } else {
                    $failed[] = $task->name;
                }
                continue;
            }

            $occurrence = null;
            if ($task->preventOverlap && $this->occurrenceRepository !== null) {
                $occurrence = $this->occurrenceRepository->recordScheduled($task, $now);
            }
            $result = $this->runTask($task, $now, $occurrence);
            // Schedule-wide `run()` reports a count of successfully-executed
            // tasks; overlap-blocked invocations are not counted as either
            // success or failure (they are an intentional skip, not an
            // error). `ScheduleRunResult::count` here means "how many fired",
            // not "how many were eligible". `runOne()` returns the raw
            // per-task result so the caller can introspect status directly.
            if ($result->count === 1) {
                $ran[] = $task->name;
            } elseif ($result->status === ScheduleRunResult::STATUS_FAILED) {
                // Surfaced to callers (audit A7 F9) so a sweep where every
                // due task fails is distinguishable from "nothing was due":
                // the CLI handler and any other consumer must not report
                // success when tasks ran and threw.
                $failed[] = $task->name;
            }
        }

        return new ScheduleRunResult(
            count: count($ran),
            taskNames: $ran,
            failedCount: count($failed),
            failedTaskNames: $failed,
        );
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
     * @throws IdempotencyKeyRequiredException When the caller omits the retry identity.
     * @throws InvalidIdempotencyKeyException When the retry identity is malformed.
     * @throws UnsafeManualExecutionException When the task cannot honor durable idempotency.
     */
    public function runOne(string $taskName, \DateTimeInterface $now, ?string $idempotencyKey = null): ScheduleRunResult
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

        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            throw new IdempotencyKeyRequiredException('Manual scheduler runs require an Idempotency-Key.');
        }
        if (strlen(trim($idempotencyKey)) > 255) {
            throw new InvalidIdempotencyKeyException('Manual scheduler runs require an idempotency key of 1 to 255 bytes.');
        }
        if (!$task->preventOverlap) {
            throw new UnsafeManualExecutionException(sprintf(
                'Task "%s" cannot be triggered manually until it uses durable occurrence and fence protection.',
                $task->name,
            ));
        }
        if ($this->occurrenceRepository === null) {
            throw new UnsafeManualExecutionException('Durable occurrence authority is unavailable.');
        }
        if (is_string($task->command)) {
            return $this->enqueueTask($task, $now, $idempotencyKey);
        }
        $occurrence = $this->occurrenceRepository->recordManual($task, $now, $idempotencyKey);

        return $this->runTask($task, $now, $occurrence);
    }

    private function enqueueTask(
        ScheduledTask $task,
        \DateTimeInterface $now,
        ?string $idempotencyKey = null,
    ): ScheduleRunResult {
        if ($this->occurrenceOutbox === null || $this->occurrenceOutboxDispatcher === null) {
            return new ScheduleRunResult(
                count: 0,
                taskNames: [],
                status: ScheduleRunResult::STATUS_FAILED,
                message: 'Durable occurrence outbox authority is unavailable.',
                exceptionClass: \LogicException::class,
            );
        }
        try {
            $occurrence = $idempotencyKey === null
                ? $this->occurrenceOutbox->recordScheduled($task, $now)
                : $this->occurrenceOutbox->recordManual($task, $now, $idempotencyKey);
            $dispatch = $this->occurrenceOutboxDispatcher->dispatchOccurrence($occurrence->id);
            if ($dispatch === OccurrenceDispatchResult::Failed) {
                return new ScheduleRunResult(
                    count: 0,
                    taskNames: [],
                    status: ScheduleRunResult::STATUS_FAILED,
                    message: 'Occurrence is durable but queue dispatch remains pending.',
                    exceptionClass: \RuntimeException::class,
                );
            }
            $this->stateRepository?->recordRun($task->name, ScheduleRunResult::STATUS_ENQUEUED, $now);

            return new ScheduleRunResult(
                count: 1,
                taskNames: [$task->name],
                status: ScheduleRunResult::STATUS_ENQUEUED,
                message: sprintf('Task "%s" was durably enqueued.', $task->name),
            );
        } catch (\Throwable $error) {
            $this->stateRepository?->recordRun($task->name, 'failed: enqueue authority unavailable', $now);
            $this->logger->error('scheduler.occurrence_enqueue_failed', [
                'task' => $task->name,
                'exception' => $error,
            ]);

            return new ScheduleRunResult(
                count: 0,
                taskNames: [],
                status: ScheduleRunResult::STATUS_FAILED,
                message: 'Durable occurrence enqueue failed.',
                exceptionClass: $error::class,
            );
        }
    }

    /**
     * Execute one task's command, record the outcome, and return a result.
     *
     * Shared between `run()` (cron-driven sweep) and `runOne()` (operator
     * trigger). Always calls `stateRepository->recordRun()` when a repository
     * is bound — including for overlap-skipped invocations — so the dashboard
     * never goes stale relative to actual runner activity.
     */
    private function runTask(ScheduledTask $task, \DateTimeInterface $now, ?ScheduledOccurrence $occurrence): ScheduleRunResult
    {
        // Per-task overlap-lock TTL (scheduler m2): must exceed the task's
        // expected runtime, since a mid-run lease expiry is what opens the
        // split-brain reclaim window that scheduler m15's ownership token closes.
        $leaseContext = null;
        if ($task->preventOverlap) {
            if ($occurrence === null) {
                return new ScheduleRunResult(
                    count: 0,
                    taskNames: [],
                    status: ScheduleRunResult::STATUS_FAILED,
                    message: 'Durable occurrence authority is unavailable.',
                    exceptionClass: \LogicException::class,
                );
            }
            try {
                $handle = $this->leaseAuthority->acquire($task->name, $task->lockTtl * 1000);
            } catch (\Throwable $error) {
                $this->stateRepository?->recordRun($task->name, 'failed: lease authority unavailable', $now);
                $this->logger->error('scheduler.lease_acquire_failed', [
                    'task' => $task->name,
                    'exception' => $error,
                ]);

                return new ScheduleRunResult(
                    count: 0,
                    taskNames: [],
                    status: ScheduleRunResult::STATUS_FAILED,
                    message: 'Durable lease acquisition failed.',
                    exceptionClass: $error::class,
                );
            }
            if ($handle === null) {
                $this->stateRepository?->recordRun($task->name, ScheduleRunResult::STATUS_SKIPPED_OVERLAP, $now);

                return new ScheduleRunResult(
                    count: 0,
                    taskNames: [],
                    status: ScheduleRunResult::STATUS_SKIPPED_OVERLAP,
                    message: sprintf('Task "%s" is already running (overlap lock held).', $task->name),
                );
            }
            if ($this->fenceGuard === null) {
                $this->leaseAuthority->release($handle);

                return new ScheduleRunResult(
                    count: 0,
                    taskNames: [],
                    status: ScheduleRunResult::STATUS_FAILED,
                    message: 'Durable fence authority is unavailable.',
                    exceptionClass: \LogicException::class,
                );
            }
            if (!$this->occurrenceRepository->begin($occurrence->id, $handle->fence)) {
                $this->leaseAuthority->release($handle);

                return new ScheduleRunResult(
                    count: 0,
                    taskNames: [],
                    status: ScheduleRunResult::STATUS_SKIPPED_DUPLICATE,
                    message: sprintf('Task "%s" occurrence is already owned or complete.', $task->name),
                );
            }
            $leaseContext = new LeaseExecutionContext(
                $this->leaseAuthority,
                $handle,
                $task->lockTtl * 1000,
                $this->fenceGuard,
                $occurrence->id,
            );
        }

        try {
            if ($task->command instanceof LeaseAwareCommandInterface) {
                if ($leaseContext === null) {
                    throw new \LogicException('Lease-aware commands require overlap protection.');
                }
                $leaseContext->checkpoint();
                $task->command->run($leaseContext);
                $leaseContext->checkpoint();
            } elseif (is_string($task->command)) {
                $this->queue->dispatch(new ($task->command)());
            } else {
                ($task->command)();
            }
            if ($occurrence !== null && $leaseContext !== null) {
                $this->occurrenceRepository?->complete($occurrence->id, $leaseContext->fence());
            }
            $this->stateRepository?->recordRun($task->name, ScheduleRunResult::STATUS_SUCCESS, $now);

            return new ScheduleRunResult(
                count: 1,
                taskNames: [$task->name],
                status: ScheduleRunResult::STATUS_SUCCESS,
                message: sprintf('Task "%s" completed.', $task->name),
            );
        } catch (\Throwable $e) {
            if ($occurrence !== null && $leaseContext !== null) {
                $this->occurrenceRepository?->fail($occurrence->id, $leaseContext->fence(), $e::class);
            }
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
            if ($leaseContext !== null) {
                try {
                    $leaseContext->release();
                } catch (\Throwable $error) {
                    $this->logger->error('scheduler.lease_release_failed', [
                        'task' => $task->name,
                        'exception' => $error,
                    ]);
                }
            }
        }
    }
}

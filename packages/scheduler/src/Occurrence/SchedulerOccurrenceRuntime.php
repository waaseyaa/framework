<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Occurrence;

use Waaseyaa\Queue\Envelope\QueueOccurrenceV1;
use Waaseyaa\Queue\Occurrence\OccurrenceRunResult;
use Waaseyaa\Queue\Occurrence\OccurrenceRuntimeInterface;
use Waaseyaa\Scheduler\Execution\LeaseExecutionContext;
use Waaseyaa\Scheduler\Fence\FenceGuardInterface;
use Waaseyaa\Scheduler\Lease\LeaseAuthorityInterface;

/** Worker-side owner of queued scheduler occurrence execution. */
final readonly class SchedulerOccurrenceRuntime implements OccurrenceRuntimeInterface
{
    public function __construct(
        private LeaseAuthorityInterface $leaseAuthority,
        private OccurrenceRepositoryInterface $occurrences,
        private FenceGuardInterface $fenceGuard,
    ) {}

    public function run(QueueOccurrenceV1 $occurrence, callable $execute): OccurrenceRunResult
    {
        $this->assertIdentityMatchesLedger($occurrence);
        $handle = $this->leaseAuthority->acquire($occurrence->taskName, $occurrence->leaseTtlMs);
        if ($handle === null) {
            return OccurrenceRunResult::Contended;
        }

        $context = null;
        try {
            if (!$this->occurrences->begin($occurrence->occurrenceId, $handle->fence)) {
                $current = $this->occurrences->require($occurrence->occurrenceId);

                return in_array($current->status, ['completed', 'dead_letter'], true)
                    ? OccurrenceRunResult::Duplicate
                    : OccurrenceRunResult::Contended;
            }
            $context = new LeaseExecutionContext(
                $this->leaseAuthority,
                $handle,
                $occurrence->leaseTtlMs,
                $this->fenceGuard,
                $occurrence->occurrenceId,
            );
            $queueContext = new QueueOccurrenceExecutionContext($context);
            $queueContext->checkpoint();
            $execute($queueContext);
            $queueContext->checkpoint();
            $this->occurrences->complete($occurrence->occurrenceId, $context->fence());

            return OccurrenceRunResult::Executed;
        } catch (\Throwable $error) {
            if ($context !== null) {
                $this->occurrences->fail($occurrence->occurrenceId, $context->fence(), $error::class);
            }

            throw $error;
        } finally {
            if ($context !== null) {
                $context->release();
            } else {
                $this->leaseAuthority->release($handle);
            }
        }
    }

    public function deadLetter(QueueOccurrenceV1 $occurrence, string $failureClass): bool
    {
        $this->assertIdentityMatchesLedger($occurrence);
        $handle = $this->leaseAuthority->acquire($occurrence->taskName, $occurrence->leaseTtlMs);
        if ($handle === null) {
            return false;
        }
        try {
            if (!$this->occurrences->begin($occurrence->occurrenceId, $handle->fence)) {
                return in_array(
                    $this->occurrences->require($occurrence->occurrenceId)->status,
                    ['completed', 'dead_letter'],
                    true,
                );
            }
            $this->occurrences->deadLetter($occurrence->occurrenceId, $handle->fence, $failureClass);

            return true;
        } finally {
            $this->leaseAuthority->release($handle);
        }
    }

    private function assertIdentityMatchesLedger(QueueOccurrenceV1 $identity): void
    {
        $record = $this->occurrences->require($identity->occurrenceId);
        if (
            $record->taskName !== $identity->taskName
            || $record->scheduleGeneration !== $identity->scheduleGeneration
        ) {
            throw new \RuntimeException('Queued occurrence identity does not match the durable scheduler ledger.');
        }
    }
}

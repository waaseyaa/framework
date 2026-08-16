<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Tests\Unit\Occurrence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Queue\Envelope\QueueOccurrenceV1;
use Waaseyaa\Queue\Occurrence\OccurrenceRunResult;
use Waaseyaa\Queue\Tests\Unit\Fixtures\OccurrenceAwareJob;
use Waaseyaa\Scheduler\Occurrence\SchedulerOccurrenceRuntime;
use Waaseyaa\Scheduler\ScheduledTask;
use Waaseyaa\Scheduler\Testing\InMemoryFenceGuard;
use Waaseyaa\Scheduler\Testing\InMemoryLeaseAuthority;
use Waaseyaa\Scheduler\Testing\InMemoryOccurrenceRepository;

final class SchedulerOccurrenceRuntimeTest extends TestCase
{
    #[Test]
    public function workerLeaseExecutesOneFencedEffectAndDuplicateIsNoOp(): void
    {
        $leases = new InMemoryLeaseAuthority();
        $occurrences = new InMemoryOccurrenceRepository();
        $task = self::task();
        $record = $occurrences->recordScheduled($task, new \DateTimeImmutable());
        $runtime = new SchedulerOccurrenceRuntime($leases, $occurrences, new InMemoryFenceGuard());
        $identity = new QueueOccurrenceV1($record->id, $task->name, $task->scheduleGeneration(), 300_000);
        $effects = 0;

        $first = $runtime->run($identity, static function ($context) use (&$effects): void {
            $context->effect('resource', 'write', static function () use (&$effects): void {
                ++$effects;
            });
        });
        $duplicate = $runtime->run($identity, static function () use (&$effects): void {
            ++$effects;
        });

        self::assertSame(OccurrenceRunResult::Executed, $first);
        self::assertSame(OccurrenceRunResult::Duplicate, $duplicate);
        self::assertSame(1, $effects);
        self::assertSame('completed', $occurrences->require($record->id)->status);
    }

    #[Test]
    public function liveWorkerLeaseReturnsContentionWithoutExecuting(): void
    {
        $leases = new InMemoryLeaseAuthority();
        $occurrences = new InMemoryOccurrenceRepository();
        $task = self::task();
        $record = $occurrences->recordScheduled($task, new \DateTimeImmutable());
        $leases->acquire($task->name, 300_000);
        $runtime = new SchedulerOccurrenceRuntime($leases, $occurrences, new InMemoryFenceGuard());
        $executed = false;

        $result = $runtime->run(
            new QueueOccurrenceV1($record->id, $task->name, $task->scheduleGeneration(), 300_000),
            static function () use (&$executed): void {
                $executed = true;
            },
        );

        self::assertSame(OccurrenceRunResult::Contended, $result);
        self::assertFalse($executed);
    }

    #[Test]
    public function deadLetterAcquiresSuccessorFenceAndIsIdempotent(): void
    {
        $leases = new InMemoryLeaseAuthority();
        $occurrences = new InMemoryOccurrenceRepository();
        $task = self::task();
        $record = $occurrences->recordScheduled($task, new \DateTimeImmutable());
        $runtime = new SchedulerOccurrenceRuntime($leases, $occurrences, new InMemoryFenceGuard());
        $identity = new QueueOccurrenceV1($record->id, $task->name, $task->scheduleGeneration(), 300_000);

        self::assertTrue($runtime->deadLetter($identity, \RuntimeException::class));
        self::assertTrue($runtime->deadLetter($identity, \RuntimeException::class));
        self::assertSame('dead_letter', $occurrences->require($record->id)->status);
        self::assertSame(
            OccurrenceRunResult::Duplicate,
            $runtime->run($identity, static function (): void {
                self::fail('A dead-lettered occurrence must not execute through generic queue retry.');
            }),
        );
    }

    #[Test]
    public function mismatchedQueuedIdentityFailsBeforeExecution(): void
    {
        $leases = new InMemoryLeaseAuthority();
        $occurrences = new InMemoryOccurrenceRepository();
        $task = self::task();
        $record = $occurrences->recordScheduled($task, new \DateTimeImmutable());
        $runtime = new SchedulerOccurrenceRuntime($leases, $occurrences, new InMemoryFenceGuard());
        $executed = false;

        try {
            $runtime->run(
                new QueueOccurrenceV1($record->id, 'different-task', $task->scheduleGeneration(), 300_000),
                static function () use (&$executed): void {
                    $executed = true;
                },
            );
            self::fail('A queued identity may not select a different lease domain than its ledger record.');
        } catch (\RuntimeException $error) {
            self::assertSame(
                'Queued occurrence identity does not match the durable scheduler ledger.',
                $error->getMessage(),
            );
        }

        self::assertFalse($executed);
        self::assertSame('recorded', $occurrences->require($record->id)->status);
    }

    private static function task(): ScheduledTask
    {
        return new ScheduledTask('queued-retention', '* * * * *', OccurrenceAwareJob::class, preventOverlap: true);
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Tests\Unit\Occurrence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Scheduler\Execution\LeaseAwareClosureCommand;
use Waaseyaa\Scheduler\Execution\LeaseExecutionContext;
use Waaseyaa\Scheduler\Occurrence\OccurrenceRepository;
use Waaseyaa\Scheduler\ScheduledTask;

final class OccurrenceRepositoryTest extends TestCase
{
    private OccurrenceRepository $repository;

    protected function setUp(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->getConnection()->executeStatement(<<<'SQL'
            CREATE TABLE waaseyaa_scheduler_occurrences (
                occurrence_id VARCHAR(64) PRIMARY KEY,
                task_name VARCHAR(255) NOT NULL,
                schedule_generation VARCHAR(64) NOT NULL,
                due_at_ms INTEGER NOT NULL,
                trigger_key VARCHAR(128) NOT NULL,
                status VARCHAR(32) NOT NULL,
                execution_fence INTEGER NOT NULL DEFAULT 0,
                failure_class VARCHAR(512) NULL,
                UNIQUE (task_name, schedule_generation, trigger_key)
            )
            SQL);
        $this->repository = new OccurrenceRepository($database);
    }

    #[Test]
    public function sameCronSlotHasOneDeterministicOccurrence(): void
    {
        $task = $this->task();
        $first = $this->repository->recordScheduled($task, new \DateTimeImmutable('2026-08-12 10:14:01 UTC'));
        $second = $this->repository->recordScheduled($task, new \DateTimeImmutable('2026-08-12 10:14:59 UTC'));

        self::assertSame($first->id, $second->id);
        self::assertSame($task->scheduleGeneration(), $first->scheduleGeneration);
        self::assertSame(1_786_529_640_000, $first->dueAtMs);
    }

    #[Test]
    public function oneFenceOwnsExecutionAndHigherFenceCanRecoverFailure(): void
    {
        $occurrence = $this->repository->recordScheduled($this->task(), new \DateTimeImmutable('2026-08-12 10:14:00 UTC'));
        self::assertTrue($this->repository->begin($occurrence->id, 4));
        self::assertFalse($this->repository->begin($occurrence->id, 4));
        $this->repository->fail($occurrence->id, 4, \RuntimeException::class);
        self::assertTrue($this->repository->begin($occurrence->id, 7));
        $this->repository->complete($occurrence->id, 7);
        self::assertFalse($this->repository->begin($occurrence->id, 8));
        self::assertSame('completed', $this->repository->require($occurrence->id)->status);
    }

    #[Test]
    public function manualKeysAreStableAndIndependentWithinOneMinute(): void
    {
        $task = $this->task();
        $now = new \DateTimeImmutable('2026-08-12 10:14:10 UTC');
        $first = $this->repository->recordManual($task, $now, 'request-a');
        $retry = $this->repository->recordManual($task, $now->modify('+20 seconds'), 'request-a');
        $second = $this->repository->recordManual($task, $now, 'request-b');

        self::assertSame($first->id, $retry->id);
        self::assertNotSame($first->id, $second->id);
    }

    private function task(): ScheduledTask
    {
        return new ScheduledTask(
            name: 'retention',
            expression: '* * * * *',
            command: new LeaseAwareClosureCommand(static fn(LeaseExecutionContext $context) => null),
            preventOverlap: true,
            timezone: 'UTC',
        );
    }
}

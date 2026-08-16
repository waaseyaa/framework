<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Tests\Unit\Occurrence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Queue\Envelope\QueueOccurrenceV1;
use Waaseyaa\Queue\OccurrenceQueueInterface;
use Waaseyaa\Queue\Tests\Unit\Fixtures\OccurrenceAwareJob;
use Waaseyaa\Scheduler\Occurrence\OccurrenceDispatchResult;
use Waaseyaa\Scheduler\Occurrence\OccurrenceOutboxDispatcher;
use Waaseyaa\Scheduler\Occurrence\OccurrenceOutboxRepository;
use Waaseyaa\Scheduler\Occurrence\OccurrenceRepository;
use Waaseyaa\Scheduler\ScheduledTask;

final class OccurrenceOutboxRepositoryTest extends TestCase
{
    private OccurrenceOutboxRepository $outbox;
    private OccurrenceRepository $occurrences;

    protected function setUp(): void
    {
        $database = DBALDatabase::createSqlite();
        $database->query('CREATE TABLE waaseyaa_scheduler_occurrences (occurrence_id VARCHAR(64) PRIMARY KEY, task_name VARCHAR(255) NOT NULL, schedule_generation VARCHAR(64) NOT NULL, due_at_ms INTEGER NOT NULL, trigger_key VARCHAR(128) NOT NULL, status VARCHAR(32) NOT NULL, execution_fence INTEGER NOT NULL DEFAULT 0, failure_class VARCHAR(512) NULL, UNIQUE (task_name, schedule_generation, trigger_key))');
        $database->query('CREATE TABLE waaseyaa_scheduler_occurrence_outbox (occurrence_id VARCHAR(64) PRIMARY KEY, message_class VARCHAR(512) NOT NULL, lease_ttl_ms INTEGER NOT NULL, state VARCHAR(32) NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, last_error_class VARCHAR(512) NULL)');
        $this->occurrences = new OccurrenceRepository($database);
        $this->outbox = new OccurrenceOutboxRepository($database, $this->occurrences);
    }

    #[Test]
    public function producerRetryCreatesOneOccurrenceAndOneOutboxEntry(): void
    {
        $task = self::task();
        $due = new \DateTimeImmutable('2026-08-12 10:14:30 UTC');

        $first = $this->outbox->recordScheduled($task, $due);
        $retry = $this->outbox->recordScheduled($task, $due->modify('+20 seconds'));

        self::assertSame($first->id, $retry->id);
        self::assertCount(1, $this->outbox->pending());
    }

    #[Test]
    public function ambiguousDispatchRetryPreservesOccurrenceIdentity(): void
    {
        $occurrence = $this->outbox->recordScheduled(self::task(), new \DateTimeImmutable());
        $queue = new class implements OccurrenceQueueInterface {
            public int $deliveries = 0;
            public function dispatch(object $message): void {}
            public function dispatchOccurrence(object $message, QueueOccurrenceV1 $occurrence): void
            {
                ++$this->deliveries;
                if ($this->deliveries === 1) {
                    throw new \RuntimeException('ambiguous transport response');
                }
            }
        };
        $dispatcher = new OccurrenceOutboxDispatcher($this->outbox, $queue);

        self::assertSame(OccurrenceDispatchResult::Failed, $dispatcher->dispatchOccurrence($occurrence->id));
        self::assertSame(OccurrenceDispatchResult::Dispatched, $dispatcher->dispatchOccurrence($occurrence->id));
        self::assertSame(OccurrenceDispatchResult::AlreadyDispatched, $dispatcher->dispatchOccurrence($occurrence->id));
        self::assertSame(2, $queue->deliveries);
    }

    #[Test]
    public function dispatchExhaustionDeadLettersOutboxAndOccurrenceAtomically(): void
    {
        $occurrence = $this->outbox->recordScheduled(self::task(), new \DateTimeImmutable());
        $queue = new class implements OccurrenceQueueInterface {
            public function dispatch(object $message): void {}
            public function dispatchOccurrence(object $message, QueueOccurrenceV1 $occurrence): void
            {
                throw new \RuntimeException('transport unavailable');
            }
        };
        $dispatcher = new OccurrenceOutboxDispatcher($this->outbox, $queue, maxAttempts: 1);

        self::assertSame(OccurrenceDispatchResult::Failed, $dispatcher->dispatchOccurrence($occurrence->id));
        self::assertSame('dead_letter', $this->outbox->state($occurrence->id));
        self::assertSame('dead_letter', $this->occurrences->require($occurrence->id)->status);
    }

    private static function task(): ScheduledTask
    {
        return new ScheduledTask(
            'queued-retention',
            '* * * * *',
            OccurrenceAwareJob::class,
            preventOverlap: true,
        );
    }
}

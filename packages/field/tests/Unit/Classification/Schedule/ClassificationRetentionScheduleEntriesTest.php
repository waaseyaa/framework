<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit\Classification\Schedule;

use Waaseyaa\Scheduler\Execution\LeaseAwareCommandInterface;
use Waaseyaa\Scheduler\Execution\LeaseExecutionContext;
use Waaseyaa\Scheduler\Testing\InMemoryLeaseAuthority;
use Waaseyaa\Scheduler\Testing\InMemoryFenceGuard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\Classification\Schedule\ClassificationRetentionScheduleEntries;
use Waaseyaa\Scheduler\ScheduledTask;
use Waaseyaa\Scheduler\ScheduleInterface;

#[CoversClass(ClassificationRetentionScheduleEntries::class)]
final class ClassificationRetentionScheduleEntriesTest extends TestCase
{
    #[Test]
    public function registers_three_tasks_with_expected_names(): void
    {
        $entries = new ClassificationRetentionScheduleEntries();
        $schedule = $this->schedule();

        $tasks = $entries->register($schedule);

        self::assertSame(
            [
                ClassificationRetentionScheduleEntries::TASK_PURGE,
                ClassificationRetentionScheduleEntries::TASK_REDACT,
                ClassificationRetentionScheduleEntries::TASK_HOLD_SCAN,
            ],
            array_keys($tasks),
        );
    }

    #[Test]
    public function tasks_carry_the_spec_cron_expressions(): void
    {
        $entries = new ClassificationRetentionScheduleEntries();
        $tasks = $entries->register($this->schedule());

        self::assertSame('0 */6 * * *', $tasks[ClassificationRetentionScheduleEntries::TASK_PURGE]->expression);
        self::assertSame('30 */6 * * *', $tasks[ClassificationRetentionScheduleEntries::TASK_REDACT]->expression);
        self::assertSame('0 3 * * *', $tasks[ClassificationRetentionScheduleEntries::TASK_HOLD_SCAN]->expression);
    }

    #[Test]
    public function tasks_are_added_to_the_schedule(): void
    {
        $entries = new ClassificationRetentionScheduleEntries();
        $schedule = $this->schedule();

        $entries->register($schedule);

        self::assertCount(3, $schedule->tasks());
    }

    #[Test]
    public function tasks_run_in_utc_and_prevent_overlap(): void
    {
        $entries = new ClassificationRetentionScheduleEntries();
        $tasks = $entries->register($this->schedule());

        foreach ($tasks as $task) {
            self::assertSame('UTC', $task->timezone);
            self::assertTrue($task->preventOverlap);
        }
    }

    #[Test]
    public function task_closures_are_inert_when_jobs_are_null(): void
    {
        // Discoverability without bound job dependencies must not error.
        $entries = new ClassificationRetentionScheduleEntries();
        $tasks = $entries->register($this->schedule());

        foreach ($tasks as $task) {
            self::assertInstanceOf(LeaseAwareCommandInterface::class, $task->command);
            $authority = new InMemoryLeaseAuthority();
            $handle = $authority->acquire($task->name, 300_000);
            self::assertNotNull($handle);
            $task->command->run(new LeaseExecutionContext($authority, $handle, 300_000, new InMemoryFenceGuard()));
        }
    }

    private function schedule(): ScheduleInterface
    {
        return new class implements ScheduleInterface {
            /** @var list<ScheduledTask> */
            private array $tasks = [];

            public function add(ScheduledTask $task): static
            {
                $this->tasks[] = $task;

                return $this;
            }

            /** @return list<ScheduledTask> */
            public function tasks(): array
            {
                return $this->tasks;
            }
        };
    }
}

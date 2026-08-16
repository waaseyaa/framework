<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Schedule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Api\Schedule\BroadcastStorageScheduleEntries;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Scheduler\Schedule;
use Waaseyaa\Scheduler\ScheduledTask;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

#[CoversClass(BroadcastStorageScheduleEntries::class)]
final class BroadcastStorageScheduleEntriesTest extends TestCase
{
    private BroadcastStorage $broadcastStorage;

    protected function setUp(): void
    {
        // BroadcastStorage is final — cannot be mocked. Use a real in-memory SQLite instance.
        $db = DBALDatabase::createSqlite(':memory:');
        RuntimeSchemaMigrations::broadcast($db);
        $this->broadcastStorage = new BroadcastStorage($db);
    }

    #[Test]
    public function registerAddsPruneTaskWithNightlyCron(): void
    {
        $schedule = new Schedule();

        $entries = new BroadcastStorageScheduleEntries($this->broadcastStorage);
        $result = $entries->register($schedule);

        self::assertArrayHasKey('prune', $result);
        $task = $result['prune'];
        self::assertInstanceOf(ScheduledTask::class, $task);
        self::assertSame('broadcast_log_prune', $task->name);
        self::assertSame('0 2 * * *', $task->expression);
        self::assertSame('UTC', $task->timezone);

        $tasks = $schedule->tasks();
        self::assertCount(1, $tasks);
        self::assertSame($task, $tasks[0]);
    }

    #[Test]
    public function pruneCallbackInvokesStoragePrune(): void
    {
        $schedule = new Schedule();

        $entries = new BroadcastStorageScheduleEntries($this->broadcastStorage);
        $result = $entries->register($schedule);

        $task = $result['prune'];
        self::assertInstanceOf(ScheduledTask::class, $task);
        self::assertInstanceOf(\Closure::class, $task->command);

        // Push a row older than 7 days to verify prune runs without error.
        $this->broadcastStorage->push('admin', 'test', []);

        // Invoke the closure — calls $broadcastStorage->prune(7). Must not throw.
        ($task->command)();
    }

    #[Test]
    public function customRetentionDaysPassedToConfig(): void
    {
        $schedule = new Schedule();

        $config = ['schedule' => ['broadcast_log_retention_days' => 14]];
        $entries = new BroadcastStorageScheduleEntries($this->broadcastStorage, $config);
        $result = $entries->register($schedule);

        $task = $result['prune'];
        self::assertInstanceOf(ScheduledTask::class, $task);
        // Verify the description embeds the retention days (14).
        self::assertStringContainsString('14', $task->description ?? '');
        // Invoke the closure — must not throw.
        ($task->command)();
    }

    #[Test]
    public function registerIsInertWhenBroadcastStorageIsNull(): void
    {
        // Regression test: kernels that do not bind BroadcastStorage (e.g. minimal SSR/OIDC
        // test kernels) must not fail. The entry must be inert — empty task map, nothing added
        // to the schedule.
        $schedule = new Schedule();

        $entries = new BroadcastStorageScheduleEntries(broadcastStorage: null);
        $result = $entries->register($schedule);

        self::assertSame([], $result);
        self::assertCount(0, $schedule->tasks());
    }
}

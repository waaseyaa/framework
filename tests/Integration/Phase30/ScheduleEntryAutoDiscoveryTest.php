<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase30;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Api\Schedule\BroadcastStorageScheduleEntries;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Scheduler\Schedule;
use Waaseyaa\Scheduler\Schedule\Ai\AgentScheduleEntries;
use Waaseyaa\Scheduler\ScheduleEntriesInterface;

/**
 * Integration test: schedule entry auto-discovery (FR-005, FR-012, FR-013).
 *
 * Verifies that both built-in ScheduleEntriesInterface implementations register
 * the expected tasks, and that the broadcast-log prune task actually removes
 * stale rows from _broadcast_log (FR-013 / issue #1536 effective behaviour).
 */
#[CoversNothing]
final class ScheduleEntryAutoDiscoveryTest extends TestCase
{
    #[Test]
    public function agent_schedule_entries_implements_interface(): void
    {
        $entries = new AgentScheduleEntries();
        self::assertInstanceOf(ScheduleEntriesInterface::class, $entries);
    }

    #[Test]
    public function agent_schedule_entries_registers_purge_and_reap_tasks(): void
    {
        $schedule = new Schedule();
        $entries = new AgentScheduleEntries();
        $tasks = $entries->register($schedule);

        self::assertArrayHasKey('purge', $tasks);
        self::assertArrayHasKey('reap', $tasks);
        self::assertSame(AgentScheduleEntries::TASK_PURGE, $tasks['purge']->name);
        self::assertSame(AgentScheduleEntries::TASK_REAP, $tasks['reap']->name);
        self::assertSame(AgentScheduleEntries::CRON_PURGE, $tasks['purge']->expression);
        self::assertSame(AgentScheduleEntries::CRON_REAP, $tasks['reap']->expression);

        $registeredNames = array_map(static fn($t) => $t->name, $schedule->tasks());
        self::assertContains(AgentScheduleEntries::TASK_PURGE, $registeredNames);
        self::assertContains(AgentScheduleEntries::TASK_REAP, $registeredNames);
    }

    #[Test]
    public function broadcast_storage_schedule_entries_implements_interface(): void
    {
        $db = DBALDatabase::createSqlite();
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::broadcast($db);
        $storage = new BroadcastStorage($db);
        $entries = new BroadcastStorageScheduleEntries($storage);
        self::assertInstanceOf(ScheduleEntriesInterface::class, $entries);
    }

    #[Test]
    public function broadcast_storage_schedule_entries_registers_prune_task(): void
    {
        $db = DBALDatabase::createSqlite();
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::broadcast($db);
        $storage = new BroadcastStorage($db);
        $schedule = new Schedule();
        $entries = new BroadcastStorageScheduleEntries($storage);
        $tasks = $entries->register($schedule);

        self::assertArrayHasKey('prune', $tasks);
        self::assertSame('broadcast_log_prune', $tasks['prune']->name);

        $registeredNames = array_map(static fn($t) => $t->name, $schedule->tasks());
        self::assertContains('broadcast_log_prune', $registeredNames);
    }

    #[Test]
    public function broadcast_prune_task_removes_stale_rows(#[\SensitiveParameter] string $ignored = ''): void
    {
        // FR-013 / #1536: prune closure actually deletes rows older than retention window.
        $db = DBALDatabase::createSqlite();
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::broadcast($db);
        $storage = new BroadcastStorage($db);

        // Insert two rows: one stale (15 days ago), one fresh (1 hour ago).
        $pdo = $db->getConnection()->getNativeConnection();
        assert($pdo instanceof \PDO);

        $staleTs = (float) (time() - 15 * 86400);
        $freshTs = (float) (time() - 3600);

        $pdo->exec(
            "INSERT INTO _broadcast_log (channel, event, data, created_at) VALUES ('admin', 'test.stale', '{}', {$staleTs})",
        );
        $pdo->exec(
            "INSERT INTO _broadcast_log (channel, event, data, created_at) VALUES ('admin', 'test.fresh', '{}', {$freshTs})",
        );

        // Register the schedule entry (7-day default retention) and run its task closure.
        $schedule = new Schedule();
        $entries = new BroadcastStorageScheduleEntries($storage, ['schedule' => ['broadcast_log_retention_days' => 7]]);
        $tasks = $entries->register($schedule);

        // Invoke the closure directly (simulates schedule runner executing the task).
        $closureOrString = $tasks['prune']->command;
        self::assertInstanceOf(\Closure::class, $closureOrString);
        ($closureOrString)();

        // Only the fresh row should remain.
        $remaining = $pdo->query('SELECT COUNT(*) FROM _broadcast_log')->fetchColumn();
        self::assertSame('1', (string) $remaining, 'Stale row should have been pruned; fresh row must survive.');
    }

    #[Test]
    public function all_built_in_entries_appear_in_combined_schedule(): void
    {
        // Simulates what ScheduleEntryRegistry does: call register() on each
        // discovered ScheduleEntriesInterface and confirm both built-in providers
        // contribute their tasks to a single Schedule.
        $db = DBALDatabase::createSqlite();
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::broadcast($db);
        $storage = new BroadcastStorage($db);
        $schedule = new Schedule();

        /** @var ScheduleEntriesInterface[] $providers */
        $providers = [
            new AgentScheduleEntries(),
            new BroadcastStorageScheduleEntries($storage),
        ];

        foreach ($providers as $provider) {
            $provider->register($schedule);
        }

        $names = array_map(static fn($t) => $t->name, $schedule->tasks());

        self::assertContains(AgentScheduleEntries::TASK_PURGE, $names, 'ai:purge-runs must be registered');
        self::assertContains(AgentScheduleEntries::TASK_REAP, $names, 'ai:reap-stalled-runs must be registered');
        self::assertContains('broadcast_log_prune', $names, 'broadcast_log_prune must be registered');
        self::assertCount(3, $names, 'Exactly three built-in tasks expected');
    }
}

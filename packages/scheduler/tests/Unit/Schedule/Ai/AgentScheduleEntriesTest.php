<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Tests\Unit\Schedule\Ai;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Scheduler\Schedule;
use Waaseyaa\Scheduler\Schedule\Ai\AgentScheduleEntries;
use Waaseyaa\Scheduler\Execution\LeaseAwareCommandInterface;
use Waaseyaa\Scheduler\Execution\LeaseExecutionContext;
use Waaseyaa\Scheduler\Testing\InMemoryLeaseAuthority;
use Waaseyaa\Scheduler\Testing\InMemoryFenceGuard;

#[CoversClass(AgentScheduleEntries::class)]
final class AgentScheduleEntriesTest extends TestCase
{
    #[Test]
    public function registers_purge_and_reap_entries_with_documented_cron_expressions(): void
    {
        $schedule = new Schedule();
        $entries = new AgentScheduleEntries();

        $result = $entries->register($schedule);

        // Returned references match the registered tasks.
        self::assertSame(AgentScheduleEntries::TASK_PURGE, $result['purge']->name);
        self::assertSame(AgentScheduleEntries::CRON_PURGE, $result['purge']->expression);
        self::assertSame('0 3 * * *', $result['purge']->expression);
        self::assertSame('UTC', $result['purge']->timezone);
        self::assertTrue($result['purge']->preventOverlap);

        self::assertSame(AgentScheduleEntries::TASK_REAP, $result['reap']->name);
        self::assertSame(AgentScheduleEntries::CRON_REAP, $result['reap']->expression);
        self::assertSame('*/5 * * * *', $result['reap']->expression);
        self::assertSame('UTC', $result['reap']->timezone);
        self::assertTrue($result['reap']->preventOverlap);

        // Schedule itself reports the registered tasks.
        $tasks = $schedule->tasks();
        self::assertCount(2, $tasks);
        $names = array_map(static fn($t): string => $t->name, $tasks);
        self::assertContains('ai:purge-runs', $names);
        self::assertContains('ai:reap-stalled-runs', $names);
    }

    #[Test]
    public function injected_cli_invoker_is_called_with_command_name(): void
    {
        $schedule = new Schedule();
        $calls = [];
        $invoker = static function (string $command, LeaseExecutionContext $context) use (&$calls): int {
            $calls[] = $command;
            return 0;
        };

        $entries = new AgentScheduleEntries($invoker);
        $result = $entries->register($schedule);

        // Execute the underlying closure command for each task; that is
        // what `ScheduleRunner` would call for closure-form tasks.
        $purgeCommand = $result['purge']->command;
        $reapCommand = $result['reap']->command;
        self::assertInstanceOf(LeaseAwareCommandInterface::class, $purgeCommand);
        self::assertInstanceOf(LeaseAwareCommandInterface::class, $reapCommand);

        $purgeCommand->run(self::context('ai:purge-runs'));
        $reapCommand->run(self::context('ai:reap-stalled-runs'));

        self::assertSame(
            [AgentScheduleEntries::TASK_PURGE, AgentScheduleEntries::TASK_REAP],
            $calls,
        );
    }

    private static function context(string $domain): LeaseExecutionContext
    {
        $authority = new InMemoryLeaseAuthority();
        $handle = $authority->acquire($domain, 300_000);
        self::assertNotNull($handle);

        return new LeaseExecutionContext($authority, $handle, 300_000, new InMemoryFenceGuard());
    }

    #[Test]
    public function purge_task_is_due_at_three_am_utc(): void
    {
        $schedule = new Schedule();
        (new AgentScheduleEntries())->register($schedule);

        $tasks = $schedule->tasks();
        $purge = $tasks[0]->name === 'ai:purge-runs' ? $tasks[0] : $tasks[1];

        // 03:00 UTC — should be due.
        $due = new \DateTimeImmutable('2026-05-18T03:00:00+00:00');
        self::assertTrue($purge->isDue($due));

        // 03:05 UTC — should NOT be due.
        $notDue = new \DateTimeImmutable('2026-05-18T03:05:00+00:00');
        self::assertFalse($purge->isDue($notDue));
    }

    #[Test]
    public function reap_task_fires_every_five_minutes(): void
    {
        $schedule = new Schedule();
        (new AgentScheduleEntries())->register($schedule);

        $tasks = $schedule->tasks();
        $reap = $tasks[1]->name === 'ai:reap-stalled-runs' ? $tasks[1] : $tasks[0];

        // 12:00 — due.
        self::assertTrue($reap->isDue(new \DateTimeImmutable('2026-05-18T12:00:00+00:00')));
        // 12:05 — due.
        self::assertTrue($reap->isDue(new \DateTimeImmutable('2026-05-18T12:05:00+00:00')));
        // 12:03 — NOT due.
        self::assertFalse($reap->isDue(new \DateTimeImmutable('2026-05-18T12:03:00+00:00')));
    }
}

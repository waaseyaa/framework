<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Schedule\Ai;

use Waaseyaa\Scheduler\Execution\LeaseAwareClosureCommand;
use Waaseyaa\Scheduler\Execution\LeaseExecutionContext;
use Waaseyaa\Scheduler\Schedule;
use Waaseyaa\Scheduler\ScheduledTask;
use Waaseyaa\Scheduler\ScheduleEntriesInterface;
use Waaseyaa\Scheduler\ScheduleInterface;

/**
 * Recurring scheduler entries for the agent runtime (FR-030).
 *
 * Two tasks:
 *  - `ai:purge-runs` — daily at 03:00 UTC (`0 3 * * *`). Retention sweep.
 *  - `ai:reap-stalled-runs` — every 5 minutes (`* /5 * * * *`). Crash recovery (NFR-004).
 *
 * **Layer compliance:** this class lives in `packages/scheduler` (L0) and
 * must NOT import from `packages/cli` (L6) or `packages/ai-agent` (L5).
 * The two tasks reference the CLI commands *by name string only*; the
 * concrete invocation is performed by an injected closure (`$cliInvoker`).
 * The CLI package wires the real invoker in its own service provider; the
 * scheduler package itself only knows the cron expressions and command
 * names.
 *
 * The closure form (rather than a string-FQCN Job class) is intentional:
 * the scheduler's `ScheduleRunner` dispatches string commands as queue
 * jobs (`new ($task->command)()`), which does not fit our use case — we
 * want the CLI dispatch path, not the queue.
 *
 * Usage:
 *
 * ```php
 * $entries = new AgentScheduleEntries($cliInvoker);
 * $entries->register($schedule);
 * ```
 *
 * @api
 */
final class AgentScheduleEntries implements ScheduleEntriesInterface
{
    public const TASK_PURGE = 'ai:purge-runs';
    public const TASK_REAP = 'ai:reap-stalled-runs';

    public const CRON_PURGE = '0 3 * * *';
    public const CRON_REAP = '*/5 * * * *';

    public const TIMEZONE_UTC = 'UTC';

    /**
     * @param \Closure(string, LeaseExecutionContext): int|null $cliInvoker Callable that
     *     executes a CLI command by name and returns the exit code.
     *     When null, the registered tasks are inert no-ops — useful for
     *     tests asserting on discoverability without running anything.
     */
    public function __construct(
        private readonly ?\Closure $cliInvoker = null,
    ) {}

    /**
     * Add both entries to the supplied {@see Schedule}.
     *
     * Returns the two tasks in registration order so callers can
     * introspect them (e.g. for `bin/waaseyaa schedule:list` assertions).
     *
     * @return array{purge: ScheduledTask, reap: ScheduledTask}
     */
    public function register(ScheduleInterface $schedule): array
    {
        if (!$schedule instanceof Schedule) {
            throw new \InvalidArgumentException(
                'AgentScheduleEntries::register() requires a concrete Schedule instance to add tasks.',
            );
        }

        $purgeTask = new ScheduledTask(
            name: self::TASK_PURGE,
            expression: self::CRON_PURGE,
            command: $this->makeClosure(self::TASK_PURGE),
            preventOverlap: true,
            timezone: self::TIMEZONE_UTC,
            description: 'Daily retention sweep for agent_run and agent_audit_log (FR-006).',
        );

        $reapTask = new ScheduledTask(
            name: self::TASK_REAP,
            expression: self::CRON_REAP,
            command: $this->makeClosure(self::TASK_REAP),
            preventOverlap: true,
            timezone: self::TIMEZONE_UTC,
            description: 'Reap stalled agent runs every 5 minutes (FR-007, NFR-004).',
        );

        $schedule->add($purgeTask);
        $schedule->add($reapTask);

        return ['purge' => $purgeTask, 'reap' => $reapTask];
    }

    private function makeClosure(string $commandName): LeaseAwareClosureCommand
    {
        $invoker = $this->cliInvoker;
        if ($invoker === null) {
            return new LeaseAwareClosureCommand(static function (LeaseExecutionContext $context) use ($commandName): void {
                // Inert: no invoker wired. Callers wire a real invoker in
                // production; tests deliberately leave it null when they
                // only want to assert discoverability.
                unset($commandName);
                $context->checkpoint();
            });
        }

        return new LeaseAwareClosureCommand(static function (LeaseExecutionContext $context) use ($invoker, $commandName): void {
            $context->checkpoint();
            $invoker($commandName, $context);
            $context->checkpoint();
        });
    }
}

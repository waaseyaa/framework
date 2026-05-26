<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Classification\Schedule;

use Waaseyaa\Field\Classification\Job\HoldScanJob;
use Waaseyaa\Field\Classification\Job\PurgeJob;
use Waaseyaa\Field\Classification\Job\RedactJob;
use Waaseyaa\Scheduler\ScheduledTask;
use Waaseyaa\Scheduler\ScheduleEntriesInterface;
use Waaseyaa\Scheduler\ScheduleInterface;

/**
 * Recurring scheduler entries for the classification retention engine (FR-009).
 *
 * Three tasks (cron expressions in the CRON_* constants below):
 *  - `classification.retention.purge`     — every 6 hours, on the hour.
 *  - `classification.retention.redact`    — every 6 hours, offset 30 minutes
 *    from purge so the two sweeps do not contend for the DB.
 *  - `classification.retention.hold_scan` — daily at 03:00 UTC.
 *    Verification-only; surfaces hold-vs-purge conflicts.
 *
 * The jobs are dispatched via closures (rather than string-FQCN commands)
 * because the scheduler's queue-dispatch path instantiates string commands
 * with no constructor arguments (`new ($task->command)()`), which cannot
 * satisfy the jobs' injected dependencies. The closures capture the
 * already-resolved job instances.
 *
 * The jobs are nullable so the class stays discoverable (and its tasks
 * introspectable via `schedule:list`) even in contexts where the job
 * dependencies are not bound — in that case the registered tasks are inert
 * no-ops.
 *
 * @api
 */
final class ClassificationRetentionScheduleEntries implements ScheduleEntriesInterface
{
    public const string TASK_PURGE = 'classification.retention.purge';
    public const string TASK_REDACT = 'classification.retention.redact';
    public const string TASK_HOLD_SCAN = 'classification.retention.hold_scan';

    public const string CRON_PURGE = '0 */6 * * *';
    public const string CRON_REDACT = '30 */6 * * *';
    public const string CRON_HOLD_SCAN = '0 3 * * *';

    public const string TIMEZONE_UTC = 'UTC';

    public function __construct(
        private readonly ?PurgeJob $purgeJob = null,
        private readonly ?RedactJob $redactJob = null,
        private readonly ?HoldScanJob $holdScanJob = null,
    ) {}

    /**
     * @return array<string, ScheduledTask>
     */
    public function register(ScheduleInterface $schedule): array
    {
        $purgeJob = $this->purgeJob;
        $redactJob = $this->redactJob;
        $holdScanJob = $this->holdScanJob;

        $tasks = [
            self::TASK_PURGE => new ScheduledTask(
                name: self::TASK_PURGE,
                expression: self::CRON_PURGE,
                command: static function () use ($purgeJob): void {
                    $purgeJob?->run();
                },
                preventOverlap: true,
                timezone: self::TIMEZONE_UTC,
                description: 'Purge age-eligible entities matched by purge retention policies.',
            ),
            self::TASK_REDACT => new ScheduledTask(
                name: self::TASK_REDACT,
                expression: self::CRON_REDACT,
                command: static function () use ($redactJob): void {
                    $redactJob?->run();
                },
                preventOverlap: true,
                timezone: self::TIMEZONE_UTC,
                description: 'Redact PII fields on entities matched by redact retention policies.',
            ),
            self::TASK_HOLD_SCAN => new ScheduledTask(
                name: self::TASK_HOLD_SCAN,
                expression: self::CRON_HOLD_SCAN,
                command: static function () use ($holdScanJob): void {
                    $holdScanJob?->run();
                },
                preventOverlap: true,
                timezone: self::TIMEZONE_UTC,
                description: 'Surface hold-vs-purge conflicts for admin review (verification-only).',
            ),
        ];

        foreach ($tasks as $task) {
            $schedule->add($task);
        }

        return $tasks;
    }
}

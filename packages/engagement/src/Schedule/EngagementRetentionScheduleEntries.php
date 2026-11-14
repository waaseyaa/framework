<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement\Schedule;

use Waaseyaa\Engagement\EngagementRetention;
use Waaseyaa\Scheduler\ScheduledTask;
use Waaseyaa\Scheduler\ScheduleEntriesInterface;
use Waaseyaa\Scheduler\ScheduleInterface;

/** Hourly erasure through the existing scheduler and canonical writer boundary. */
final readonly class EngagementRetentionScheduleEntries implements ScheduleEntriesInterface
{
    public function __construct(private EngagementRetention $retention) {}

    public function register(ScheduleInterface $schedule): array
    {
        if (!$this->retention->ready()) {
            return [];
        }
        $task = new ScheduledTask(
            name: 'engagement_retention',
            expression: '0 * * * *',
            command: fn(): int => $this->retention->sweep(),
            timezone: 'UTC',
            description: 'Erase due engagement content and expired submission metadata.',
        );
        $schedule->add($task);
        return ['engagement_retention' => $task];
    }
}

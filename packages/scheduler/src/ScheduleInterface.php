<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler;

/**
 * @internal
 */
interface ScheduleInterface
{
    /**
     * @return list<ScheduledTask>
     */
    public function tasks(): array;

    public function add(ScheduledTask $task): static;
}

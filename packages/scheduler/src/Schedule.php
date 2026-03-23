<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler;

final class Schedule implements ScheduleInterface
{
    /** @var list<ScheduledTask> */
    private array $tasks = [];

    public function tasks(): array
    {
        return $this->tasks;
    }

    public function add(ScheduledTask $task): static
    {
        $this->tasks[] = $task;

        return $this;
    }

    /**
     * Fluent helper to start building a task from a job class.
     */
    public function job(string $jobClass): ScheduleBuilder
    {
        return new ScheduleBuilder($this, $jobClass);
    }

    /**
     * Fluent helper to start building a task from a closure.
     */
    public function call(\Closure $callback): ScheduleBuilder
    {
        return new ScheduleBuilder($this, $callback);
    }
}

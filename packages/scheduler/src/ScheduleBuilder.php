<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler;

/**
 * Fluent API for defining scheduled tasks.
 */
final class ScheduleBuilder
{
    private string $expression = '* * * * *';
    private string $name = '';
    private bool $preventOverlap = false;
    private ?string $timezone = null;
    private ?string $description = null;

    public function __construct(
        private readonly Schedule $schedule,
        private readonly string|\Closure $command,
    ) {}

    public function cron(string $expression): self
    {
        $this->expression = $expression;

        return $this;
    }

    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }

    public function everyFiveMinutes(): self
    {
        return $this->cron('*/5 * * * *');
    }

    public function everyTenMinutes(): self
    {
        return $this->cron('*/10 * * * *');
    }

    public function everyFifteenMinutes(): self
    {
        return $this->cron('*/15 * * * *');
    }

    public function everyThirtyMinutes(): self
    {
        return $this->cron('*/30 * * * *');
    }

    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }

    public function daily(): self
    {
        return $this->cron('0 0 * * *');
    }

    public function dailyAt(string $time): self
    {
        [$hour, $minute] = explode(':', $time);

        return $this->cron("{$minute} {$hour} * * *");
    }

    public function weekly(): self
    {
        return $this->cron('0 0 * * 0');
    }

    public function monthly(): self
    {
        return $this->cron('0 0 1 * *');
    }

    public function named(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function withoutOverlapping(): self
    {
        $this->preventOverlap = true;

        return $this;
    }

    public function timezone(string $timezone): self
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function describedAs(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Register the task with the schedule and return it.
     */
    public function register(): ScheduledTask
    {
        $name = $this->name;
        if ($name === '') {
            $name = is_string($this->command) ? $this->command : 'closure-' . spl_object_id($this);
        }

        $task = new ScheduledTask(
            name: $name,
            expression: $this->expression,
            command: $this->command,
            preventOverlap: $this->preventOverlap,
            timezone: $this->timezone,
            description: $this->description,
        );

        $this->schedule->add($task);

        return $task;
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler;

use Cron\CronExpression;

final class ScheduledTask
{
    private readonly CronExpression $cronExpression;

    public function __construct(
        public readonly string $name,
        public readonly string $expression,
        public readonly string|\Closure $command,
        public readonly bool $preventOverlap = false,
        public readonly ?string $timezone = null,
        public readonly ?string $description = null,
    ) {
        $this->cronExpression = new CronExpression($this->expression);
    }

    public function isDue(\DateTimeInterface $now): bool
    {
        if ($this->timezone !== null) {
            $tz = new \DateTimeZone($this->timezone);
            $now = \DateTimeImmutable::createFromInterface($now)->setTimezone($tz);
        }

        return $this->cronExpression->isDue($now);
    }

    public function getNextRunDate(\DateTimeInterface $now): \DateTimeInterface
    {
        return $this->cronExpression->getNextRunDate($now);
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler;

use Cron\CronExpression;
use Waaseyaa\Queue\Occurrence\OccurrenceAwareMessageInterface;
use Waaseyaa\Scheduler\Execution\LeaseAwareCommandInterface;

final class ScheduledTask
{
    private readonly CronExpression $cronExpression;

    public function __construct(
        public readonly string $name,
        public readonly string $expression,
        public readonly string|\Closure|LeaseAwareCommandInterface $command,
        public readonly bool $preventOverlap = false,
        public readonly ?string $timezone = null,
        public readonly ?string $description = null,
        /**
         * Overlap-lock TTL in seconds (only meaningful when $preventOverlap).
         * Must exceed the task's expected runtime: if the lease expires mid-run
         * another node can reclaim and run concurrently. Default 300s (5 min);
         * raise it for long-running tasks. See scheduler m15 / m2.
         */
        public readonly int $lockTtl = 300,
    ) {
        if (!CronExpression::isValidExpression($this->expression)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid cron expression "%s" for scheduled task "%s".', $this->expression, $this->name),
            );
        }
        $occurrenceAwareQueueCommand = is_string($this->command)
            && is_a($this->command, OccurrenceAwareMessageInterface::class, true);
        if ($this->preventOverlap && !$this->command instanceof LeaseAwareCommandInterface && !$occurrenceAwareQueueCommand) {
            throw new \InvalidArgumentException(sprintf(
                'Overlap-protected task "%s" must use a stable lease-aware command.',
                $this->name,
            ));
        }
        if (is_string($this->command) && (!$this->preventOverlap || !$occurrenceAwareQueueCommand)) {
            throw new \InvalidArgumentException(sprintf(
                'Queued task "%s" must enable overlap protection and implement OccurrenceAwareMessageInterface.',
                $this->name,
            ));
        }
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

    public function scheduleGeneration(): string
    {
        $commandIdentity = is_string($this->command) ? $this->command : $this->command::class;

        return hash('sha256', implode("\0", [
            $this->name,
            $this->expression,
            $this->timezone ?? '',
            $commandIdentity,
            $this->preventOverlap ? 'overlap' : 'parallel',
            (string) $this->lockTtl,
        ]));
    }
}

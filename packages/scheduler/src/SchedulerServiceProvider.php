<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Queue\QueueInterface;
use Waaseyaa\Scheduler\Lock\DatabaseLock;
use Waaseyaa\Scheduler\Lock\InMemoryLock;
use Waaseyaa\Scheduler\Lock\LockInterface;
use Waaseyaa\Scheduler\Storage\ScheduleStateRepository;

final class SchedulerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $driver = $this->config['queue']['driver'] ?? 'sync';

        $this->singleton(ScheduleInterface::class, fn(): Schedule => new Schedule());

        $this->singleton(LockInterface::class, match ($driver) {
            'database' => fn(): DatabaseLock => new DatabaseLock(
                $this->resolve(DatabaseInterface::class),
            ),
            default => fn(): InMemoryLock => new InMemoryLock(),
        });

        $this->singleton(ScheduleRunner::class, fn(): ScheduleRunner => new ScheduleRunner(
            $this->resolve(ScheduleInterface::class),
            $this->resolve(QueueInterface::class),
            $this->resolve(LockInterface::class),
            $driver === 'database'
                ? new ScheduleStateRepository($this->resolve(DatabaseInterface::class))
                : null,
        ));
    }
}

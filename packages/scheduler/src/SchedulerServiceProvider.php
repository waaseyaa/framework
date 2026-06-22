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
        $this->singleton(ScheduleInterface::class, fn(): Schedule => new Schedule());

        // Cross-host mutual exclusion needs a SHARED store. Use the durable
        // database lock whenever a DatabaseInterface is available — regardless
        // of the queue driver, which has nothing to do with scheduler locking.
        // Only a database-less install falls back to the per-process in-memory
        // lock (which, by construction, cannot guard against other hosts).
        // Resolved lazily so the database binding (registered by a lower-layer
        // provider) is present by the time the lock is first used.
        $this->singleton(LockInterface::class, function (): LockInterface {
            $database = $this->resolveOptional(DatabaseInterface::class);

            return $database instanceof DatabaseInterface
                ? new DatabaseLock($database)
                : new InMemoryLock();
        });

        // Bind ScheduleStateRepository as a first-class container service so
        // the admin scheduler dashboard (M4B WP02 — Layer 4 ApiServiceProvider)
        // can resolve it without duplicating the repository instance. It needs
        // a real DatabaseInterface; without one, resolution throws and the
        // dashboard's resolveOptional() degrades to "no state" as before.
        $this->singleton(
            ScheduleStateRepository::class,
            function (): ScheduleStateRepository {
                $database = $this->resolveOptional(DatabaseInterface::class);
                if (!$database instanceof DatabaseInterface) {
                    throw new \RuntimeException(
                        'ScheduleStateRepository requires a DatabaseInterface; none is bound.',
                    );
                }

                return new ScheduleStateRepository($database);
            },
        );

        $this->singleton(ScheduleRunner::class, function (): ScheduleRunner {
            $hasDatabase = $this->resolveOptional(DatabaseInterface::class) instanceof DatabaseInterface;

            return new ScheduleRunner(
                $this->resolve(ScheduleInterface::class),
                $this->resolve(QueueInterface::class),
                $this->resolve(LockInterface::class),
                $hasDatabase ? $this->resolve(ScheduleStateRepository::class) : null,
            );
        });
    }
}

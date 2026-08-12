<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Queue\Occurrence\OccurrenceRuntimeInterface;
use Waaseyaa\Queue\OccurrenceQueueInterface;
use Waaseyaa\Queue\QueueInterface;
use Waaseyaa\Scheduler\Fence\DatabaseFenceGuard;
use Waaseyaa\Scheduler\Fence\FenceGuardInterface;
use Waaseyaa\Scheduler\Fence\UnavailableFenceGuard;
use Waaseyaa\Scheduler\Lease\DatabaseLease;
use Waaseyaa\Scheduler\Lease\LeaseAuthorityInterface;
use Waaseyaa\Scheduler\Lease\UnavailableLeaseAuthority;
use Waaseyaa\Scheduler\Occurrence\OccurrenceOutboxDispatcher;
use Waaseyaa\Scheduler\Occurrence\OccurrenceOutboxRepository;
use Waaseyaa\Scheduler\Occurrence\OccurrenceRepository;
use Waaseyaa\Scheduler\Occurrence\OccurrenceRepositoryInterface;
use Waaseyaa\Scheduler\Occurrence\SchedulerOccurrenceRuntime;
use Waaseyaa\Scheduler\Storage\ScheduleStateRepository;

final class SchedulerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(ScheduleInterface::class, fn(): Schedule => new Schedule());

        $this->singleton(LeaseAuthorityInterface::class, function (): LeaseAuthorityInterface {
            $database = $this->resolveOptional(DatabaseInterface::class);

            return $database instanceof DBALDatabase
                ? new DatabaseLease($database)
                : new UnavailableLeaseAuthority();
        });
        $this->singleton(FenceGuardInterface::class, function (): FenceGuardInterface {
            $database = $this->resolveOptional(DatabaseInterface::class);

            return $database instanceof DBALDatabase
                ? new DatabaseFenceGuard($database)
                : new UnavailableFenceGuard();
        });
        $this->singleton(OccurrenceRepositoryInterface::class, function (): OccurrenceRepositoryInterface {
            $database = $this->resolveOptional(DatabaseInterface::class);
            if (!$database instanceof DBALDatabase) {
                throw new \RuntimeException('Scheduled occurrences require the durable DBAL database authority.');
            }

            return new OccurrenceRepository($database);
        });
        $this->singleton(OccurrenceOutboxRepository::class, function (): OccurrenceOutboxRepository {
            $database = $this->resolveOptional(DatabaseInterface::class);
            if (!$database instanceof DBALDatabase) {
                throw new \RuntimeException('Scheduled occurrence outbox requires the durable DBAL database authority.');
            }

            return new OccurrenceOutboxRepository($database, $this->resolve(OccurrenceRepositoryInterface::class));
        });
        $this->singleton(OccurrenceRuntimeInterface::class, fn(): SchedulerOccurrenceRuntime => new SchedulerOccurrenceRuntime(
            $this->resolve(LeaseAuthorityInterface::class),
            $this->resolve(OccurrenceRepositoryInterface::class),
            $this->resolve(FenceGuardInterface::class),
        ));

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
            $queue = $this->resolve(QueueInterface::class);
            $outbox = $hasDatabase && $queue instanceof OccurrenceQueueInterface
                ? $this->resolve(OccurrenceOutboxRepository::class)
                : null;
            $dispatcher = $outbox !== null
                ? new OccurrenceOutboxDispatcher($outbox, $queue)
                : null;

            return new ScheduleRunner(
                $this->resolve(ScheduleInterface::class),
                $queue,
                $this->resolve(LeaseAuthorityInterface::class),
                $hasDatabase ? $this->resolve(ScheduleStateRepository::class) : null,
                fenceGuard: $this->resolve(FenceGuardInterface::class),
                occurrenceRepository: $hasDatabase ? $this->resolve(OccurrenceRepositoryInterface::class) : null,
                occurrenceOutbox: $outbox,
                occurrenceOutboxDispatcher: $dispatcher,
            );
        });
    }
}

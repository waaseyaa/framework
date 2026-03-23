<?php

declare(strict_types=1);

namespace Waaseyaa\Queue;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Queue\Handler\HandlerInterface;
use Waaseyaa\Queue\Handler\JobHandler;
use Waaseyaa\Queue\Storage\DatabaseFailedJobRepository;
use Waaseyaa\Queue\Storage\InMemoryFailedJobRepository;
use Waaseyaa\Queue\Transport\DbalTransport;
use Waaseyaa\Queue\Transport\InMemoryTransport;
use Waaseyaa\Queue\Transport\TransportInterface;
use Waaseyaa\Queue\Worker\Worker;

final class QueueServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $driver = $this->config['queue']['driver'] ?? 'sync';

        $this->singleton(TransportInterface::class, match ($driver) {
            'database' => fn(): DbalTransport => new DbalTransport(
                $this->resolve(DatabaseInterface::class),
            ),
            default => fn(): InMemoryTransport => new InMemoryTransport(),
        });

        $this->singleton(QueueInterface::class, match ($driver) {
            'database' => fn(): DbalQueue => new DbalQueue(
                $this->resolve(TransportInterface::class),
                $this->config['queue']['default'] ?? 'default',
            ),
            default => fn(): SyncQueue => new SyncQueue(),
        });

        $this->singleton(FailedJobRepositoryInterface::class, match ($driver) {
            'database' => fn(): DatabaseFailedJobRepository => new DatabaseFailedJobRepository(
                $this->resolve(DatabaseInterface::class),
            ),
            default => fn(): InMemoryFailedJobRepository => new InMemoryFailedJobRepository(),
        });

        $this->singleton(Worker::class, fn(): Worker => new Worker(
            $this->resolve(TransportInterface::class),
            $this->resolve(FailedJobRepositoryInterface::class),
            $this->resolveHandlers(),
        ));
    }

    /**
     * @return list<HandlerInterface>
     */
    private function resolveHandlers(): array
    {
        return [new JobHandler()];
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Scheduler\Lock\DatabaseLock;
use Waaseyaa\Scheduler\Lock\InMemoryLock;
use Waaseyaa\Scheduler\Lock\LockInterface;
use Waaseyaa\Scheduler\SchedulerServiceProvider;

#[CoversClass(SchedulerServiceProvider::class)]
final class SchedulerServiceProviderTest extends TestCase
{
    #[Test]
    public function uses_durable_database_lock_when_a_database_is_available_even_with_non_database_queue(): void
    {
        // queue.driver = 'sync' (the default). A database IS available. The
        // overlap lock must be the durable, cross-host DatabaseLock — not the
        // per-process InMemoryLock keyed off the unrelated queue driver.
        $provider = $this->provider(['queue' => ['driver' => 'sync']], DBALDatabase::createSqlite());
        $provider->register();

        self::assertInstanceOf(DatabaseLock::class, $provider->resolve(LockInterface::class));
    }

    #[Test]
    public function falls_back_to_in_memory_lock_only_without_a_database(): void
    {
        $provider = $this->provider(['queue' => ['driver' => 'sync']], null);
        $provider->register();

        self::assertInstanceOf(InMemoryLock::class, $provider->resolve(LockInterface::class));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function provider(array $config, ?DatabaseInterface $database): SchedulerServiceProvider
    {
        $provider = new SchedulerServiceProvider();
        $provider->setKernelContext('', $config, []);
        $provider->setKernelServices(new class ($database) implements KernelServicesInterface {
            public function __construct(private readonly ?DatabaseInterface $database) {}

            public function get(string $abstract): ?object
            {
                return $abstract === DatabaseInterface::class ? $this->database : null;
            }
        });

        return $provider;
    }
}

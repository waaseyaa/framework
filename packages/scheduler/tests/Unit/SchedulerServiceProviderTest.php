<?php

declare(strict_types=1);

namespace Waaseyaa\Scheduler\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Scheduler\SchedulerServiceProvider;
use Waaseyaa\Scheduler\Lease\DatabaseLease;
use Waaseyaa\Scheduler\Lease\LeaseAuthorityInterface;
use Waaseyaa\Scheduler\Lease\UnavailableLeaseAuthority;
use Waaseyaa\Scheduler\Fence\DatabaseFenceGuard;
use Waaseyaa\Scheduler\Fence\FenceGuardInterface;
use Waaseyaa\Scheduler\Fence\UnavailableFenceGuard;
use Waaseyaa\Scheduler\Occurrence\OccurrenceRepository;
use Waaseyaa\Scheduler\Occurrence\OccurrenceRepositoryInterface;

#[CoversClass(SchedulerServiceProvider::class)]
final class SchedulerServiceProviderTest extends TestCase
{
    #[Test]
    public function uses_durable_lease_and_fence_authorities_when_a_database_is_available(): void
    {
        $provider = $this->provider(['queue' => ['driver' => 'sync']], DBALDatabase::createSqlite());
        $provider->register();

        self::assertInstanceOf(DatabaseLease::class, $provider->resolve(LeaseAuthorityInterface::class));
        self::assertInstanceOf(DatabaseFenceGuard::class, $provider->resolve(FenceGuardInterface::class));
        self::assertInstanceOf(OccurrenceRepository::class, $provider->resolve(OccurrenceRepositoryInterface::class));
    }

    #[Test]
    public function refuses_lease_and_fence_effects_without_a_database(): void
    {
        $provider = $this->provider(['queue' => ['driver' => 'sync']], null);
        $provider->register();

        self::assertInstanceOf(UnavailableLeaseAuthority::class, $provider->resolve(LeaseAuthorityInterface::class));
        self::assertInstanceOf(UnavailableFenceGuard::class, $provider->resolve(FenceGuardInterface::class));
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

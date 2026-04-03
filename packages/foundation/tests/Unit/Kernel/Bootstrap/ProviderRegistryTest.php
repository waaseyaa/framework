<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel\Bootstrap;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistry;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Regression test for issue #3: service provider DI cannot resolve
 * kernel-provided services (EntityTypeManager, DatabaseInterface, etc.).
 */
#[CoversClass(ProviderRegistry::class)]
final class ProviderRegistryTest extends TestCase
{
    #[Test]
    public function provider_can_resolve_kernel_services_via_fallback(): void
    {
        $registry = new ProviderRegistry(new NullLogger());
        $database = DBALDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher();
        $entityTypeManager = new EntityTypeManager($dispatcher);

        // Use a concrete provider class defined below that resolves EntityTypeManager
        $manifest = new PackageManifest(
            providers: [KernelResolverTestProvider::class],
        );

        $providers = $registry->discoverAndRegister(
            manifest: $manifest,
            projectRoot: sys_get_temp_dir(),
            config: [],
            entityTypeManager: $entityTypeManager,
            database: $database,
            dispatcher: $dispatcher,
        );

        $this->assertCount(1, $providers);

        // The provider's register() resolves EntityTypeManager via kernel resolver
        $resolved = $providers[0]->resolve(EntityTypeManager::class);
        $this->assertSame($entityTypeManager, $resolved);
    }

    #[Test]
    public function provider_can_resolve_cross_provider_bindings(): void
    {
        $registry = new ProviderRegistry(new NullLogger());
        $database = DBALDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher();
        $entityTypeManager = new EntityTypeManager($dispatcher);

        $manifest = new PackageManifest(
            providers: [
                CrossProviderSourceProvider::class,
                CrossProviderConsumerProvider::class,
            ],
        );

        $providers = $registry->discoverAndRegister(
            manifest: $manifest,
            projectRoot: sys_get_temp_dir(),
            config: [],
            entityTypeManager: $entityTypeManager,
            database: $database,
            dispatcher: $dispatcher,
        );

        $this->assertCount(2, $providers);

        // Consumer provider resolves a binding registered by the source provider
        $resolved = $providers[1]->resolve('test.cross_provider_service');
        $this->assertInstanceOf(\stdClass::class, $resolved);
        $this->assertSame('from-source', $resolved->origin);
    }
}

/**
 * @internal Test fixture — provider that resolves EntityTypeManager from kernel.
 */
final class KernelResolverTestProvider extends ServiceProvider
{
    public function register(): void
    {
        // No local bindings — EntityTypeManager must come from kernel resolver.
    }
}

/**
 * @internal Test fixture — provider that registers a binding for cross-provider resolution.
 */
final class CrossProviderSourceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton('test.cross_provider_service', static function (): \stdClass {
            $obj = new \stdClass();
            $obj->origin = 'from-source';

            return $obj;
        });
    }
}

/**
 * @internal Test fixture — provider that consumes a binding from another provider.
 */
final class CrossProviderConsumerProvider extends ServiceProvider
{
    public function register(): void
    {
        // No local bindings — depends on CrossProviderSourceProvider's binding.
    }
}

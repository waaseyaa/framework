<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistryKernelServices;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider as BaseServiceProvider;
use Waaseyaa\Migration\Discovery\HasMigrationsInterface;
use Waaseyaa\Migration\Discovery\MigrationRegistry;
use Waaseyaa\Migration\MigrationDefinition;
use Waaseyaa\Migration\PluginFixtures\PluginFixturesTrait;
use Waaseyaa\Migration\ServiceProvider;

/**
 * Reproduces the exact G-024 kernel-boot-ordering bug (#1940 — Sheguiandah
 * pass-1) using the real production classes involved:
 * {@see \Waaseyaa\Migration\ServiceProvider}, {@see MigrationRegistry}, and
 * {@see ProviderRegistryKernelServices}.
 *
 * Why not a full `AbstractKernel::boot()` in this test: that path requires a
 * real projectRoot filesystem, a persisted entity-type manifest, and content-
 * type validation entirely unrelated to this ordering bug, which would make
 * the test heavy and fragile without proving anything more. Instead this
 * test drives {@see ProviderRegistryKernelServices}'s lazy
 * `$accessHandlerAccessor` closure through the exact same "null during
 * bootProviders(), populated afterward" transition that
 * `AbstractKernel::boot()` produces in production — `bootProviders()` (which
 * used to eagerly resolve `MigrationRegistry`, invoking every migration
 * provider's `migrations()`) runs strictly BEFORE `discoverAccessPolicies()`
 * populates the `EntityAccessHandler` the kernel-services bus serves under
 * `GateInterface`/`EntityAccessHandler`. See `AbstractKernel::boot()`
 * (`packages/foundation/src/Kernel/AbstractKernel.php`, ~lines 165-171) for
 * the real ordering this simulates.
 */
#[CoversNothing]
final class LazyRegistryKernelOrderingTest extends TestCase
{
    use PluginFixturesTrait;

    #[Test]
    public function migration_provider_resolving_gate_interface_succeeds_only_after_access_policies_are_discovered(): void
    {
        // Simulates AbstractKernel::$accessHandler — null until
        // discoverAccessPolicies() runs, exactly like the real closure wired
        // in AbstractKernel::discoverAccessPolicies().
        $accessHandler = null;
        $kernelServices = new ProviderRegistryKernelServices(
            entityTypeManager: new EntityTypeManager(new EventDispatcher()),
            database: DBALDatabase::createSqlite(),
            dispatcher: new EventDispatcher(),
            logger: new NullLogger(),
            providersAccessor: static fn(): array => [],
            accessHandlerAccessor: static function () use (&$accessHandler): ?EntityAccessHandler {
                return $accessHandler;
            },
        );

        // The application's migration-shipping provider: a real
        // ServiceProvider implementing HasMigrationsInterface whose
        // migrations() needs GateInterface to build its destination plugin —
        // exactly the Sheguiandah pass-1 shape ("the pass-1 site needed a
        // lazy-gate shim").
        $appProvider = new class() extends BaseServiceProvider implements HasMigrationsInterface {
            use PluginFixturesTrait;

            public int $migrationsCallCount = 0;
            public ?GateInterface $resolvedGate = null;

            public function register(): void {}

            public function migrations(): iterable
            {
                $this->migrationsCallCount++;
                // Resolving GateInterface off the kernel-services bus is
                // exactly what the pass-1 provider did to build its
                // EntityDestination.
                $gate = $this->resolve(GateInterface::class);
                \assert($gate instanceof GateInterface);
                $this->resolvedGate = $gate;

                yield new MigrationDefinition(
                    id: 'app_migration',
                    source: $this->fixtureSource('src'),
                    process: ['title' => 'src_title'],
                    destination: $this->fixtureDestination('dst'),
                );
            }
        };
        $appProvider->setKernelServices($kernelServices);

        $migrationProvider = new ServiceProvider();
        $migrationProvider->setKernelContext(projectRoot: \sys_get_temp_dir(), config: [], manifestFormatters: []);
        $migrationProvider->setKernelServices($kernelServices);
        $migrationProvider->withMigrationProviders([$appProvider]);

        // Step 1: register() + boot() — mirrors AbstractKernel::bootProviders(),
        // which runs BEFORE discoverAccessPolicies() populates $accessHandler.
        $migrationProvider->register();
        $migrationProvider->boot();

        // G-024: boot() must not have touched the registry — migrations()
        // must not have run yet, because GateInterface is not resolvable yet.
        self::assertSame(
            0,
            $appProvider->migrationsCallCount,
            'boot() must defer registry construction — the access handler is not live yet.',
        );

        // Step 2: mirrors AbstractKernel::discoverAccessPolicies() — the
        // access handler becomes live AFTER bootProviders() has completed.
        $accessHandler = new EntityAccessHandler([]);

        // Step 3: first registry query — mirrors the CLI resolving the
        // registry lazily at command execution, well after full kernel boot
        // (see ImportServiceProvider — every import:* command resolves
        // MigrationRegistry inside its own per-command singleton factory).
        $registry = $migrationProvider->resolve(MigrationRegistry::class);
        \assert($registry instanceof MigrationRegistry);

        self::assertTrue($registry->has('app_migration'));
        self::assertSame(
            1,
            $appProvider->migrationsCallCount,
            'the first query must build the index exactly once, now that access services are live.',
        );
        self::assertNotNull(
            $appProvider->resolvedGate,
            'migrations() must have resolved a live GateInterface once the access handler existed.',
        );

        // Idempotent: further queries must not rebuild / re-invoke migrations().
        $registry->all();
        self::assertSame(1, $appProvider->migrationsCallCount);
    }
}

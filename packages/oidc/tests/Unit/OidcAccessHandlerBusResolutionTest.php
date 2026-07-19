<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountPrincipalFactory;
use Waaseyaa\Access\AccountPrincipalFactoryInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistryKernelServices;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Oidc\OidcServiceProvider;
use Waaseyaa\Oidc\Userinfo\UserinfoController;
use Waaseyaa\Tests\Support\UserInternalFieldReaderFixture;

/**
 * Regression coverage for C-12: the kernel-services bus must publish the
 * kernel's composed {@see EntityAccessHandler} so that lazy provider factories
 * which `resolve(EntityAccessHandler::class)` succeed.
 *
 * Before C-12, {@see ProviderRegistryKernelServices::get()} had no case for
 * {@see EntityAccessHandler::class} and no provider bound it, so the lazy
 * {@see UserinfoController} factory in {@see OidcServiceProvider} threw
 * `RuntimeException "No binding registered for ...EntityAccessHandler"` the
 * moment anything resolved it. C-12 threads a late-bound access-handler
 * accessor through ProviderRegistry → ProviderRegistryKernelServices so the
 * bus returns the kernel's handler.
 *
 * These tests construct the real bus (not a stub) so the actual C-12 branch in
 * `get()` is exercised, and pin both halves: the bus resolves the handler, and
 * the OIDC consumer can build its controller through that resolution. The
 * "without accessor" cases reproduce the exact pre-fix failure, so removing the
 * bus accessor again fails CI.
 */
#[CoversClass(ProviderRegistryKernelServices::class)]
#[CoversClass(OidcServiceProvider::class)]
final class OidcAccessHandlerBusResolutionTest extends TestCase
{
    protected function tearDown(): void
    {
        // OidcServiceProvider::register() warms EntityType::fromClass(OidcClient)
        // into a static cache; clear it so the cache cannot leak into other tests.
        EntityType::clearFromClassCache();

        parent::tearDown();
    }

    #[Test]
    public function bus_resolves_entity_access_handler_through_the_accessor(): void
    {
        $handler = new EntityAccessHandler();
        $services = $this->kernelServices(static fn(): EntityAccessHandler => $handler);

        // C-12: the bus returns the kernel's composed handler verbatim.
        $this->assertSame($handler, $services->get(EntityAccessHandler::class));
    }

    #[Test]
    public function bus_returns_null_for_entity_access_handler_without_accessor(): void
    {
        // Pre-C-12 condition: no accessor wired => the handler is unresolvable
        // through the bus, which is what made resolve() throw downstream.
        $services = $this->kernelServices(null);

        $this->assertNull($services->get(EntityAccessHandler::class));
    }

    #[Test]
    public function oidc_provider_constructs_userinfo_controller_with_the_kernel_handler(): void
    {
        $handler = new EntityAccessHandler();
        $provider = $this->registeredOidcProvider(static fn(): EntityAccessHandler => $handler);

        // The exact line guarded: resolving UserinfoController must not throw
        // (it did, pre-C-12, on `resolve(EntityAccessHandler::class)`).
        $controller = $provider->resolve(UserinfoController::class);

        $this->assertInstanceOf(UserinfoController::class, $controller);

        // ...and the handler injected is precisely the one the kernel bus exposed.
        $injected = new \ReflectionProperty(UserinfoController::class, 'entityAccessHandler');
        $this->assertSame($handler, $injected->getValue($controller));
    }

    #[Test]
    public function oidc_provider_resolution_throws_without_a_bus_access_handler(): void
    {
        // Reproduces the original C-12 defect end-to-end: with no access handler
        // on the bus, OidcServiceProvider's lazy UserinfoController factory fails
        // exactly where it used to.
        $provider = $this->registeredOidcProvider(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No binding registered for ' . EntityAccessHandler::class);
        $provider->resolve(UserinfoController::class);
    }

    /**
     * Build a real ProviderRegistryKernelServices so the production C-12 branch
     * in get() is the code under test.
     *
     * @param (\Closure(): ?EntityAccessHandler)|null $accessHandlerAccessor
     */
    private function kernelServices(?\Closure $accessHandlerAccessor): KernelServicesInterface
    {
        $dispatcher = new EventDispatcher();

        $services = new ProviderRegistryKernelServices(
            entityTypeManager: new EntityTypeManager($dispatcher),
            // DBALDatabase satisfies OidcServiceProvider::resolveDatabase()'s
            // DBALDatabase instanceof guard for the AccessTokenIssuer leg.
            database: DBALDatabase::createSqlite(),
            dispatcher: $dispatcher,
            logger: new NullLogger(),
            providersAccessor: static fn(): array => [],
            accountContext: null,
            accessHandlerAccessor: $accessHandlerAccessor,
            applicationSecret: ApplicationSecret::fromEnvironmentValue(null, 'testing'),
        );

        return new readonly class ($services) implements KernelServicesInterface {
            public function __construct(private ProviderRegistryKernelServices $services) {}

            public function get(string $abstract): ?object
            {
                if ($abstract === UserInternalFieldReaderInterface::class) {
                    return new UserInternalFieldReaderFixture();
                }
                if ($abstract === AccountPrincipalFactoryInterface::class) {
                    return new AccountPrincipalFactory();
                }

                return $this->services->get($abstract);
            }
        };
    }

    /**
     * @param (\Closure(): ?EntityAccessHandler)|null $accessHandlerAccessor
     */
    private function registeredOidcProvider(?\Closure $accessHandlerAccessor): OidcServiceProvider
    {
        $provider = new OidcServiceProvider();
        $provider->setKernelContext(sys_get_temp_dir(), [], []);
        $provider->setKernelServices($this->kernelServices($accessHandlerAccessor));
        $provider->register();

        return $provider;
    }
}

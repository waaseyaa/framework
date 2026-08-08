<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Routing\CacheConfigResolver;
use Waaseyaa\Routing\Redirector;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\HttpServiceResolverInterface;
use Waaseyaa\SSR\SsrPageHandler;

// Stub controller with a custom dependency
class StubDependency
{
    public string $value = 'resolved';
}

class StubController
{
    public function __construct(
        public readonly StubDependency $dep,
    ) {}
}

class StubControllerWithDefault
{
    public function __construct(
        public readonly ?StubDependency $dep = null,
    ) {}
}

class StubControllerWithRequiredNonNullable
{
    public function __construct(
        public readonly StubDependency $dep,
        public readonly \DateTimeInterface $time,
    ) {}
}

class StubControllerWithGate
{
    public function __construct(
        public readonly EntityTypeManager $etm,
        public readonly GateInterface $gate,
    ) {}
}

class StubControllerWithRedirector
{
    public function __construct(
        public readonly Redirector $redirector,
    ) {}
}

#[CoversClass(SsrPageHandler::class)]
final class SsrPageHandlerResolverTest extends TestCase
{
    private function createHandler(?HttpServiceResolverInterface $serviceResolver = null, ?GateInterface $gate = null): SsrPageHandler
    {
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $database = DBALDatabase::createSqlite();
        $discoveryHandler = new DiscoveryApiHandler($entityTypeManager, $database);
        $cacheConfigResolver = new CacheConfigResolver([]);

        return new SsrPageHandler(
            entityTypeManager: $entityTypeManager,
            database: $database,
            renderCache: null,
            cacheConfigResolver: $cacheConfigResolver,
            discoveryHandler: $discoveryHandler,
            projectRoot: '/tmp',
            config: [],
            manifest: null,
            serviceResolver: $serviceResolver,
            gate: $gate,
        );
    }

    private function makeServiceResolver(\Closure $resolver): HttpServiceResolverInterface
    {
        return new class ($resolver) implements HttpServiceResolverInterface {
            public function __construct(private readonly \Closure $resolver) {}

            public function resolve(string $className): ?object
            {
                return ($this->resolver)($className);
            }
        };
    }

    #[Test]
    public function resolves_custom_dependency_via_service_resolver(): void
    {
        $dep = new StubDependency();
        $resolver = $this->makeServiceResolver(function (string $className) use ($dep): ?object {
            return $className === StubDependency::class ? $dep : null;
        });

        $handler = $this->createHandler($resolver);
        $twig = $this->createStub(\Twig\Environment::class);
        $account = $this->createStub(AccountInterface::class);
        $request = HttpRequest::create('/test');

        $controller = $handler->resolveControllerInstance(
            StubController::class,
            $twig,
            $account,
            $request,
        );

        $this->assertInstanceOf(StubController::class, $controller);
        $this->assertSame($dep, $controller->dep);
    }

    #[Test]
    public function resolves_pre_registered_controller_singleton_via_service_resolver(): void
    {
        $preBuilt = new StubController(new StubDependency());
        $resolver = $this->makeServiceResolver(function (string $className) use ($preBuilt): ?object {
            return $className === StubController::class ? $preBuilt : null;
        });

        $handler = $this->createHandler($resolver);
        $twig = $this->createStub(\Twig\Environment::class);
        $account = $this->createStub(AccountInterface::class);
        $request = HttpRequest::create('/test');

        $controller = $handler->resolveControllerInstance(
            StubController::class,
            $twig,
            $account,
            $request,
        );

        $this->assertSame($preBuilt, $controller);
    }

    #[Test]
    public function falls_back_to_default_when_resolver_returns_null(): void
    {
        $resolver = $this->makeServiceResolver(static fn (string $className): ?object => null);

        $handler = $this->createHandler($resolver);
        $twig = $this->createStub(\Twig\Environment::class);
        $account = $this->createStub(AccountInterface::class);
        $request = HttpRequest::create('/test');

        $controller = $handler->resolveControllerInstance(
            StubControllerWithDefault::class,
            $twig,
            $account,
            $request,
        );

        $this->assertInstanceOf(StubControllerWithDefault::class, $controller);
        $this->assertNull($controller->dep);
    }

    #[Test]
    public function works_without_service_resolver(): void
    {
        $handler = $this->createHandler(null);
        $twig = $this->createStub(\Twig\Environment::class);
        $account = $this->createStub(AccountInterface::class);
        $request = HttpRequest::create('/test');

        $controller = $handler->resolveControllerInstance(
            StubControllerWithDefault::class,
            $twig,
            $account,
            $request,
        );

        $this->assertInstanceOf(StubControllerWithDefault::class, $controller);
        $this->assertNull($controller->dep);
    }

    #[Test]
    public function throws_for_required_non_nullable_unresolvable_parameter(): void
    {
        $handler = $this->createHandler(null);
        $twig = $this->createStub(\Twig\Environment::class);
        $account = $this->createStub(AccountInterface::class);
        $request = HttpRequest::create('/test');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot resolve required parameter \$dep/');

        $handler->resolveControllerInstance(
            StubControllerWithRequiredNonNullable::class,
            $twig,
            $account,
            $request,
        );
    }

    #[Test]
    public function resolves_gate_interface_from_service_map(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $handler = $this->createHandler(gate: $gate);
        $twig = $this->createStub(\Twig\Environment::class);
        $account = $this->createStub(AccountInterface::class);
        $request = HttpRequest::create('/test');

        $controller = $handler->resolveControllerInstance(
            StubControllerWithGate::class,
            $twig,
            $account,
            $request,
        );

        $this->assertInstanceOf(StubControllerWithGate::class, $controller);
        $this->assertSame($gate, $controller->gate);
    }

    #[Test]
    public function resolves_the_request_scoped_redirector_for_controller_construction(): void
    {
        $redirector = new Redirector(new WaaseyaaRouter());
        $handler = $this->createHandler();
        $request = HttpRequest::create('/test');
        $request->attributes->set(Redirector::REQUEST_ATTRIBUTE, $redirector);

        $controller = $handler->resolveControllerInstance(
            StubControllerWithRedirector::class,
            $this->createStub(\Twig\Environment::class),
            $this->createStub(AccountInterface::class),
            $request,
        );

        self::assertInstanceOf(StubControllerWithRedirector::class, $controller);
        self::assertSame($redirector, $controller->redirector);
    }
}

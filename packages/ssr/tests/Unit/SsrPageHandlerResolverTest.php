<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Cache\CacheConfigResolver;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
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

#[CoversClass(SsrPageHandler::class)]
final class SsrPageHandlerResolverTest extends TestCase
{
    private function createHandler(?\Closure $serviceResolver = null): SsrPageHandler
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
        );
    }

    #[Test]
    public function resolves_custom_dependency_via_service_resolver(): void
    {
        $dep = new StubDependency();
        $resolver = function (string $className) use ($dep): ?object {
            return $className === StubDependency::class ? $dep : null;
        };

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
    public function falls_back_to_default_when_resolver_returns_null(): void
    {
        $resolver = fn (string $className): ?object => null;

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
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Routing\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Auth\Controller\LogoutController;
use Waaseyaa\Auth\Extension\AuthExtensionRegistry;
use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;
use Waaseyaa\Foundation\ServiceProvider\Capability\ContributesRouteMetadataInterface;
use Waaseyaa\Foundation\ServiceProvider\ExplicitHandlerServices;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Routing\AuthOidcRouteServiceProvider;
use Waaseyaa\Routing\RouteHandlerResolver;
use Waaseyaa\Routing\RouteMetadataCompiler;

#[CoversClass(AuthOidcRouteServiceProvider::class)]
final class AuthOidcRouteMetadataTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testColdBootstrapLoadsItsMetadataHelperBeforeInspection(): void
    {
        $provider = new AuthOidcRouteServiceProvider();
        $provider->register();
        self::assertTrue(class_exists(\Waaseyaa\Routing\OidcHttpRoutes::class, false), 'The metadata helper must be admitted during bootstrap.');
        $context = new RouteContributionContext($provider::class, 0);
        class_exists(\Waaseyaa\Foundation\Routing\Metadata\RouteDefinition::class);
        class_exists(\Waaseyaa\Foundation\Routing\Metadata\HandlerReference::class);
        $autoloads = [];
        $trap = static function (string $class) use (&$autoloads): void {
            $autoloads[] = $class;
            throw new \LogicException('Inspection autoloaded ' . $class);
        };
        spl_autoload_register($trap, true, true);
        try {
            $definitions = iterator_to_array($provider->routeDefinitions($context));
        } finally {
            spl_autoload_unregister($trap);
        }
        self::assertCount(12, $definitions);
        self::assertSame([], $autoloads);
    }

    public function testSelectedAuthDependencyFailureRefusesWithoutPrivateError(): void
    {
        $calls = 0;
        $provider = new AuthOidcRouteServiceProvider();
        $provider->setKernelServices(new class implements KernelServicesInterface {
            public function get(string $abstract): ?object
            {
                throw new \RuntimeException('private auth dependency');
            }
        });
        $provider->register();
        $definition = iterator_to_array($provider->routeDefinitions(new RouteContributionContext($provider::class, 0)))[5];
        $request = Request::create('/api/auth/login', 'POST');
        $request->attributes->add(['_route' => $definition->name, '_controller' => $definition->handler->id]);
        $services = new ExplicitHandlerServices($request, [$definition->handler->target => function () use ($provider, $definition, &$calls): object {
            $calls++;
            return $provider->resolve($definition->handler->target);
        }]);
        try {
            new RouteHandlerResolver($definition, $services)->resolveMatched();
            self::fail('Selected auth construction must refuse.');
        } catch (\Waaseyaa\Routing\Exception\HandlerResolutionException $error) {
            self::assertSame('resolution-failed', $error->reason);
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('private auth dependency', $error->getMessage());
        }
        self::assertSame(1, $calls);
    }

    public function testDeclarationsDoNotResolveServicesAndOptionalOidcUsesPresence(): void
    {
        $provider = new AuthOidcRouteServiceProvider();
        $provider->setKernelServices(new class implements KernelServicesInterface {
            public function get(string $abstract): ?object
            {
                throw new \LogicException('Inspection resolved ' . $abstract);
            }
        });
        $provider->register();
        self::assertInstanceOf(ContributesRouteMetadataInterface::class, $provider);
        $absent = iterator_to_array($provider->routeDefinitions(new RouteContributionContext($provider::class, 0)));
        self::assertCount(12, $absent);
        $partial = iterator_to_array($provider->routeDefinitions(new RouteContributionContext($provider::class, 0, capabilities: ['service:Waaseyaa\\Oidc\\Token\\TokenController' => true])));
        self::assertCount(13, $partial);
        $token = $partial[12];
        self::assertSame('oidc.token', $token->name);
        self::assertSame(['_public' => true, '_csrf' => false], $token->options);
        self::assertSame(['POST'], $token->methods);
        self::assertSame(12, $token->ordinal);
        self::assertSame(10, $absent[7]->priority);
    }

    public function testSelectedLogoutUsesOnlyItsDependenciesAndNonsharedFactory(): void
    {
        $extensions = AuthExtensionRegistry::defaults();
        $bus = new class ($extensions) implements KernelServicesInterface {
            public array $calls = [];
            public function __construct(private AuthExtensionRegistry $extensions) {}
            public function get(string $abstract): ?object
            {
                $this->calls[] = $abstract;
                if ($abstract !== AuthExtensionRegistry::class) {
                    throw new \LogicException('Unselected dependency');
                }
                return $this->extensions;
            }
        };
        $provider = new AuthOidcRouteServiceProvider();
        $provider->setKernelServices($bus);
        $provider->register();
        $definitions = iterator_to_array($provider->routeDefinitions(new RouteContributionContext($provider::class, 0)));
        $definition = $definitions[6];
        $request = Request::create('/api/auth/logout', 'POST');
        $request->attributes->add(['_route' => $definition->name, '_controller' => $definition->handler->id]);
        $services = new ExplicitHandlerServices($request, [LogoutController::class => fn() => $provider->resolve(LogoutController::class)]);
        $resolver = new RouteHandlerResolver($definition, $services);
        self::assertSame([], $bus->calls);
        $first = $resolver->resolveMatched();
        $second = $resolver->resolveMatched();
        self::assertInstanceOf(\Closure::class, $first);
        self::assertInstanceOf(\Closure::class, $second);
        self::assertNotSame(new \ReflectionFunction($first)->getClosureThis(), new \ReflectionFunction($second)->getClosureThis());
        self::assertSame([AuthExtensionRegistry::class, AuthExtensionRegistry::class], $bus->calls);
        $response = $first($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Logged out.', json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['meta']['message']);
        self::assertSame('/api/auth/logout', new RouteMetadataCompiler()->compileRoute($definition)->getPath());
    }

    public function testFullOidcDeclarationsPreserveFieldsAndFailuresDoNotOmitRoutes(): void
    {
        $provider = new AuthOidcRouteServiceProvider();
        $provider->register();
        $capabilities = [];
        $oidc = new \Waaseyaa\Oidc\OidcServiceProvider();
        $oidc->register();
        foreach (array_keys($oidc->getBindings()) as $key) {
            $capabilities['service:' . $key] = true;
        }
        $definitions = iterator_to_array($provider->routeDefinitions(new RouteContributionContext($provider::class, 0, capabilities: $capabilities)));
        self::assertCount(19, $definitions);
        self::assertSame(['oidc.discovery', 'oidc.jwks', 'oidc.authorize', 'oidc.token', 'oidc.revoke', 'oidc.userinfo', 'oidc.consent'], array_column(array_slice($definitions, 12), 'name'));
        self::assertSame(range(0, 18), array_column($definitions, 'ordinal'));
        $baseline = json_decode(file_get_contents(__DIR__ . '/../Fixtures/auth-oidc-route-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($definitions as $index => $route) {
            self::assertSame($baseline['routes'][$index], [
                'name' => $route->name, 'path' => $route->path, 'methods' => $route->methods,
                'options' => $route->options, 'priority' => $route->priority, 'host' => $route->host,
                'schemes' => $route->schemes, 'requirements' => $route->requirements, 'condition' => $route->condition,
            ]);
        }
        $definition = $definitions[15];
        $request = Request::create('/oidc/token', 'POST');
        $router = new \Waaseyaa\Routing\WaaseyaaRouter();
        foreach ($definitions as $route) {
            $router->addRoute($route->name, new RouteMetadataCompiler()->compileRoute($route));
        }
        $request->attributes->add($router->matchRequest($request));
        $calls = 0;
        $services = new ExplicitHandlerServices($request, [$definition->handler->target => static function () use (&$calls): object {
            $calls++;
            throw new \RuntimeException('Private secret must not leak');
        }]);
        self::assertSame(0, $calls);
        try {
            new RouteHandlerResolver($definition, $services)->resolveMatched();
            self::fail('Declared unavailable handler must refuse.');
        } catch (\Waaseyaa\Routing\Exception\HandlerResolutionException $error) {
            self::assertSame('resolution-failed', $error->reason);
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('Private secret', $error->getMessage());
        }
        self::assertSame(1, $calls);
        self::assertSame('oidc.token', $router->matchRequest($request)['_route']);
        foreach ($definitions as $definition) {
            self::assertTrue($definition->options['_public']);
            self::assertSame('', $definition->host);
            self::assertSame([], $definition->requirements);
        }
    }
}

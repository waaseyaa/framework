<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Api\Http\Router\EngagementApiRouter;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Kernel\KernelHandlerContainer;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Routing\RouteHandlerResolver;
use Waaseyaa\Routing\RouteMetadataCompiler;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversClass(ApiServiceProvider::class)]
final class ApiServiceProviderEngagementTest extends TestCase
{
    #[Test]
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function absent_engagement_provider_contributes_no_routes_or_router(): void
    {
        self::assertFalse(class_exists('Waaseyaa\\Engagement\\EngagementServiceProvider', false));
        $original = null;
        foreach (spl_autoload_functions() ?: [] as $autoload) {
            if (is_array($autoload) && $autoload[0] instanceof \Composer\Autoload\ClassLoader) {
                $original = $autoload[0];
                break;
            }
        }
        self::assertInstanceOf(\Composer\Autoload\ClassLoader::class, $original);
        $classMap = array_filter(
            $original->getClassMap(),
            static fn(string $path, string $class): bool => !str_starts_with($class, 'Waaseyaa\\Engagement\\'),
            ARRAY_FILTER_USE_BOTH,
        );
        $loader = new \Composer\Autoload\ClassLoader();
        foreach ($original->getPrefixesPsr4() as $prefix => $paths) {
            if (!str_starts_with($prefix, 'Waaseyaa\\Engagement\\')) {
                $loader->setPsr4($prefix, $paths);
            }
        }
        $loader->setPsr4('', $original->getFallbackDirsPsr4());
        $loader->addClassMap($classMap);
        $original->unregister();
        $loader->register();
        self::assertFalse(class_exists('Waaseyaa\\Engagement\\EngagementServiceProvider'));

        $provider = new ApiServiceProvider();
        $provider->register();
        $context = new RouteContributionContext(ApiServiceProvider::class, 0, capabilities: [
            'api.route.content_search' => false,
            'api.route.mcp' => false,
            'api.route.catalog' => false,
            'api.route.ai_catalog' => false,
            'api.route.engagement' => false,
        ]);

        $definitions = iterator_to_array($provider->routeDefinitions($context));
        self::assertSame([], array_values(array_filter($definitions, static fn($route): bool => str_starts_with($route->name, 'api.engagement.'))));
        self::assertSame([], $provider->middleware(new EntityTypeManager(new \Symfony\Component\EventDispatcher\EventDispatcher())));

        $manager = new EntityTypeManager(new \Symfony\Component\EventDispatcher\EventDispatcher());
        $legacyRouter = new WaaseyaaRouter();
        $provider->routes($legacyRouter, $manager);
        self::assertNull($legacyRouter->getRouteCollection()->get('api.engagement.comment_show'));
        $database = \Waaseyaa\Database\DBALDatabase::createSqlite(':memory:');
        $kernel = new HttpKernel(sys_get_temp_dir());
        (new \ReflectionProperty(AbstractKernel::class, 'entityTypeManager'))->setValue($kernel, $manager);
        (new \ReflectionProperty(HttpKernel::class, 'discoveryHandler'))->setValue($kernel, new DiscoveryApiHandler($manager, $database));
        $routers = iterator_to_array($provider->httpDomainRouters($kernel));
        self::assertNotContains(EngagementApiRouter::class, array_map(static fn(object $router): string => $router::class, $routers));
    }

    #[Test]
    public function installed_provider_keeps_service_resolution_lazy_and_reports_broken_binding_as_503(): void
    {
        $bus = new class implements KernelServicesInterface {
            public int $reads = 0;
            public function get(string $abstract): ?object
            {
                $this->reads++;
                throw new \RuntimeException('Service unavailable.');
            }
        };
        $provider = new ApiServiceProvider();
        $provider->setKernelServices($bus);
        $provider->register();
        $readsAfterRegistration = $bus->reads;
        $router = $provider->resolve(EngagementApiRouter::class);
        self::assertInstanceOf(EngagementApiRouter::class, $router);
        self::assertSame($readsAfterRegistration, $bus->reads);

        $request = Request::create('/engagement/v1/targets/node/1/summary', 'GET');
        $request->attributes->set('_controller', EngagementApiRouter::class . '::handle');
        $request->attributes->set('_engagement_action', 'summary');
        $request->attributes->set('_authorization_principal', new AuthorizationPrincipal(1, true, [], [], 'test'));
        $response = $router->handle($request);
        self::assertSame(503, $response->getStatusCode());
        self::assertGreaterThan($readsAfterRegistration, $bus->reads);
    }

    #[Test]
    public function engagement_route_metadata_inspection_does_not_resolve_services(): void
    {
        $bus = new class implements KernelServicesInterface {
            public int $reads = 0;
            public bool $poison = false;
            public function get(string $abstract): ?object
            {
                $this->reads++;
                if ($this->poison) {
                    throw new \LogicException('Metadata inspection resolved a runtime service.');
                }
                return null;
            }
        };
        $provider = new ApiServiceProvider();
        $provider->setKernelServices($bus);
        $provider->register();
        $bus->poison = true;
        $bus->reads = 0;
        $context = new RouteContributionContext(ApiServiceProvider::class, 0, capabilities: [
            'api.route.content_search' => false,
            'api.route.mcp' => false,
            'api.route.catalog' => false,
            'api.route.ai_catalog' => false,
            'api.route.engagement' => true,
        ]);

        $definitions = iterator_to_array($provider->routeDefinitions($context));
        $routes = array_values(array_filter($definitions, static fn($route): bool => str_starts_with($route->name, 'api.engagement.')));
        self::assertCount(11, $routes);
        self::assertSame(0, $bus->reads);
        $byAction = [];
        foreach ($routes as $route) {
            self::assertTrue($route->options['_engagement']);
            self::assertSame('Waaseyaa\\Api\\Http\\Router\\EngagementApiRouter', $route->handler->target);
            self::assertSame('handle', $route->handler->method);
            $byAction[$route->defaults['_engagement_action']] = $route;
        }
        self::assertSame(['GET', 'HEAD'], $byAction['summary']->methods);
        self::assertSame(['PUT'], $byAction['like']->methods);
        self::assertTrue($byAction['like']->options['_csrf']);
        self::assertSame(['POST'], $byAction['commentKey']->methods);
        self::assertTrue($byAction['commentKey']->options['_csrf']);
        self::assertSame(['GET', 'HEAD', 'POST'], array_values(array_unique(array_merge($byAction['comments']->methods, $byAction['createComment']->methods))));
        self::assertSame(['GET', 'HEAD'], $byAction['replies']->methods);
        self::assertSame(['PATCH'], $byAction['edit']->methods);
        self::assertTrue($byAction['edit']->options['_csrf']);
        self::assertSame(['DELETE'], $byAction['remove']->methods);
        self::assertSame(['POST'], $byAction['moderateRemove']->methods);
        self::assertSame(['POST'], $byAction['summaries']->methods);
        self::assertFalse($byAction['summaries']->options['_csrf']);
        self::assertTrue($byAction['summaries']->options['_public']);
        self::assertSame('/engagement/v1/targets/{type}/{id}/summary', $byAction['summary']->path);
        self::assertSame('/engagement/v1/targets/{type}/{id}/like', $byAction['like']->path);
        self::assertSame('/engagement/v1/targets/{type}/{id}/comment-keys', $byAction['commentKey']->path);
        self::assertSame('/engagement/v1/targets/{type}/{id}/comments', $byAction['comments']->path);
        self::assertSame('/engagement/v1/targets/{type}/{id}/comments', $byAction['createComment']->path);
        self::assertSame('/engagement/v1/comments/{uuid}/replies', $byAction['replies']->path);
        self::assertSame('/engagement/v1/comments/{uuid}', $byAction['show']->path);
        self::assertSame('/engagement/v1/comments/{uuid}', $byAction['edit']->path);
        self::assertSame('/engagement/v1/comments/{uuid}', $byAction['remove']->path);
        self::assertSame('/engagement/v1/comments/{uuid}/moderation-removal', $byAction['moderateRemove']->path);
        self::assertSame('/engagement/v1/summaries', $byAction['summaries']->path);
    }

    #[Test]
    public function legacy_route_registration_uses_all_settled_paths_actions_and_csrf_options(): void
    {
        $provider = new ApiServiceProvider();
        $provider->register();
        $router = new WaaseyaaRouter();
        $provider->routes($router, new EntityTypeManager(new \Symfony\Component\EventDispatcher\EventDispatcher()));
        $collection = $router->getRouteCollection();
        $expected = [
            'summary' => ['/engagement/v1/targets/{type}/{id}/summary', 'summary', ['GET', 'HEAD'], null],
            'like' => ['/engagement/v1/targets/{type}/{id}/like', 'like', ['PUT'], true],
            'comment_keys' => ['/engagement/v1/targets/{type}/{id}/comment-keys', 'commentKey', ['POST'], true],
            'comments' => ['/engagement/v1/targets/{type}/{id}/comments', 'comments', ['GET', 'HEAD'], null],
            'create_comment' => ['/engagement/v1/targets/{type}/{id}/comments', 'createComment', ['POST'], true],
            'replies' => ['/engagement/v1/comments/{uuid}/replies', 'replies', ['GET', 'HEAD'], null],
            'comment_show' => ['/engagement/v1/comments/{uuid}', 'show', ['GET', 'HEAD'], null],
            'comment_edit' => ['/engagement/v1/comments/{uuid}', 'edit', ['PATCH'], true],
            'comment_remove' => ['/engagement/v1/comments/{uuid}', 'remove', ['DELETE'], true],
            'moderation_remove' => ['/engagement/v1/comments/{uuid}/moderation-removal', 'moderateRemove', ['POST'], true],
            'summaries' => ['/engagement/v1/summaries', 'summaries', ['POST'], false],
        ];

        foreach ($expected as $suffix => [$path, $action, $methods, $csrf]) {
            $route = $collection->get('api.engagement.' . $suffix);
            self::assertNotNull($route, 'Missing legacy engagement route ' . $suffix);
            self::assertSame($path, $route->getPath());
            self::assertSame($methods, $route->getMethods());
            self::assertSame($action, $route->getDefault('_engagement_action'));
            self::assertTrue($route->getOption('_engagement'));
            self::assertSame($csrf, $route->getOption('_csrf'));
        }
    }

    #[Test]
    public function compiled_comment_route_dispatches_through_router_closure_using_route_default(): void
    {
        $provider = new ApiServiceProvider();
        $provider->register();
        $context = new RouteContributionContext(ApiServiceProvider::class, 0, capabilities: [
            'api.route.content_search' => false,
            'api.route.mcp' => false,
            'api.route.catalog' => false,
            'api.route.ai_catalog' => false,
            'api.route.engagement' => true,
        ]);
        $definitions = iterator_to_array($provider->routeDefinitions($context));
        $logger = new class implements \Waaseyaa\Foundation\Log\LoggerInterface {
            public ?\Throwable $exception = null;
            public function emergency(string|\Stringable $message, array $context = []): void {}
            public function alert(string|\Stringable $message, array $context = []): void {}
            public function critical(string|\Stringable $message, array $context = []): void { $this->exception = $context['exception'] ?? null; }
            public function error(string|\Stringable $message, array $context = []): void {}
            public function warning(string|\Stringable $message, array $context = []): void {}
            public function notice(string|\Stringable $message, array $context = []): void {}
            public function info(string|\Stringable $message, array $context = []): void {}
            public function debug(string|\Stringable $message, array $context = []): void {}
            public function log(\Waaseyaa\Foundation\Log\LogLevel $level, string|\Stringable $message, array $context = []): void {}
        };
        foreach ([
            ['api.engagement.summary', '/engagement/v1/targets/node/77/summary', 'summary'],
            ['api.engagement.comment_show', '/engagement/v1/comments/00000000-0000-4000-8000-000000000001', 'show'],
        ] as [$routeName, $path, $action]) {
            $definition = current(array_filter($definitions, static fn(RouteDefinition $route): bool => $route->name === $routeName));
            self::assertInstanceOf(RouteDefinition::class, $definition);
            $compiled = new RouteMetadataCompiler()->compileRoute($definition);
            $collection = new RouteCollection();
            $collection->add($definition->name, $compiled);
            $request = Request::create($path, 'GET');
            $request->attributes->add((new UrlMatcher($collection, new RequestContext()))->match($request->getPathInfo()));
            $request->attributes->set('_route_object', $compiled);
            $request->attributes->set('_authorization_principal', new AuthorizationPrincipal(1, true, [], [], 'compiled-route-test'));
            self::assertSame($action, $request->attributes->get('_engagement_action'));
            self::assertTrue($compiled->getOption('_engagement'));

            $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
            $handler = new RouteHandlerResolver($definition, $services)->resolveMatched();
            $request->attributes->set('_controller', $handler);
            $logger->exception = null;
            $response = new ControllerDispatcher([], $logger)->dispatch($request);

            self::assertSame(503, $response->getStatusCode(), 'Broken lazy service resolution must reach engagement and fail unavailable, not return router 404. Error: ' . $logger->exception?->getMessage());
        }
    }
}

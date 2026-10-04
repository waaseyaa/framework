<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Api\ContentSearch\AtomicRateLimiterAdapter;
use Waaseyaa\Api\ContentSearch\SearchPackageContentSearchAdapter;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Api\Controller\ContentSearchController;
use Waaseyaa\Api\Http\Router\ContentSearchApiRouter;
use Waaseyaa\Auth\AtomicRateLimiterInterface;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Kernel\KernelHandlerContainer;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Routing\Exception\HandlerResolutionException;
use Waaseyaa\Routing\RouteHandlerResolver;
use Waaseyaa\Search\SearchProviderInterface;
use Waaseyaa\Search\SearchResult;

#[CoversClass(ApiServiceProvider::class)]
final class ContentSearchTerminalBindingTest extends TestCase
{
    public function testSuccessfulMalformedAndLimitedRequestsPreservePrincipalAndPolicy(): void
    {
        $principal = new AuthorizationPrincipal(7, true, ['authenticated'], [], 'claims-7');
        foreach ([['/api/content/search?q=public', true, 200], ['/api/content/search?unknown=public', true, 400], ['/api/content/search?q=public', false, 429]] as [$uri, $allowed, $status]) {
            $search = $this->createMock(SearchProviderInterface::class);
            $search->expects($status === 200 ? self::exactly(2) : self::never())->method('search')->with(self::anything(), self::identicalTo($principal))->willReturn(new SearchResult(0, 0, 1, 20, []));
            $limiter = $this->createMock(AtomicRateLimiterInterface::class);
            $limiter->expects(self::exactly($status === 400 ? 0 : ($allowed ? 4 : 2)))->method('consume')->willReturnCallback(static function (string $key, int $max, int $window) use ($allowed): bool {
                self::assertSame($key === 'api-content-search:global' ? 9 : 3, $max);
                self::assertSame(17, $window);
                return $allowed;
            });
            [$provider, $bus] = $this->provider([SearchProviderInterface::class => $search, AtomicRateLimiterInterface::class => $limiter]);
            $legacy = new ContentSearchApiRouter(new ContentSearchController(new SearchPackageContentSearchAdapter($search), new AtomicRateLimiterAdapter($limiter), 3, 9, 17));
            $request = $this->request($uri, $principal);
            $request->attributes->set('_controller', ContentSearchApiRouter::CONTROLLER);
            $expected = new ControllerDispatcher([$legacy])->dispatch($request);
            $actual = $this->dispatch($provider, $bus, $request);
            self::assertSame($status, $actual->getStatusCode());
            self::assertSame($expected->getContent(), $actual->getContent());
            foreach (['Content-Type', 'Cache-Control', 'Retry-After'] as $header) {
                self::assertSame($expected->headers->get($header), $actual->headers->get($header));
            }
            self::assertSame([SearchProviderInterface::class, AtomicRateLimiterInterface::class, 'Waaseyaa\\Foundation\\Log\\LoggerInterface'], $bus->reads);
        }
    }

    public function testContextIsValidatedBeforeLazyOptionalServicesAndFailuresKeep503(): void
    {
        foreach ([
            [SearchProviderInterface::class => null],
            [SearchProviderInterface::class => new \stdClass(), AtomicRateLimiterInterface::class => new \stdClass()],
            [SearchProviderInterface::class => new \RuntimeException('private backend detail')],
            [SearchProviderInterface::class => $this->createStub(SearchProviderInterface::class), AtomicRateLimiterInterface::class => new \stdClass()],
        ] as $bindings) {
            $bindings['Waaseyaa\\Foundation\\Log\\LoggerInterface'] = new \RuntimeException('private logging detail');
            [$provider, $bus] = $this->provider($bindings);
            $missingContext = $this->dispatch($provider, $bus, new Request());
            self::assertSame(500, $missingContext->getStatusCode());
            self::assertSame([], $bus->reads);
            $response = $this->dispatch($provider, $bus, $this->request('/api/content/search?q=public', new AuthorizationPrincipal(0, false, [], [], 'anonymous')));
            self::assertSame(503, $response->getStatusCode());
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            self::assertStringNotContainsString('private', $response->getContent());
            $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $payload['errors'][0]['meta']['correlation_id']);
        }
    }

    public function testDisabledConfigurationRefusesSelectionWithoutServiceReads(): void
    {
        [$provider, $bus] = $this->provider([], false);
        try {
            $this->dispatch($provider, $bus, new Request());
            self::fail('A disabled handler must refuse selection.');
        } catch (HandlerResolutionException $error) {
            self::assertStringContainsString('resolution-failed', $error->getMessage());
        }
        self::assertSame([], $bus->reads);
    }

    private function request(string $uri, AuthorizationPrincipal $principal): Request
    {
        $request = Request::create($uri);
        $request->attributes->set('_account', $principal);
        $request->attributes->set('_authorization_principal', $principal);
        $request->attributes->set('_broadcast_storage', new \ReflectionClass(BroadcastStorage::class)->newInstanceWithoutConstructor());
        return $request;
    }

    private function provider(array $bindings, bool $enabled = true): array
    {
        $bus = new class ($bindings) implements KernelServicesInterface {
            public array $reads = [];
            public function __construct(private array $bindings) {}
            public function get(string $abstract): ?object
            {
                $this->reads[] = $abstract;
                $value = $this->bindings[$abstract] ?? null;
                if ($value instanceof \Throwable) {
                    throw $value;
                }
                return $value;
            }
        };
        $provider = new ApiServiceProvider();
        $provider->setKernelContext(sys_get_temp_dir(), ['api' => ['content_search' => ['enabled' => $enabled, 'rate_limit' => ['identity_max' => 3, 'global_max' => 9, 'window_seconds' => 17]]]], []);
        $provider->setKernelServices($bus);
        $provider->register();
        $bus->reads = [];
        return [$provider, $bus];
    }

    private function dispatch(ApiServiceProvider $provider, KernelServicesInterface $bus, Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $definition = new RouteDefinition('fixture.search', '/api/content/search', HandlerReference::fromString('class:' . ContentSearchApiRouter::class . '::handle'), sourceId: 'fixture.api', ordinal: 0);
        $request->attributes->set('_route', $definition->name);
        $request->attributes->set('_controller', $definition->handler->id);
        $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
        self::assertTrue($services->has(ContentSearchApiRouter::class));
        $before = $bus->reads;
        $handler = new RouteHandlerResolver($definition, $services)->resolveMatched();
        self::assertSame($before, $bus->reads);
        self::assertNotSame($provider->resolve(ContentSearchApiRouter::class), $provider->resolve(ContentSearchApiRouter::class));
        self::assertSame($before, $bus->reads);
        $request->attributes->set('_controller', $handler);
        return new ControllerDispatcher([])->dispatch($request);
    }
}

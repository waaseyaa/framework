<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Kernel\HttpRouteComposer;
use Waaseyaa\Foundation\Kernel\KernelHandlerContainer;
use Waaseyaa\Foundation\Routing\Metadata\FoundationRouteDefinitions;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteCompositionEpoch;
use Waaseyaa\Foundation\Routing\Metadata\RouteCompositionException;
use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\Routing\Metadata\RouteParticipationCompiler;
use Waaseyaa\Foundation\Routing\Metadata\ValidatedRouteParticipation;
use Waaseyaa\Foundation\ServiceProvider\Capability\ContributesRouteMetadataInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class HttpRouteComposerTest extends TestCase
{
    public function testMixedCohortUsesOnePathAndConstructsHandlersOnlyAfterMatching(): void
    {
        $pure = new BridgePureProvider();
        $legacy = new BridgeLegacyProvider();
        $providers = [new BridgeNoopProvider(), $pure, $legacy];
        [$token, $contexts] = $this->admit($providers);
        $composer = new HttpRouteComposer(new EntityTypeManager(new EventDispatcher()), $providers, $token, $contexts, new KernelHandlerContainer($providers, []));
        self::assertSame(0, $pure->definitions);
        $request = Request::create('/metadata');
        $request->headers->set('X-Value', 'first');
        $router = $composer->router($request);
        $request->attributes->add($router->matchRequest($request));
        self::assertSame(1, $pure->definitions);
        self::assertSame(0, $pure->legacyCalls);
        self::assertSame(1, $legacy->calls);
        self::assertSame(0, $pure->factories);
        $request->attributes->set('_controller', $composer->resolveMatched($request));
        self::assertSame('first', new ControllerDispatcher([])->dispatch($request)->getContent());
        self::assertSame(1, $pure->factories);
        $second = Request::create('/metadata');
        $second->headers->set('X-Value', 'second');
        $second->attributes->add($composer->router($second)->matchRequest($second));
        $second->attributes->set('_controller', $composer->resolveMatched($second));
        self::assertSame('second', new ControllerDispatcher([])->dispatch($second)->getContent());
        self::assertSame(2, $pure->factories);
        self::assertSame(1, $pure->definitions);
        self::assertSame(1, $legacy->calls);
        self::assertSame('legacy', $composer->mode());
    }

    public function testCanonicalRefusesLegacyBeforeAnyHookOrContribution(): void
    {
        $pure = new BridgePureProvider();
        $legacy = new BridgeLegacyProvider();
        $providers = [$pure, $legacy];
        [$token, $contexts] = $this->admit($providers);
        try {
            new HttpRouteComposer(new EntityTypeManager(new EventDispatcher()), $providers, $token, $contexts, new KernelHandlerContainer($providers, []), mode: 'canonical');
            self::fail('Canonical cannot adapt legacy contributors.');
        } catch (RouteCompositionException $error) {
            self::assertSame('legacy-contributor', $error->reason);
            self::assertSame(0, $pure->definitions);
            self::assertSame(0, $legacy->calls);
        }
    }

    public function testPureCohortReusesTheCompleteSnapshotAndReturnsIsolatedRouters(): void
    {
        $pure = new BridgePureProvider();
        $providers = [$pure];
        [$token, $contexts] = $this->admit($providers);
        $epoch = new RouteCompositionEpoch($token, 'http');
        $epoch->ready([BridgePureProvider::class => $pure], $contexts, FoundationRouteDefinitions::builtins(), FoundationRouteDefinitions::terminal());
        $snapshot = $epoch->snapshot();
        $composer = new HttpRouteComposer(new EntityTypeManager(new EventDispatcher()), $providers, $token, $contexts, new KernelHandlerContainer($providers, []), $snapshot, 'canonical');
        $first = $composer->router(Request::create('/metadata'));
        $first->removeRoute('metadata');
        $second = $composer->router(Request::create('/metadata'));
        self::assertSame('metadata', $second->match('/metadata')['_route']);
        self::assertSame(1, $pure->definitions);
        self::assertSame(0, $pure->factories);
        self::assertSame('canonical', $composer->mode());
    }

    public function testMissingExplicitBindingRefusesWithoutConstructionOrRetry(): void
    {
        $pure = new BridgePureProvider();
        $pure->bindHandler = false;
        $legacy = new BridgeLegacyProvider();
        $providers = [$pure, $legacy];
        [$token, $contexts] = $this->admit($providers);
        $composer = new HttpRouteComposer(new EntityTypeManager(new EventDispatcher()), $providers, $token, $contexts, new KernelHandlerContainer($providers, []));
        foreach ([1, 2] as $attempt) {
            try {
                $composer->router(Request::create('/metadata'));
                self::fail('Missing declarations must refuse.');
            } catch (RouteCompositionException $error) {
                self::assertSame('handler-unavailable', $error->reason);
            }
        }
        self::assertSame(1, $pure->definitions);
        self::assertSame(0, $pure->factories);
        self::assertSame(1, $legacy->calls);
    }

    public function testCaughtRecursiveCollectionIsTerminalAndDoesNotPublish(): void
    {
        $pure = new BridgePureProvider();
        $legacy = new BridgeLegacyProvider();
        $providers = [$pure, $legacy];
        [$token, $contexts] = $this->admit($providers);
        $composer = new HttpRouteComposer(new EntityTypeManager(new EventDispatcher()), $providers, $token, $contexts, new KernelHandlerContainer($providers, []));
        $legacy->onRoutes = static function () use ($composer): void {
            try {
                $composer->router(Request::create('/metadata'));
            } catch (RouteCompositionException) {
            }
        };
        foreach ([1, 2] as $attempt) {
            try {
                $composer->router(Request::create('/metadata'));
                self::fail('Caught recursion must refuse.');
            } catch (RouteCompositionException $error) {
                self::assertSame('collecting', $error->reason);
            }
        }
        self::assertSame(1, $pure->definitions);
        self::assertSame(1, $legacy->calls);
        self::assertSame(0, $pure->factories);
    }

    public function testLegacyOverrideDoesNotResolveTheReplacedMetadataHandler(): void
    {
        $pure = new BridgePureProvider();
        $pure->bindHandler = false;
        $legacy = new BridgeLegacyProvider();
        $providers = [$pure, $legacy];
        [$token, $contexts] = $this->admit($providers);
        $legacy->onRoutes = static function (\Waaseyaa\Routing\WaaseyaaRouter $router): void {
            $router->removeRoute('metadata');
            $router->addRoute('metadata', \Waaseyaa\Routing\RouteBuilder::create('/metadata')->controller(static fn(): Response => new Response('replacement'))->allowAll()->build());
        };
        $composer = new HttpRouteComposer(new EntityTypeManager(new EventDispatcher()), $providers, $token, $contexts, new KernelHandlerContainer($providers, []));
        $request = Request::create('/metadata');
        $request->attributes->add($composer->router($request)->matchRequest($request));
        self::assertNull($composer->resolveMatched($request));
        self::assertSame('replacement', new ControllerDispatcher([])->dispatch($request)->getContent());
        self::assertSame(0, $pure->factories);
    }

    public function testDeniedMiddlewareDoesNotConstructSelectedHandler(): void
    {
        $pure = new BridgePureProvider();
        $legacy = new BridgeLegacyProvider();
        $providers = [$pure, $legacy];
        [$token, $contexts] = $this->admit($providers);
        $composer = new HttpRouteComposer(new EntityTypeManager(new EventDispatcher()), $providers, $token, $contexts, new KernelHandlerContainer($providers, []));
        $request = Request::create('/metadata');
        $request->attributes->add($composer->router($request)->matchRequest($request));
        $request->headers->set('Content-Length', '4096');
        $terminal = new class ($composer) implements \Waaseyaa\Foundation\Middleware\HttpHandlerInterface {
            public function __construct(private HttpRouteComposer $composer) {}
            public function handle(Request $request): Response
            {
                $request->attributes->set('_controller', $this->composer->resolveMatched($request));
                return new ControllerDispatcher([])->dispatch($request);
            }
        };
        $response = new \Waaseyaa\Foundation\Middleware\BodySizeLimitMiddleware(maxBytes: 1024)->process($request, $terminal);
        self::assertSame(413, $response->getStatusCode());
        self::assertSame(0, $pure->factories);
    }

    public function testUnknownModeAndMismatchedRosterRefuseWithoutHooks(): void
    {
        $pure = new BridgePureProvider();
        $legacy = new BridgeLegacyProvider();
        $providers = [$pure, $legacy];
        [$token, $contexts] = $this->admit($providers);
        try {
            new HttpRouteComposer(new EntityTypeManager(new EventDispatcher()), $providers, $token, $contexts, new KernelHandlerContainer($providers, []), mode: 'guess');
            self::fail('Unknown mode must refuse.');
        } catch (RouteCompositionException $error) {
            self::assertSame('unsupported-mode', $error->reason);
        }
        $composer = new HttpRouteComposer(new EntityTypeManager(new EventDispatcher()), array_reverse($providers), $token, $contexts, new KernelHandlerContainer($providers, []));
        try {
            $composer->router(Request::create('/metadata'));
            self::fail('Stale roster must refuse.');
        } catch (RouteCompositionException $error) {
            self::assertSame('inventory-unavailable', $error->reason);
        }
        self::assertSame(0, $pure->definitions);
        self::assertSame(0, $legacy->calls);
    }

    public function testMalformedDeclarationRefusesWithoutLeakingOrRetrying(): void
    {
        $pure = new BridgePureProvider();
        $pure->malformed = true;
        $legacy = new BridgeLegacyProvider();
        $providers = [$pure, $legacy];
        [$token, $contexts] = $this->admit($providers);
        $composer = new HttpRouteComposer(new EntityTypeManager(new EventDispatcher()), $providers, $token, $contexts, new KernelHandlerContainer($providers, []));
        foreach ([1, 2] as $attempt) {
            try {
                $composer->router(Request::create('/metadata'));
                self::fail('Malformed contribution must refuse.');
            } catch (RouteCompositionException $error) {
                self::assertSame('contribution-failed', $error->reason);
                self::assertNull($error->getPrevious());
                self::assertStringNotContainsString('private payload', $error->getMessage());
            }
        }
        self::assertSame(1, $pure->definitions);
        self::assertSame(0, $pure->factories);
        self::assertSame(0, $legacy->calls);
    }

    /** @param list<ServiceProvider> $providers */
    private function admit(array $providers): array
    {
        $roster = array_map(static fn(ServiceProvider $provider): string => $provider::class, $providers);
        $token = ValidatedRouteParticipation::atBootstrap($roster, new RouteParticipationCompiler()->compile($roster));
        $contexts = [];
        foreach ($providers as $order => $provider) {
            $provider->register();
            if ($provider instanceof ContributesRouteMetadataInterface) {
                $contexts[$provider::class] = new RouteContributionContext($provider::class, $order);
            }
        }
        return [$token, $contexts];
    }
}

final class BridgeNoopProvider extends ServiceProvider
{
    public function register(): void {}
}

final class BridgePureProvider extends ServiceProvider implements ContributesRouteMetadataInterface
{
    public int $definitions = 0;
    public int $legacyCalls = 0;
    public int $factories = 0;
    public bool $bindHandler = true;
    public bool $malformed = false;
    public function register(): void
    {
        if ($this->bindHandler) {
            $this->bind('http.handler', function (): object {
                $this->factories++;
                return new BridgeHandler();
            });
        }
    }
    public function routeDefinitions(RouteContributionContext $context): iterable
    {
        $this->definitions++;
        if ($this->malformed) {
            yield (object) ['private payload' => true];
            return;
        }
        yield new RouteDefinition('metadata', '/metadata', HandlerReference::fromString('service:http.handler::handle'), methods: ['GET'], options: ['_public' => true], sourceId: $context->sourceId);
    }
    public function routes(\Waaseyaa\Routing\WaaseyaaRouter $router, EntityTypeManager $entityTypeManager): void
    {
        $this->legacyCalls++;
        throw new \LogicException('Declarative providers cannot invoke their legacy hook.');
    }
}

final class BridgeLegacyProvider extends ServiceProvider
{
    public ?\Closure $onRoutes = null;
    public int $calls = 0;
    public function register(): void {}
    public function routes(\Waaseyaa\Routing\WaaseyaaRouter $router, EntityTypeManager $entityTypeManager): void
    {
        $this->calls++;
        if ($this->onRoutes !== null) {
            ($this->onRoutes)($router);
        }
        $router->addRoute('legacy', \Waaseyaa\Routing\RouteBuilder::create('/legacy')->controller(static fn(): Response => new Response('legacy'))->allowAll()->methods('GET')->build());
    }
}

final class BridgeHandler
{
    public function handle(Request $request): Response
    {
        return new Response($request->headers->get('X-Value', 'missing'));
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\KernelHandlerContainer;
use Waaseyaa\Foundation\Routing\Metadata\RouteCompositionEpoch;
use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;
use Waaseyaa\Foundation\Routing\Metadata\RouteParticipationCompiler;
use Waaseyaa\Foundation\Routing\Metadata\ValidatedRouteParticipation;
use Waaseyaa\Foundation\ServiceProvider\Capability\ContributesRouteMetadataInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Routing\RouteHandlerResolver;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\SSR\Http\SeoPublicController;
use Waaseyaa\SSR\SsrServiceProvider;

final class SsrRouteMetadataTest extends TestCase
{
    public function testDeclarationsNeverResolveServicesAndAreAdmittedDeclarative(): void
    {
        $provider = new SsrServiceProvider();
        $bus = new SsrMetadataServiceBus();
        $bus->poison = true;
        $provider->setKernelServices($bus);
        self::assertInstanceOf(ContributesRouteMetadataInterface::class, $provider);
        $definitions = iterator_to_array($provider->routeDefinitions(new RouteContributionContext(SsrServiceProvider::class, 2)));
        self::assertSame(0, $bus->calls);
        self::assertSame(['seo.robots_txt', 'seo.sitemap_xml', 'seo.llms_txt'], array_column($definitions, 'name'));
        foreach ($definitions as $ordinal => $definition) {
            self::assertSame($ordinal, $definition->ordinal);
            self::assertSame(SsrServiceProvider::class, $definition->sourceId);
            self::assertSame(['GET'], $definition->methods);
            self::assertSame(['_public' => true], $definition->options);
            self::assertSame(10, $definition->priority);
            self::assertSame('class', $definition->handler->kind);
            self::assertSame(SeoPublicController::class, $definition->handler->target);
        }
        self::assertSame('declarative', new RouteParticipationCompiler()->compile([SsrServiceProvider::class])['records'][0]['kind']);
    }

    public function testCompatibilityProjectionPreservesEveryOriginalField(): void
    {
        $router = new WaaseyaaRouter();
        new SsrServiceProvider()->routes($router, new EntityTypeManager(new EventDispatcher()));
        $actual = [];
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            $actual[$name] = ['path' => $route->getPath(), 'defaults' => $route->getDefaults(), 'requirements' => $route->getRequirements(), 'options' => $route->getOptions(), 'methods' => $route->getMethods(), 'host' => $route->getHost(), 'schemes' => $route->getSchemes(), 'condition' => $route->getCondition()];
        }
        self::assertSame(json_decode(file_get_contents(__DIR__ . '/../Fixtures/seo-route-baseline.json'), true, 512, JSON_THROW_ON_ERROR), $actual);
    }

    public function testExplicitFactoryIsLazyNonsharedAndUsesTrustedOrigin(): void
    {
        $provider = new SsrServiceProvider();
        $provider->setKernelContext('', ['api_catalog' => ['base_url' => 'https://trusted.example']], []);
        $bus = new SsrMetadataServiceBus();
        $provider->setKernelServices($bus);
        $provider->register();
        $bus->poison = true;
        $snapshot = $this->snapshot($provider);
        $request = Request::create('https://evil.example/robots.txt');
        $container = new KernelHandlerContainer([$provider], []);
        self::assertTrue($container->explicitServices($request)->has(SeoPublicController::class));
        self::assertSame(0, $bus->calls);
        $router = new WaaseyaaRouter(snapshot: $snapshot);
        $request->attributes->add($router->matchRequest($request));
        $resolver = new RouteHandlerResolver($snapshot, $container->explicitServices($request));
        $bus->poison = false;
        $first = $resolver->resolveMatched();
        $second = $resolver->resolveMatched();
        self::assertNotSame(new \ReflectionFunction($first)->getClosureThis(), new \ReflectionFunction($second)->getClosureThis());
        $response = $first($request);
        self::assertStringContainsString('Sitemap: https://trusted.example/sitemap.xml', $response->getContent());
        self::assertStringNotContainsString('evil.example', $response->getContent());
        self::assertGreaterThan(0, $bus->calls);
    }

    public function testMissingRequiredAndFailingOptionalServicesRefuseSelectedExecution(): void
    {
        foreach ([null, EntityTypeManager::class, \Waaseyaa\Seo\Discovery\PublicUrlPolicyInterface::class] as $failure) {
            $provider = new SsrServiceProvider();
            $bus = new SsrMetadataServiceBus();
            $bus->failure = $failure;
            $bus->missingManager = $failure === null;
            $provider->setKernelServices($bus);
            $provider->register();
            $snapshot = $this->snapshot($provider);
            self::assertSame(0, $bus->calls);
            $request = Request::create('/robots.txt');
            $request->attributes->add(new WaaseyaaRouter(snapshot: $snapshot)->matchRequest($request));
            $resolver = new RouteHandlerResolver($snapshot, new KernelHandlerContainer([$provider], [])->explicitServices($request));
            try {
                $resolver->resolveMatched();
                self::fail('Selected dependency failure cannot become optional absence.');
            } catch (\Waaseyaa\Routing\Exception\HandlerResolutionException $error) {
                self::assertSame('resolution-failed', $error->reason);
                self::assertNull($error->getPrevious());
                self::assertStringNotContainsString('private dependency', $error->getMessage());
            }
        }
    }

    private function snapshot(SsrServiceProvider $provider): \Waaseyaa\Foundation\Routing\Metadata\RouteSnapshot
    {
        $roster = [SsrServiceProvider::class];
        $token = ValidatedRouteParticipation::atBootstrap($roster, new RouteParticipationCompiler()->compile($roster));
        $epoch = new RouteCompositionEpoch($token, 'http');
        $epoch->ready([SsrServiceProvider::class => $provider], [SsrServiceProvider::class => new RouteContributionContext(SsrServiceProvider::class, 0)]);
        return $epoch->snapshot();
    }
}

final class SsrMetadataServiceBus implements KernelServicesInterface
{
    public int $calls = 0;
    public bool $poison = false;
    public ?string $failure = null;
    public bool $missingManager = false;
    public function get(string $abstract): ?object
    {
        $this->calls++;
        if ($this->poison) {
            throw new \LogicException('Inspection cannot resolve execution services.');
        }
        if ($abstract === $this->failure) {
            throw new \RuntimeException('private dependency failure');
        }
        return $abstract === EntityTypeManager::class && !$this->missingManager ? new EntityTypeManager(new EventDispatcher()) : null;
    }
}

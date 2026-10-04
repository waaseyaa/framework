<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Api\Controller\AiCatalogController;
use Waaseyaa\Api\Controller\ApiCatalogController;
use Waaseyaa\Api\Discovery\AiCatalog;
use Waaseyaa\Api\Discovery\ApiCatalog;
use Waaseyaa\Api\Http\Router\AiCatalogRouter;
use Waaseyaa\Api\Http\Router\ApiCatalogRouter;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Discovery\ApiCatalog\ApiCatalogEntry;
use Waaseyaa\Foundation\Discovery\ApiCatalog\ApiCatalogTarget;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Kernel\KernelHandlerContainer;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesApiCatalogEntriesInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Routing\Exception\HandlerResolutionException;
use Waaseyaa\Routing\RouteHandlerResolver;

#[CoversClass(ApiServiceProvider::class)]
final class CatalogTerminalBindingsTest extends TestCase
{
    public function testBootFinalizedCatalogBindingsPreserveHttpRepresentations(): void
    {
        [$provider, $bus] = $this->provider(true);
        $provider->boot();
        $bus->reject = true;
        $catalog = new ApiCatalog('https://cms.example', [new ApiCatalogEntry(new ApiCatalogTarget('/public', 'application/json'))]);
        $aiCatalog = new AiCatalog('https://cms.example', $provider->aiCatalogEntries());
        foreach ([
            [new ApiCatalogRouter(new ApiCatalogController($catalog)), 'api.catalog'],
            [new AiCatalogRouter(new AiCatalogController($aiCatalog)), 'ai.catalog'],
        ] as [$router, $alias]) {
            foreach ([['GET', '', false, 200], ['HEAD', '', false, 200], ['GET', '', true, 304], ['GET', 'text/html', false, 406]] as [$method, $accept, $conditional, $status]) {
                $request = Request::create('/fixture', $method);
                $request->headers->set('Accept', $accept);
                if ($conditional) {
                    $request->headers->set('If-None-Match', '*');
                }
                $request->attributes->set('_controller', $alias);
                $expected = new ControllerDispatcher([$router])->dispatch($request);
                $definition = new RouteDefinition('fixture.catalog', '/fixture', HandlerReference::fromString('class:' . $router::class . '::handle'), sourceId: 'fixture.api', ordinal: 0);
                $request->attributes->set('_route', $definition->name);
                $request->attributes->set('_controller', $definition->handler->id);
                $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
                self::assertTrue($services->has($router::class));
                $handler = new RouteHandlerResolver($definition, $services)->resolveMatched();
                $request->attributes->set('_controller', $handler);
                $actual = new ControllerDispatcher([])->dispatch($request);
                self::assertSame($status, $expected->getStatusCode());
                self::assertSame($status, $actual->getStatusCode());
                self::assertSame($expected->getContent(), $actual->getContent());
                foreach (['Content-Type', 'Content-Length', 'Cache-Control', 'ETag', 'Link', 'Vary', 'X-Content-Type-Options', 'Access-Control-Allow-Origin'] as $header) {
                    self::assertSame($expected->headers->get($header), $actual->headers->get($header));
                }
                self::assertNotSame($provider->resolve($router::class), $provider->resolve($router::class));
            }
        }
    }

    public function testUnfinishedAndDisabledCatalogsRefuseSelectedConstruction(): void
    {
        foreach ([false, true] as $boot) {
            [$provider, $bus] = $this->provider(false);
            if ($boot) {
                $provider->boot();
            }
            foreach ([ApiCatalogRouter::class, AiCatalogRouter::class] as $router) {
                $definition = new RouteDefinition('fixture.catalog', '/fixture', HandlerReference::fromString('class:' . $router . '::handle'), sourceId: 'fixture.api', ordinal: 0);
                $request = new Request(attributes: ['_route' => $definition->name, '_controller' => $definition->handler->id]);
                $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
                self::assertTrue($services->has($router));
                try {
                    new RouteHandlerResolver($definition, $services)->resolveMatched();
                    self::fail('Unavailable catalog must refuse.');
                } catch (HandlerResolutionException $error) {
                    self::assertStringContainsString('resolution-failed', $error->getMessage());
                }
            }
        }
    }

    private function provider(bool $enabled): array
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $provider = new ApiServiceProvider();
        $provider->setKernelContext('/tmp/test-project', [
            'api_catalog' => ['enabled' => $enabled, 'base_url' => 'https://cms.example'],
            'ai_catalog' => ['enabled' => $enabled, 'base_url' => 'https://cms.example'],
        ], []);
        $bus = new class ($manager) implements KernelServicesInterface {
            public bool $reject = false;
            public function __construct(private EntityTypeManager $manager) {}
            public function get(string $abstract): ?object
            {
                if ($this->reject) {
                    throw new \LogicException('Catalog execution must reuse boot-finalized values.');
                }
                return $abstract === EntityTypeManager::class ? $this->manager : null;
            }
        };
        $provider->setKernelServices($bus);
        $provider->withApiCatalogEntryProviders([new class implements ProvidesApiCatalogEntriesInterface {
            public function apiCatalogEntries(): array
            {
                return [new ApiCatalogEntry(new ApiCatalogTarget('/public', 'application/json'))];
            }
        }]);
        $provider->withAiCatalogEntryProviders([$provider]);
        $provider->register();
        return [$provider, $bus];
    }
}

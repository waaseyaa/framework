<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Bimaaji\BimaajiServiceProvider;
use Waaseyaa\Bimaaji\Introspection\JsonApi\JsonApiIntrospectionProvider;
use Waaseyaa\Bimaaji\Introspection\PublicSurface\PublicSurfaceProvider;
use Waaseyaa\Bimaaji\Introspection\Routing\RoutingIntrospectionProvider;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteCompositionException;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\Routing\Metadata\RouteSnapshot;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

#[CoversClass(BimaajiServiceProvider::class)]
final class CanonicalRouteAuthorityTest extends TestCase
{
    public function testMalformedKernelAuthorityCannotFallBackToACollection(): void
    {
        $provider = new BimaajiServiceProvider();
        $provider->setKernelServices(new class implements KernelServicesInterface {
            public function get(string $abstract): ?object
            {
                if ($abstract === RouteSnapshot::class) {
                    return new \stdClass();
                }
                throw new \LogicException('Malformed authority must not read a legacy collection.');
            }
        });
        $provider->register();
        $section = $provider->resolve(RoutingIntrospectionProvider::class);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid canonical route authority');
        $section->provide();
    }

    public function testFinalCustodyRefusesEvenWhenTheLastSectionCatchesItsMutationFailure(): void
    {
        foreach ([false, true] as $strict) {
            $failed = false;
            $mutator = new class ($failed) implements \Waaseyaa\Bimaaji\Graph\GraphSectionProviderInterface {
                public function __construct(private bool &$failed) {}
                public function getKey(): string
                {
                    return 'last';
                }
                public function provide(): \Waaseyaa\Bimaaji\Graph\GraphSection
                {
                    $this->failed = true;
                    return new \Waaseyaa\Bimaaji\Graph\GraphSection('last', '1.0', []);
                }
            };
            $generator = new \Waaseyaa\Bimaaji\Graph\ApplicationGraphGenerator([$mutator], strict: $strict, routeAuthorityCheck: static function () use (&$failed): void {
                if ($failed) {
                    throw new RouteCompositionException('inputs-unavailable', 'Refused final custody.');
                }
            });
            try {
                $generator->generate();
                self::fail('A graph escaped after final custody failed.');
            } catch (RouteCompositionException $failure) {
                self::assertSame('inputs-unavailable', $failure->reason);
            }
        }
    }

    public function testCachedProvidersReadCompletedAuthorityAgainAndNeverFallBackOnRefusal(): void
    {
        $snapshot = new RouteSnapshot([new RouteDefinition('application.item', '/api/item/{id}', HandlerReference::fromString('class:Application\\ItemController::show'), methods: ['GET'], defaults: ['_entity_type' => 'item'], options: ['_json_api' => true, '_public' => true], sourceId: 'application')], []);
        $bus = new class ($snapshot) implements KernelServicesInterface {
            public bool $failed = false;
            public int $reads = 0;
            public int $legacyReads = 0;
            public function __construct(public readonly RouteSnapshot $snapshot) {}
            public function get(string $abstract): ?object
            {
                if ($abstract === RouteSnapshot::class) {
                    $this->reads++;
                    if ($this->failed) {
                        throw new RouteCompositionException('inputs-unavailable', 'Refused fixture authority.');
                    }
                    return $this->snapshot;
                }
                $this->legacyReads++;
                throw new \LogicException('Legacy collection must not be read.');
            }
        };
        $provider = new BimaajiServiceProvider();
        $provider->setKernelServices($bus);
        $provider->register();
        $sections = [];
        foreach ([RoutingIntrospectionProvider::class, PublicSurfaceProvider::class, JsonApiIntrospectionProvider::class] as $class) {
            $section = $provider->resolve($class);
            self::assertSame(0, $bus->legacyReads);
            $sections[] = $section;
            $data = $section->provide()->data;
            self::assertSame('/api/item/{id}', $data['application.item']['path']);
            if ($section instanceof JsonApiIntrospectionProvider) {
                self::assertSame('item', $data['application.item']['entity_type']);
            }
        }
        self::assertSame(3, $bus->reads);
        $bus->failed = true;
        foreach ($sections as $section) {
            try {
                $section->provide();
                self::fail('A cached section bypassed failed authority.');
            } catch (RouteCompositionException $failure) {
                self::assertSame('inputs-unavailable', $failure->reason);
            }
        }
        self::assertSame(6, $bus->reads);
        self::assertSame(0, $bus->legacyReads);
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Routing\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteCompositionEpoch;
use Waaseyaa\Foundation\Routing\Metadata\RouteCompositionException;
use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\Routing\Metadata\RouteParticipationCompiler;
use Waaseyaa\Foundation\Routing\Metadata\ValidatedRouteParticipation;
use Waaseyaa\Foundation\ServiceProvider\Capability\ContributesRouteMetadataInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversClass(RouteCompositionEpoch::class)]
#[CoversClass(RouteCompositionException::class)]
#[CoversClass(RouteParticipationCompiler::class)]
#[CoversClass(ValidatedRouteParticipation::class)]
#[CoversClass(WaaseyaaRouter::class)]
final class RouteCompositionEpochTest extends TestCase
{
    public function testWholeSourceCollectionHasStablePriorityAndDetachedInputs(): void
    {
        $builtin = new RouteDefinition('builtin', '/builtin', HandlerReference::fromString('builtin:render.page'), sourceId: 'foundation.builtin');
        $terminal = new RouteDefinition('terminal', '/{path}', HandlerReference::fromString('builtin:render.page'), sourceId: 'foundation.terminal');
        $high = new RouteDefinition('high', '/high', HandlerReference::fromString('builtin:render.page'), priority: 10, sourceId: 'foundation.terminal', ordinal: 1);
        $inputs = ['api' => ['exposed' => true]];
        $before = [$builtin];
        $after = [$terminal, $high];
        $inventory = $this->inventory([PureRouteProvider::class]);
        $epoch = new RouteCompositionEpoch($inventory, 'cli');
        $epoch->ready([PureRouteProvider::class => new PureRouteProvider()], [PureRouteProvider::class => new RouteContributionContext(PureRouteProvider::class, 0)], $before, $after, $inputs);
        $before[0] = $terminal;
        $after = [];
        $inputs['api']['exposed'] = false;
        $snapshot = $epoch->snapshot();
        self::assertSame(['high', 'builtin', 'report', 'terminal'], array_column($snapshot->routes, 'name'));
        self::assertSame(['api' => ['exposed' => true]], $snapshot->inputs['declarations']);
        self::assertSame(['foundation.builtin', PureRouteProvider::class, 'foundation.terminal'], $snapshot->inputs['sources']);
        self::assertSame($snapshot, $epoch->snapshot());
        $http = new RouteCompositionEpoch($inventory, 'http');
        $http->ready([PureRouteProvider::class => new PureRouteProvider()], [PureRouteProvider::class => new RouteContributionContext(PureRouteProvider::class, 0)], [$builtin], [$terminal, $high], ['api' => ['exposed' => true]]);
        self::assertSame($snapshot->identity, $http->snapshot()->identity);
    }

    public function testCrossSourceDuplicatePoisonsWholeSnapshot(): void
    {
        $provider = new PureRouteProvider();
        $epoch = new RouteCompositionEpoch($this->inventory([PureRouteProvider::class]), 'cli');
        $epoch->ready([PureRouteProvider::class => $provider], [PureRouteProvider::class => new RouteContributionContext(PureRouteProvider::class, 0)], [new RouteDefinition('report', '/builtin', HandlerReference::fromString('builtin:render.page'), sourceId: 'foundation.builtin')]);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $epoch->snapshot();
                self::fail('Duplicate source names must refuse the entire snapshot.');
            } catch (RouteCompositionException $exception) {
                self::assertSame('contribution-failed', $exception->reason);
            }
        }
        self::assertSame('failed', $epoch->state());
        self::assertSame(1, $provider->calls);
    }

    public function testSharedInputsBindIdentityEvenWithoutDeclarativeProviders(): void
    {
        $inventory = $this->inventory([NoRoutesProvider::class]);
        $left = new RouteCompositionEpoch($inventory, 'cli');
        $right = new RouteCompositionEpoch($inventory, 'cli');
        $left->ready([], [], [], [], ['exposed' => true]);
        $right->ready([], [], [], [], ['exposed' => false]);
        self::assertNotSame($left->snapshot()->identity, $right->snapshot()->identity);
    }

    public function testStaticCohortsRejectWrongSourceOrdinalAndMapShapeBeforeReadiness(): void
    {
        foreach (['source', 'ordinal', 'shape'] as $case) {
            $epoch = new RouteCompositionEpoch($this->inventory([]), 'cli');
            $route = new RouteDefinition('builtin', '/builtin', HandlerReference::fromString('builtin:render.page'), sourceId: $case === 'source' ? 'other' : 'foundation.builtin', ordinal: $case === 'ordinal' ? 1 : 0);
            try {
                $epoch->ready([], [], $case === 'shape' ? ['named' => $route] : [$route]);
                self::fail('Invalid static source must refuse readiness.');
            } catch (RouteCompositionException $exception) {
                self::assertSame('unavailable', $exception->reason);
            }
            self::assertSame('unavailable', $epoch->state());
        }
    }

    public function testLegacyCohortCannotPublishStaticSourcesAsACompleteGraph(): void
    {
        $epoch = new RouteCompositionEpoch($this->inventory([LegacyRouteProvider::class]), 'cli');
        $epoch->ready([], [], [new RouteDefinition('builtin', '/', HandlerReference::fromString('builtin:render.page'), sourceId: 'foundation.builtin')]);
        $this->expectException(RouteCompositionException::class);
        $this->expectExceptionMessage('Legacy route contributor');
        try {
            $epoch->snapshot();
        } finally {
            self::assertSame('failed', $epoch->state());
            self::assertSame(0, LegacyRouteProvider::$legacyCalls);
        }
    }

    public function testInvalidSharedInputsDoNotAdmitPartialReadinessOrLeakDetails(): void
    {
        $epoch = new RouteCompositionEpoch($this->inventory([]), 'cli');
        try {
            $epoch->ready([], [], [], [], ['private-detail' => new \stdClass()]);
            self::fail('Execution state cannot become declaration inputs.');
        } catch (RouteCompositionException $exception) {
            self::assertSame('unavailable', $exception->reason);
            self::assertStringNotContainsString('private-detail', $exception->getMessage());
        }
        self::assertSame('unavailable', $epoch->state());
        $epoch->ready([], [], [], [], ['enabled' => true]);
        self::assertSame(['enabled' => true], $epoch->snapshot()->inputs['declarations']);
    }

    private function inventory(array $roster): ValidatedRouteParticipation
    {
        return ValidatedRouteParticipation::atBootstrap($roster, new RouteParticipationCompiler()->compile($roster));
    }

    public function testParticipationDistinguishesInheritedNoopParentTraitAndPureCapability(): void
    {
        $roster = [NoRoutesProvider::class, ParentLegacyProvider::class, TraitLegacyProvider::class, PureRouteProvider::class];
        $compiled = new RouteParticipationCompiler()->compile($roster);
        self::assertSame(['none', 'legacy', 'legacy', 'declarative'], array_column($compiled['records'], 'kind'));
        self::assertSame(NoRoutesProvider::class, $compiled['records'][0]['provider']);
        self::assertStringNotContainsString('C:', json_encode($compiled, JSON_THROW_ON_ERROR));
        self::assertSame($compiled, new RouteParticipationCompiler()->compile($roster));
        self::assertSame(0, LegacyRouteProvider::$legacyCalls);
    }

    public function testAnonymousProviderIdentityCannotLeakSourceLocations(): void
    {
        $provider = new class extends NoRoutesProvider {};
        $this->expectException(RouteCompositionException::class);
        $this->expectExceptionMessage('requires concrete service providers');
        new RouteParticipationCompiler()->compile([$provider::class]);
    }

    public function testInventoryRefusesMissingUnknownOrStaleRecordsAtBootstrap(): void
    {
        $roster = [PureRouteProvider::class];
        $original = new RouteParticipationCompiler()->compile($roster);
        foreach (['missing', 'unknown', 'stale', 'compiler'] as $case) {
            $changed = $original;
            if ($case === 'missing') {
                $changed['records'] = [];
            }
            if ($case === 'unknown') {
                $changed['records'][0]['kind'] = 'guess';
            }
            if ($case === 'stale') {
                $changed['records'][0]['source_digest'] = str_repeat('0', 64);
            }
            if ($case === 'compiler') {
                $changed['compiler_identity'] = str_repeat('0', 64);
            }
            try {
                ValidatedRouteParticipation::atBootstrap($roster, $changed);
                self::fail('Invalid participation must refuse.');
            } catch (RouteCompositionException $exception) {
                self::assertSame('inventory-unavailable', $exception->reason);
            }
        }
    }

    public function testNoopAndPureProviderPublishOnceWithoutLegacyHookInvocation(): void
    {
        $provider = new PureRouteProvider();
        $epoch = new RouteCompositionEpoch($this->inventory([NoRoutesProvider::class, PureRouteProvider::class]), 'cli');
        $epoch->ready([PureRouteProvider::class => $provider], [PureRouteProvider::class => new RouteContributionContext(PureRouteProvider::class, 1)]);
        $first = $epoch->snapshot();
        self::assertSame($first, $epoch->snapshot());
        self::assertSame(1, $provider->calls);
        self::assertSame('/report', $first->routes[0]->path);
        self::assertSame(0, LegacyRouteProvider::$legacyCalls);
        self::assertSame('complete', $epoch->state());
    }

    public function testLegacyCohortRefusesWholeSnapshotWithoutCallingHooks(): void
    {
        $epoch = new RouteCompositionEpoch($this->inventory([LegacyRouteProvider::class]), 'cli');
        $epoch->ready([], []);
        try {
            $epoch->snapshot();
            self::fail('Legacy cohorts cannot publish partial metadata.');
        } catch (RouteCompositionException $exception) {
            self::assertSame('legacy-contributor', $exception->reason);
            self::assertStringContainsString(LegacyRouteProvider::class, $exception->getMessage());
        }
        self::assertSame(0, LegacyRouteProvider::$legacyCalls);
        self::assertSame('failed', $epoch->state());
    }

    public function testEarlyRestrictedAndRecursiveAccessRefuseExplicitly(): void
    {
        $inventory = $this->inventory([PureRouteProvider::class]);
        $early = new RouteCompositionEpoch($inventory, 'cli');
        try {
            $early->snapshot();
            self::fail('Early access must refuse.');
        } catch (RouteCompositionException $exception) {
            self::assertSame('unavailable', $exception->reason);
        }
        $restricted = new RouteCompositionEpoch($inventory, 'schema-sync');
        try {
            $restricted->ready([], []);
            self::fail('Restricted profiles cannot become runtime epochs.');
        } catch (RouteCompositionException $exception) {
            self::assertSame('unsupported-profile', $exception->reason);
        }
        $provider = new PureRouteProvider();
        $recursive = new RouteCompositionEpoch($inventory, 'cli');
        $provider->duringContribution = static fn() => $recursive->snapshot();
        $recursive->ready([PureRouteProvider::class => $provider], [PureRouteProvider::class => new RouteContributionContext(PureRouteProvider::class, 0)]);
        try {
            $recursive->snapshot();
            self::fail('Recursive collection must refuse.');
        } catch (RouteCompositionException $exception) {
            self::assertSame('collecting', $exception->reason);
        }
        self::assertSame('failed', $recursive->state());
    }

    public function testFailedEpochNeverRetriesOrPublishesAFormerPartialResult(): void
    {
        $provider = new PureRouteProvider();
        $provider->fail = true;
        $epoch = new RouteCompositionEpoch($this->inventory([PureRouteProvider::class]), 'http');
        $epoch->ready([PureRouteProvider::class => $provider], [PureRouteProvider::class => new RouteContributionContext(PureRouteProvider::class, 0)]);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $epoch->snapshot();
                self::fail('Failed epochs cannot publish.');
            } catch (RouteCompositionException $exception) {
                self::assertSame('contribution-failed', $exception->reason);
                self::assertStringNotContainsString('private detail', $exception->getMessage());
            }
            $provider->fail = false;
        }
        self::assertSame(1, $provider->calls);
        self::assertSame('failed', $epoch->state());
    }

    public function testEquivalentHttpAndCliInputsHaveTheSameIdentity(): void
    {
        $identities = [];
        foreach (['http', 'cli'] as $profile) {
            $epoch = new RouteCompositionEpoch($this->inventory([PureRouteProvider::class]), $profile);
            $epoch->ready([PureRouteProvider::class => new PureRouteProvider()], [PureRouteProvider::class => new RouteContributionContext(PureRouteProvider::class, 0)]);
            $identities[] = $epoch->snapshot()->identity;
        }
        self::assertSame($identities[0], $identities[1]);
    }

    public function testReadinessDetachesReferencedContributorAndContextElements(): void
    {
        $provider = new PureRouteProvider();
        $admitted = $provider;
        $context = new RouteContributionContext(PureRouteProvider::class, 0, configuration: ['label' => 'original']);
        $epoch = new RouteCompositionEpoch($this->inventory([PureRouteProvider::class]), 'cli');
        $epoch->ready([PureRouteProvider::class => &$provider], [PureRouteProvider::class => &$context]);
        $provider = new PureRouteProvider();
        $context = new RouteContributionContext(PureRouteProvider::class, 0, configuration: ['label' => 'replacement']);
        $snapshot = $epoch->snapshot();
        self::assertSame(1, $admitted->calls);
        self::assertSame(0, $provider->calls);
        self::assertSame('original', $snapshot->inputs['contexts'][PureRouteProvider::class]['configuration']['label']);
    }

    public function testSwallowedRecursiveRefusalStillPoisonsTheEpoch(): void
    {
        $provider = new PureRouteProvider();
        $epoch = new RouteCompositionEpoch($this->inventory([PureRouteProvider::class]), 'cli');
        $provider->duringContribution = static function () use ($epoch): void {
            try {
                $epoch->snapshot();
            } catch (RouteCompositionException) {
            }
        };
        $epoch->ready([PureRouteProvider::class => $provider], [PureRouteProvider::class => new RouteContributionContext(PureRouteProvider::class, 0)]);
        $this->expectException(RouteCompositionException::class);
        $this->expectExceptionMessage('Recursive route composition');
        $epoch->snapshot();
    }

    public function testReadinessRefusesWrongSourceOrOrder(): void
    {
        foreach ([new RouteContributionContext('other', 0), new RouteContributionContext(PureRouteProvider::class, 1)] as $context) {
            $epoch = new RouteCompositionEpoch($this->inventory([PureRouteProvider::class]), 'cli');
            try {
                $epoch->ready([PureRouteProvider::class => new PureRouteProvider()], [PureRouteProvider::class => $context]);
                self::fail('Mismatched contribution context must refuse.');
            } catch (RouteCompositionException $exception) {
                self::assertSame('unavailable', $exception->reason);
            }
        }
    }

    public function testWrongDefinitionSourceOrdinalAndDuplicateNamesPoisonPublication(): void
    {
        foreach (['wrongSource', 'wrongOrdinal', 'duplicate'] as $mode) {
            $provider = new PureRouteProvider();
            $provider->$mode = true;
            $epoch = new RouteCompositionEpoch($this->inventory([PureRouteProvider::class]), 'cli');
            $epoch->ready([PureRouteProvider::class => $provider], [PureRouteProvider::class => new RouteContributionContext(PureRouteProvider::class, 0)]);
            try {
                $epoch->snapshot();
                self::fail('Invalid definitions must not publish a partial snapshot.');
            } catch (RouteCompositionException $exception) {
                self::assertSame('contribution-failed', $exception->reason);
            }
            self::assertSame('failed', $epoch->state());
        }
    }

    public function testBootstrapDetectsChangedParentAndInspectionNeverRefreshesSource(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $parent = sys_get_temp_dir() . '/route-parent-' . $suffix . '.php';
        $leaf = sys_get_temp_dir() . '/route-leaf-' . $suffix . '.php';
        $trait = sys_get_temp_dir() . '/route-trait-' . $suffix . '.php';
        $interface = sys_get_temp_dir() . '/route-interface-' . $suffix . '.php';
        $parentClass = 'RouteParent' . $suffix;
        $leafClass = 'RouteLeaf' . $suffix;
        $traitClass = 'RouteTrait' . $suffix;
        $interfaceClass = 'RouteInterface' . $suffix;
        try {
            file_put_contents($trait, '<?php trait ' . $traitClass . ' { public function register(): void {} }');
            file_put_contents($interface, '<?php interface ' . $interfaceClass . ' {}');
            file_put_contents($parent, '<?php class ' . $parentClass . ' extends \\Waaseyaa\\Foundation\\ServiceProvider\\ServiceProvider { use ' . $traitClass . ' { register as protected alternateRegister; } }');
            file_put_contents($leaf, '<?php class ' . $leafClass . ' extends ' . $parentClass . ' implements ' . $interfaceClass . ' {}');
            require $trait;
            require $interface;
            require $parent;
            require $leaf;
            $roster = [$leafClass];
            $compiler = new RouteParticipationCompiler();
            $before = $compiler->compile($roster);
            $token = ValidatedRouteParticipation::atBootstrap($roster, $before);
            $leafHash = hash_file('sha256', $leaf);
            self::assertSame($traitClass . '::register', $before['records'][0]['provenance'][$parentClass]['aliases']['alternateRegister']);
            foreach ([$trait, $interface] as $dependency) {
                $original = file_get_contents($dependency);
                file_put_contents($dependency, "\n// changed transitive dependency\n", FILE_APPEND);
                self::assertNotSame($before['records'][0]['source_digest'], $compiler->compile($roster)['records'][0]['source_digest']);
                file_put_contents($dependency, $original);
            }
            file_put_contents($parent, "\n// changed parent without changing leaf\n", FILE_APPEND);
            self::assertSame($leafHash, hash_file('sha256', $leaf));
            self::assertNotSame($before['records'][0]['source_digest'], $compiler->compile($roster)['records'][0]['source_digest']);
            try {
                ValidatedRouteParticipation::atBootstrap($roster, $before);
                self::fail('Changed parent must invalidate bootstrap admission.');
            } catch (RouteCompositionException $exception) {
                self::assertSame('inventory-unavailable', $exception->reason);
            }
            unlink($leaf);
            unlink($parent);
            unlink($trait);
            unlink($interface);
            $epoch = new RouteCompositionEpoch($token, 'cli');
            $epoch->ready([], []);
            self::assertSame([], $epoch->snapshot()->routes);
        } finally {
            foreach ([$leaf, $parent, $trait, $interface] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}

class NoRoutesProvider extends ServiceProvider
{
    public function register(): void {}
}

class LegacyRouteProvider extends NoRoutesProvider
{
    public static int $legacyCalls = 0;
    public function routes(WaaseyaaRouter $router, EntityTypeManager $entityTypeManager): void
    {
        self::$legacyCalls++;
        throw new \LogicException('Legacy hooks must never execute in metadata inspection.');
    }
}

class ParentLegacyProvider extends LegacyRouteProvider {}

trait LegacyRoutesTrait
{
    public function routes(WaaseyaaRouter $router, EntityTypeManager $entityTypeManager): void
    {
        throw new \LogicException('Trait legacy hook must never execute.');
    }
}

class TraitLegacyProvider extends NoRoutesProvider
{
    use LegacyRoutesTrait;
}

class PureRouteProvider extends LegacyRouteProvider implements ContributesRouteMetadataInterface
{
    public int $calls = 0;
    public bool $fail = false;
    public bool $wrongSource = false;
    public bool $wrongOrdinal = false;
    public bool $duplicate = false;
    public ?\Closure $duringContribution = null;

    public function routeDefinitions(RouteContributionContext $context): iterable
    {
        $this->calls++;
        if ($this->duringContribution !== null) {
            ($this->duringContribution)();
        }
        yield new RouteDefinition('report', '/report', HandlerReference::fromString('service:app.report::show'), sourceId: $this->wrongSource ? 'other' : $context->sourceId, ordinal: $this->wrongOrdinal ? 1 : 0);
        if ($this->duplicate) {
            yield new RouteDefinition('report', '/second', HandlerReference::fromString('service:app.report::show'), sourceId: $context->sourceId, ordinal: 1);
        }
        if ($this->fail) {
            throw new \RuntimeException('private detail');
        }
    }
}

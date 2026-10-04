<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Routing\Metadata\RouteCompositionException;
use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;
use Waaseyaa\Foundation\Routing\Metadata\RouteExposureInputs;
use Waaseyaa\Foundation\Routing\Metadata\RouteParticipationCompiler;
use Waaseyaa\Foundation\ServiceProvider\Capability\ContributesRouteMetadataInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\FinalizesProviderBootInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Tests\Support\ProcessFieldReadRuntime;

#[CoversClass(AbstractKernel::class)]
#[CoversClass(PackageManifest::class)]
#[CoversClass(PackageManifestCompiler::class)]
final class KernelRouteParticipationTest extends TestCase
{
    public function testFrozenBindingPresenceNeverConstructsServices(): void
    {
        file_put_contents($this->root . '/composer.json', json_encode(['extra' => ['waaseyaa' => ['providers' => [KernelRouteFixtureProvider::class, KernelBindingPresenceFixtureProvider::class]]]], JSON_THROW_ON_ERROR));
        $kernel = new RouteParticipationKernelFixture($this->root);
        $kernel->bootForCli();
        self::assertTrue($kernel->getRouteInputs()->capabilities['service:fixture.poison'] ?? false);
        self::assertArrayNotHasKey('service:fixture.absent', $kernel->getRouteInputs()->capabilities);
        self::assertSame($kernel->getRouteSnapshot(), $kernel->getRouteSnapshot());
    }

    public function testKernelSnapshotIsCompleteSharedAndLazy(): void
    {
        KernelSnapshotFixtureProvider::$calls = 0;
        file_put_contents($this->root . '/composer.json', json_encode(['extra' => ['waaseyaa' => ['providers' => [KernelRouteFixtureProvider::class, KernelSnapshotFixtureProvider::class]]]], JSON_THROW_ON_ERROR));
        $kernel = new RouteParticipationKernelFixture($this->root);
        try {
            $kernel->getRouteSnapshot();
            self::fail('Early snapshot access must refuse.');
        } catch (RouteCompositionException $error) {
            self::assertSame('unavailable', $error->reason);
        }
        $kernel->bootForCli();
        self::assertSame(0, KernelSnapshotFixtureProvider::$calls);
        $kernel->poisonDefinitionReads();
        $snapshot = $kernel->getRouteSnapshot();
        self::assertCount(18, $snapshot->routes);
        $names = array_column($snapshot->routes, 'name');
        self::assertSame('fixture.first', $names[0]);
        self::assertSame(['public.home', 'public.page'], array_slice($names, -2));
        self::assertSame('fixture.catchall', $names[count($names) - 3]);
        self::assertSame('fixture.catchall', new \Waaseyaa\Routing\WaaseyaaRouter(snapshot: $snapshot)->match('/any-page')['_route']);
        self::assertSame($snapshot, $kernel->getRouteSnapshot());
        self::assertSame(1, KernelSnapshotFixtureProvider::$calls);
        $projection = $snapshot->toArray();
        $projection['routes'][0]['defaults']['changed'] = true;
        self::assertArrayNotHasKey('changed', $kernel->getRouteSnapshot()->routes[0]->defaults);
    }

    public function testNoopCohortStillIncludesEveryStaticSourceAndSharedInputs(): void
    {
        $kernel = new RouteParticipationKernelFixture($this->root);
        $kernel->bootForCli();
        $snapshot = $kernel->getRouteSnapshot();
        self::assertCount(16, $snapshot->routes);
        self::assertSame('foundation.builtin', $snapshot->routes[0]->sourceId);
        self::assertSame('foundation.terminal', $snapshot->routes[15]->sourceId);
        self::assertSame($kernel->getRouteInputs()->entities, $snapshot->inputs['declarations']['entities']);
    }

    public function testLegacyCohortRefusesBeforeAnyContributorOrHookRuns(): void
    {
        KernelSnapshotFixtureProvider::$calls = 0;
        KernelLegacySnapshotFixtureProvider::$calls = 0;
        file_put_contents($this->root . '/composer.json', json_encode(['extra' => ['waaseyaa' => ['providers' => [KernelSnapshotFixtureProvider::class, KernelLegacySnapshotFixtureProvider::class]]]], JSON_THROW_ON_ERROR));
        $kernel = new RouteParticipationKernelFixture($this->root);
        $kernel->bootForCli();
        foreach ([1, 2] as $attempt) {
            try {
                $kernel->getRouteSnapshot();
                self::fail('Legacy cohort cannot publish even its static routes.');
            } catch (RouteCompositionException $error) {
                self::assertSame('legacy-contributor', $error->reason);
            }
        }
        self::assertSame(0, KernelSnapshotFixtureProvider::$calls);
        self::assertSame(0, KernelLegacySnapshotFixtureProvider::$calls);
    }

    public function testLateInputsRefuseEvenAnAlreadyPublishedSnapshot(): void
    {
        $kernel = new RouteParticipationKernelFixture($this->root);
        $kernel->captureExposureSlot = true;
        $kernel->bootForCli();
        $kernel->getRouteSnapshot();
        try {
            $kernel->exposureSlot->publish(['test' => true]);
        } catch (RouteCompositionException) {
        }
        $this->expectException(RouteCompositionException::class);
        $kernel->getRouteSnapshot();
    }

    public function testCaughtInputPoisoningDuringContributionNeverPublishes(): void
    {
        file_put_contents($this->root . '/composer.json', json_encode(['extra' => ['waaseyaa' => ['providers' => [KernelPoisoningSnapshotFixtureProvider::class]]]], JSON_THROW_ON_ERROR));
        $kernel = new RouteParticipationKernelFixture($this->root);
        $kernel->captureExposureSlot = true;
        $kernel->bootForCli();
        KernelPoisoningSnapshotFixtureProvider::$slot = $kernel->exposureSlot;
        KernelPoisoningSnapshotFixtureProvider::$calls = 0;
        try {
            foreach ([1, 2] as $attempt) {
                try {
                    $kernel->getRouteSnapshot();
                    self::fail('No snapshot may escape poisoned contribution inputs.');
                } catch (RouteCompositionException $error) {
                    self::assertSame('inputs-unavailable', $error->reason);
                }
            }
            self::assertSame(1, KernelPoisoningSnapshotFixtureProvider::$calls);
        } finally {
            KernelPoisoningSnapshotFixtureProvider::$slot = null;
        }
    }

    public function testContributionFailureDuplicateAndCaughtRecursionAreTerminal(): void
    {
        foreach (['throw', 'builtin-duplicate', 'terminal-duplicate', 'recursive'] as $mode) {
            ProcessFieldReadRuntime::reset();
            KernelFailingSnapshotFixtureProvider::$mode = $mode;
            KernelFailingSnapshotFixtureProvider::$calls = 0;
            file_put_contents($this->root . '/composer.json', json_encode(['extra' => ['waaseyaa' => ['providers' => [KernelFailingSnapshotFixtureProvider::class]]]], JSON_THROW_ON_ERROR));
            $kernel = new RouteParticipationKernelFixture($this->root);
            KernelFailingSnapshotFixtureProvider::$kernel = $kernel;
            $kernel->bootForCli();
            $first = null;
            foreach ([1, 2] as $attempt) {
                try {
                    $kernel->getRouteSnapshot();
                    self::fail('A failed kernel epoch cannot publish a partial snapshot.');
                } catch (RouteCompositionException $error) {
                    self::assertSame($mode === 'recursive' ? 'collecting' : 'contribution-failed', $error->reason);
                    self::assertStringNotContainsString('private contributor value', (string) $error);
                    $first ??= $error;
                    self::assertSame($first, $error);
                }
            }
            self::assertSame(1, KernelFailingSnapshotFixtureProvider::$calls);
        }
        KernelFailingSnapshotFixtureProvider::$kernel = null;
    }

    public function testSnapshotCannotReviveRestrictedFailedOrMissingInventory(): void
    {
        $restricted = new RouteParticipationKernelFixture($this->root);
        $restricted->bootForSchemaSync();
        $restricted->bootForCli();
        try {
            $restricted->getRouteSnapshot();
            self::fail('Restricted snapshot must refuse.');
        } catch (RouteCompositionException $error) {
            self::assertSame('unsupported-profile', $error->reason);
        }
        ProcessFieldReadRuntime::reset();
        $failed = new RouteParticipationKernelFixture($this->root);
        $failed->failFinalization = true;
        try {
            $failed->bootForCli();
        } catch (\RuntimeException) {
        }
        $failed->failFinalization = false;
        $failed->bootForCli();
        try {
            $failed->getRouteSnapshot();
            self::fail('Ordinary boot retry cannot revive snapshot authority.');
        } catch (RouteCompositionException $error) {
            self::assertSame('boot-failed', $error->reason);
        }
        ProcessFieldReadRuntime::reset();
        $missing = new RouteParticipationKernelFixture($this->root);
        $missing->suppliedManifest = new PackageManifest(providers: [KernelRouteFixtureProvider::class]);
        $missing->bootForCli();
        $this->expectException(RouteCompositionException::class);
        $missing->getRouteSnapshot();
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testFreshProcessSnapshotNeedsNoSourceReadsOrAutoload(): void
    {
        $providerClass = 'KernelSnapshotSource' . bin2hex(random_bytes(8));
        $source = $this->root . '/provider.php';
        file_put_contents($source, '<?php class ' . $providerClass . ' extends \\Waaseyaa\\Foundation\\ServiceProvider\\ServiceProvider { public function register(): void {} }');
        require $source;
        file_put_contents($this->root . '/composer.json', json_encode(['extra' => ['waaseyaa' => ['providers' => [$providerClass]]]], JSON_THROW_ON_ERROR));
        $kernel = new RouteParticipationKernelFixture($this->root);
        $kernel->poisonProjectionAutoload = true;
        try {
            $kernel->bootForCli();
            unlink($source);
            $kernel->poisonDefinitionReads();
            $snapshot = $kernel->getRouteSnapshot();
        } finally {
            $kernel->removeProjectionAutoloadTrap();
        }
        self::assertCount(16, $snapshot->routes);
        self::assertSame([], $kernel->projectionAutoloads);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testFreshProcessProjectionDoesNotAutoload(): void
    {
        $kernel = new RouteParticipationKernelFixture($this->root);
        $kernel->poisonProjectionAutoload = true;
        try {
            $kernel->bootForCli();
        } finally {
            $kernel->removeProjectionAutoloadTrap();
        }
        self::assertSame([], $kernel->projectionAutoloads);
        self::assertSame(['api' => false], $kernel->getRouteInputs()->capabilities);
    }

    public function testRuntimeBootFreezesAbsentApiInputsAndNeverRefreshesOnInspection(): void
    {
        $kernel = new RouteParticipationKernelFixture($this->root);
        try {
            $kernel->getRouteInputs();
            self::fail('Early input access must refuse.');
        } catch (RouteCompositionException $error) {
            self::assertSame('unavailable', $error->reason);
        }
        $kernel->bootForCli();
        $first = $kernel->getRouteInputs();
        self::assertSame([['id' => 'test', 'bundle_entity_type' => null, 'api_exposed' => false]], $first->entities);
        self::assertSame(['api' => false], $first->capabilities);
        $kernel->poisonDefinitionReads();
        self::assertSame($first, $kernel->getRouteInputs());
        self::assertSame([], $kernel->getRouteContributionContexts());
    }

    public function testRealApiProviderPublishesItsNarrowedMapDuringBoot(): void
    {
        file_put_contents($this->root . '/config/waaseyaa.php', "<?php return ['database' => ':memory:', 'environment' => 'testing', 'api' => ['entity_type_allowlist' => []], 'api_catalog' => ['enabled' => false], 'ai_catalog' => ['enabled' => false]];");
        file_put_contents($this->root . '/config/entity-types.php', "<?php return [new \\Waaseyaa\\Entity\\EntityType(id: 'post', label: 'Post', class: \\stdClass::class, api: true)];");
        file_put_contents($this->root . '/composer.json', json_encode(['extra' => ['waaseyaa' => ['providers' => [\Waaseyaa\Api\ApiServiceProvider::class]]]], JSON_THROW_ON_ERROR));
        $kernel = new RouteParticipationKernelFixture($this->root);
        $kernel->bootForCli();
        $capabilities = $kernel->getRouteInputs()->capabilities;
        self::assertSame([
            'api' => true,
            'api.route.content_search' => false,
            'api.route.mcp' => true,
            'api.route.catalog' => false,
            'api.route.ai_catalog' => false,
        ], array_filter($capabilities, static fn(string $name): bool => !str_starts_with($name, 'service:'), ARRAY_FILTER_USE_KEY));
        self::assertCount(23, array_filter($capabilities, static fn(string $name): bool => str_starts_with($name, 'service:'), ARRAY_FILTER_USE_KEY));
        foreach (['Waaseyaa\Api\EntityTypeApiExposurePolicy', 'Waaseyaa\Api\InternalFieldVisibilityPolicy', 'Waaseyaa\Api\Audit\AuditQueryReadModelInterface', 'Waaseyaa\Api\Controller\NotExposedController'] as $service) {
            self::assertTrue($capabilities['service:' . $service]);
        }
        self::assertFalse($kernel->getRouteInputs()->entities[0]['api_exposed']);
        self::assertSame('declarative', $kernel->getRouteParticipation()->records[0]['kind']);
    }

    public function testLatePublicationPoisonsCachedKernelInputs(): void
    {
        $kernel = new RouteParticipationKernelFixture($this->root);
        $kernel->captureExposureSlot = true;
        $kernel->bootForCli();
        $frozen = $kernel->getRouteInputs();
        try {
            $kernel->exposureSlot->publish(['test' => true]);
            self::fail('Late input publication must refuse.');
        } catch (RouteCompositionException $error) {
            self::assertSame('inputs-unavailable', $error->reason);
        }
        self::assertFalse($frozen->entities[0]['api_exposed']);
        $this->expectException(RouteCompositionException::class);
        $kernel->getRouteInputs();
    }

    public function testFinalizerRosterChangeRefusesWithoutRecomputingExposure(): void
    {
        file_put_contents($this->root . '/composer.json', json_encode(['extra' => ['waaseyaa' => ['providers' => [\Waaseyaa\Api\ApiServiceProvider::class, KernelLateEntityRouteFixtureProvider::class]]]], JSON_THROW_ON_ERROR));
        $kernel = new RouteParticipationKernelFixture($this->root);
        $kernel->bootForCli();
        self::assertCount(2, $kernel->getRouteParticipation()->records);
        $this->expectException(RouteCompositionException::class);
        $kernel->getRouteInputs();
    }

    public function testDeclaredApiWithoutPublicationCannotUseTheAbsentFallback(): void
    {
        file_put_contents($this->root . '/composer.json', json_encode(['extra' => ['waaseyaa' => ['providers' => [\Waaseyaa\Api\ApiServiceProvider::class]]]], JSON_THROW_ON_ERROR));
        $kernel = new RouteParticipationKernelFixture($this->root);
        $kernel->skipProviderBoot = true;
        $kernel->bootForCli();
        self::assertSame('declarative', $kernel->getRouteParticipation()->records[0]['kind']);
        $this->expectException(RouteCompositionException::class);
        $kernel->getRouteInputs();
    }

    public function testPureContextsAreFrozenWithoutInvokingContributors(): void
    {
        file_put_contents($this->root . '/composer.json', json_encode(['extra' => ['waaseyaa' => ['providers' => [KernelPureRouteInputFixtureProvider::class]]]], JSON_THROW_ON_ERROR));
        $kernel = new RouteParticipationKernelFixture($this->root);
        $kernel->bootForCli();
        $contexts = $kernel->getRouteContributionContexts();
        self::assertSame([KernelPureRouteInputFixtureProvider::class], array_keys($contexts));
        self::assertSame(0, $contexts[KernelPureRouteInputFixtureProvider::class]->sourceOrder);
        $kernel->poisonDefinitionReads();
        self::assertSame($contexts, $kernel->getRouteContributionContexts());
    }

    public function testInputAccessRetainsRestrictedAndFailedBootCustody(): void
    {
        $restricted = new RouteParticipationKernelFixture($this->root);
        $restricted->bootForSchemaSync();
        try {
            $restricted->getRouteInputs();
            self::fail('Restricted input access must refuse.');
        } catch (RouteCompositionException $error) {
            self::assertSame('unsupported-profile', $error->reason);
        }
        ProcessFieldReadRuntime::reset();
        $failed = new RouteParticipationKernelFixture($this->root);
        $failed->failFinalization = true;
        try {
            $failed->bootForCli();
        } catch (\RuntimeException) {
        }
        $failed->failFinalization = false;
        $failed->bootForCli();
        try {
            $failed->getRouteInputs();
            self::fail('A generic boot retry cannot revive route inputs.');
        } catch (RouteCompositionException $error) {
            self::assertSame('boot-failed', $error->reason);
        }
    }

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/route-kernel-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/config', 0o755, true);
        mkdir($this->root . '/storage', 0o755, true);
        file_put_contents($this->root . '/config/waaseyaa.php', "<?php return ['database' => ':memory:', 'environment' => 'testing'];");
        file_put_contents($this->root . '/config/entity-types.php', "<?php return [new \\Waaseyaa\\Entity\\EntityType(id: 'test', label: 'Test', class: \\stdClass::class, keys: ['id' => 'id'])];");
        file_put_contents($this->root . '/composer.json', json_encode(['extra' => ['waaseyaa' => ['providers' => [KernelRouteFixtureProvider::class]]]], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        ProcessFieldReadRuntime::reset();
        new Filesystem()->remove($this->root);
    }

    public function testCompiledParticipationSurvivesCacheRoundTrip(): void
    {
        $compiler = new PackageManifestCompiler($this->root, $this->root . '/storage');
        $compiled = $compiler->compileAndCache();
        $loaded = $compiler->load();
        self::assertSame('none', $compiled->routeParticipation['records'][0]['kind']);
        self::assertSame($compiled->routeParticipation, $loaded->routeParticipation);
        self::assertSame($compiled->routeParticipation, PackageManifest::fromArray($compiled->toArray())->routeParticipation);
    }

    public function testKernelAdmitsOnlyAfterCompleteBoot(): void
    {
        $kernel = new RouteParticipationKernelFixture($this->root);
        try {
            $kernel->getRouteParticipation();
            self::fail('Early access must refuse.');
        } catch (RouteCompositionException $error) {
            self::assertSame('unavailable', $error->reason);
        }
        $kernel->bootForCli();
        self::assertSame('unavailable', $kernel->duringFinalization);
        $token = $kernel->getRouteParticipation();
        self::assertSame($token, $kernel->getRouteParticipation());
        self::assertSame(KernelRouteFixtureProvider::class, $token->records[0]['provider']);
    }

    public function testLegacyCacheCannotClaimRouteReadiness(): void
    {
        $kernel = new RouteParticipationKernelFixture($this->root);
        $kernel->suppliedManifest = new PackageManifest(providers: [KernelRouteFixtureProvider::class]);
        $kernel->bootForCli();
        $this->expectException(RouteCompositionException::class);
        $kernel->getRouteParticipation();
    }

    public function testStaleInventoryRefusesWithoutRecompilationOnAccess(): void
    {
        $data = new RouteParticipationCompiler()->compile([KernelRouteFixtureProvider::class]);
        $data['records'][0]['source_digest'] = str_repeat('0', 64);
        $kernel = new RouteParticipationKernelFixture($this->root);
        $kernel->suppliedManifest = new PackageManifest(providers: [KernelRouteFixtureProvider::class], routeParticipation: $data);
        $kernel->bootForCli();
        foreach ([1, 2] as $attempt) {
            try {
                $kernel->getRouteParticipation();
                self::fail('Stale inventory must refuse.');
            } catch (RouteCompositionException $error) {
                self::assertSame('inventory-unavailable', $error->reason);
            }
        }
        self::assertSame($data, $kernel->getManifest()->routeParticipation);
    }

    public function testRestrictedKernelCannotBecomeARuntimeRouteAuthority(): void
    {
        $kernel = new RouteParticipationKernelFixture($this->root);
        $kernel->bootForSchemaSync();
        $kernel->bootForCli();
        try {
            $kernel->getRouteParticipation();
            self::fail('Restricted boot cannot admit runtime routes.');
        } catch (RouteCompositionException $error) {
            self::assertSame('unsupported-profile', $error->reason);
        }
    }

    public function testFailedBootPermanentlyRefusesRouteAuthority(): void
    {
        $kernel = new RouteParticipationKernelFixture($this->root);
        $kernel->failFinalization = true;
        try {
            $kernel->bootForCli();
            self::fail('Finalization must fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('private boot failure', $error->getMessage());
        }
        try {
            $kernel->getRouteParticipation();
            self::fail('Failed boot must refuse.');
        } catch (RouteCompositionException $error) {
            self::assertSame('boot-failed', $error->reason);
            self::assertStringNotContainsString('private boot failure', $error->getMessage());
        }
        $kernel->failFinalization = false;
        ProcessFieldReadRuntime::reset();
        $kernel->bootForCli();
        try {
            $kernel->getRouteParticipation();
            self::fail('Ordinary retry must not revive failed route authority.');
        } catch (RouteCompositionException $error) {
            self::assertSame('boot-failed', $error->reason);
        }
        $fresh = new RouteParticipationKernelFixture($this->root);
        ProcessFieldReadRuntime::reset();
        $fresh->bootForCli();
        self::assertSame('none', $fresh->getRouteParticipation()->records[0]['kind']);
    }

    public function testRegisteredRosterCannotOmitAnAdmittedProvider(): void
    {
        $kernel = new RouteParticipationKernelFixture($this->root);
        $kernel->omitProviders = true;
        $kernel->bootForCli();
        try {
            $kernel->getRouteParticipation();
            self::fail('A partial runtime roster must refuse.');
        } catch (RouteCompositionException $error) {
            self::assertSame('inventory-unavailable', $error->reason);
        }
    }

    public function testInspectionDoesNotReadProviderSourceAfterBootstrap(): void
    {
        $providerClass = 'RouteInspectionProvider' . bin2hex(random_bytes(8));
        $source = $this->root . '/provider.php';
        file_put_contents($source, '<?php class ' . $providerClass . ' extends \\Waaseyaa\\Foundation\\ServiceProvider\\ServiceProvider { public function register(): void {} }');
        require $source;
        file_put_contents($this->root . '/composer.json', json_encode(['extra' => ['waaseyaa' => ['providers' => [$providerClass]]]], JSON_THROW_ON_ERROR));
        $kernel = new RouteParticipationKernelFixture($this->root);
        $kernel->bootForCli();
        $admitted = $kernel->getRouteParticipation();
        unlink($source);
        self::assertSame($admitted, $kernel->getRouteParticipation());
        self::assertSame($providerClass, $admitted->records[0]['provider']);
    }

    public function testMalformedAndMissingCacheParticipationNeverTriggersGenericCacheRecovery(): void
    {
        $compiler = new PackageManifestCompiler($this->root, $this->root . '/storage');
        $compiler->compileAndCache();
        $cachePath = $this->root . '/storage/framework/packages.php';
        $valid = require $cachePath;
        foreach (['scalar', 'missing', 'nested'] as $case) {
            $data = $valid;
            if ($case === 'scalar') {
                $data['route_participation'] = 'invalid';
            } elseif ($case === 'missing') {
                unset($data['route_participation']);
            } else {
                $data['route_participation']['records'][0]['kind'] = 'unknown';
            }
            file_put_contents($cachePath, "<?php\nreturn " . var_export($data, true) . ";\n");
            $before = hash_file('sha256', $cachePath);
            ProcessFieldReadRuntime::reset();
            $kernel = new RouteParticipationKernelFixture($this->root);
            $kernel->loadCachedManifest = true;
            $kernel->bootForCli();
            self::assertSame($before, hash_file('sha256', $cachePath), 'Route-only invalidity must not rewrite cached participation.');
            try {
                $kernel->getRouteParticipation();
                self::fail('Malformed or missing cached participation must refuse.');
            } catch (RouteCompositionException $error) {
                self::assertSame('inventory-unavailable', $error->reason);
            }
        }
    }
}

final class KernelRouteFixtureProvider extends ServiceProvider
{
    public function register(): void {}
}

final class KernelSnapshotFixtureProvider extends ServiceProvider implements ContributesRouteMetadataInterface
{
    public static int $calls = 0;
    public function register(): void {}
    public function routeDefinitions(RouteContributionContext $context): iterable
    {
        self::$calls++;
        yield new \Waaseyaa\Foundation\Routing\Metadata\RouteDefinition('fixture.first', '/fixture', \Waaseyaa\Foundation\Routing\Metadata\HandlerReference::fromString('service:fixture::handle'), priority: 10, sourceId: $context->sourceId);
        yield new \Waaseyaa\Foundation\Routing\Metadata\RouteDefinition('fixture.catchall', '/{alias}', \Waaseyaa\Foundation\Routing\Metadata\HandlerReference::fromString('builtin:render.page'), methods: ['GET'], sourceId: $context->sourceId, ordinal: 1);
    }
}

final class KernelLegacySnapshotFixtureProvider extends ServiceProvider
{
    public static int $calls = 0;
    public function register(): void {}
    public function routes(\Waaseyaa\Routing\WaaseyaaRouter $router, \Waaseyaa\Entity\EntityTypeManager $manager): void
    {
        self::$calls++;
        throw new \LogicException('Legacy route hooks cannot supply canonical metadata.');
    }
}

final class KernelFailingSnapshotFixtureProvider extends ServiceProvider implements ContributesRouteMetadataInterface
{
    public static string $mode = '';
    public static int $calls = 0;
    public static ?AbstractKernel $kernel = null;
    public function register(): void {}
    public function routeDefinitions(RouteContributionContext $context): iterable
    {
        self::$calls++;
        if (self::$mode === 'throw') {
            throw new \RuntimeException('private contributor value');
        }
        if (self::$mode === 'recursive') {
            try {
                self::$kernel->getRouteSnapshot();
            } catch (RouteCompositionException) {
            }
        }
        $name = self::$mode === 'builtin-duplicate' ? 'api.openapi' : 'public.page';
        yield new \Waaseyaa\Foundation\Routing\Metadata\RouteDefinition($name, '/fixture', \Waaseyaa\Foundation\Routing\Metadata\HandlerReference::fromString('service:fixture::handle'), sourceId: $context->sourceId);
    }
}

final class KernelPoisoningSnapshotFixtureProvider extends ServiceProvider implements ContributesRouteMetadataInterface
{
    public static ?RouteExposureInputs $slot = null;
    public static int $calls = 0;
    public function register(): void {}
    public function routeDefinitions(RouteContributionContext $context): iterable
    {
        self::$calls++;
        try {
            self::$slot->publish(['test' => true]);
        } catch (RouteCompositionException) {
        }
        yield new \Waaseyaa\Foundation\Routing\Metadata\RouteDefinition('fixture.poison', '/fixture', \Waaseyaa\Foundation\Routing\Metadata\HandlerReference::fromString('builtin:render.page'), sourceId: $context->sourceId);
    }
}

final class KernelLateEntityRouteFixtureProvider extends ServiceProvider implements FinalizesProviderBootInterface
{
    public function register(): void {}
    public function finalizeProviderBoot(): void
    {
        $manager = $this->resolve(\Waaseyaa\Entity\EntityTypeManager::class);
        $manager->registerEntityType(new \Waaseyaa\Entity\EntityType(id: 'late', label: 'Late', class: \stdClass::class));
    }
}

final class KernelPureRouteInputFixtureProvider extends ServiceProvider implements ContributesRouteMetadataInterface
{
    public function register(): void {}
    public function routeDefinitions(RouteContributionContext $context): iterable
    {
        throw new \LogicException('Input finalization must not invoke route contributors.');
    }
}

final class KernelBindingPresenceFixtureProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->bind('fixture.poison', static fn(): object => throw new \LogicException('Presence must never resolve a binding.'));
    }
}

final class RouteParticipationKernelFixture extends AbstractKernel
{
    public ?PackageManifest $suppliedManifest = null;
    public bool $failFinalization = false;
    public bool $omitProviders = false;
    public bool $loadCachedManifest = false;
    public ?string $duringFinalization = null;
    public bool $captureExposureSlot = false;
    public ?RouteExposureInputs $exposureSlot = null;
    public bool $skipProviderBoot = false;
    public bool $poisonProjectionAutoload = false;
    public array $projectionAutoloads = [];
    private ?\Closure $projectionAutoloadTrap = null;

    public function removeProjectionAutoloadTrap(): void
    {
        if ($this->projectionAutoloadTrap !== null) {
            spl_autoload_unregister($this->projectionAutoloadTrap);
        }
    }

    protected function bootProviders(): void
    {
        if (!$this->skipProviderBoot) {
            parent::bootProviders();
        }
    }

    public function poisonDefinitionReads(): void
    {
        $this->entityTypeManager = new class (new \Symfony\Component\EventDispatcher\EventDispatcher()) extends \Waaseyaa\Entity\EntityTypeManager {
            public function getDefinitions(): array
            {
                throw new \LogicException('Inspection must never refresh definitions.');
            }
        };
    }

    protected function compileManifest(): void
    {
        if ($this->loadCachedManifest) {
            $this->manifest = new PackageManifestCompiler($this->projectRoot, $this->projectRoot . '/storage')->load();
        } elseif ($this->suppliedManifest !== null) {
            $this->manifest = $this->suppliedManifest;
        } else {
            parent::compileManifest();
        }
    }

    protected function finalizeBoot(): void
    {
        if ($this->captureExposureSlot) {
            $this->exposureSlot = $this->routeExposureInputsForProviders();
        }
        try {
            $this->getRouteParticipation();
        } catch (RouteCompositionException $error) {
            $this->duringFinalization = $error->reason;
        }
        if ($this->failFinalization) {
            throw new \RuntimeException('private boot failure');
        }
        if ($this->omitProviders) {
            $this->providers = [];
        }
        if ($this->poisonProjectionAutoload) {
            $this->projectionAutoloadTrap = function (string $class): void {
                $this->projectionAutoloads[] = $class;
                throw new \LogicException('Projection must not autoload.');
            };
            spl_autoload_register($this->projectionAutoloadTrap, true, true);
        }
    }
}

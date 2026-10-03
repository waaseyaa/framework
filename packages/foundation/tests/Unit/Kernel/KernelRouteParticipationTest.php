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
use Waaseyaa\Foundation\Routing\Metadata\RouteParticipationCompiler;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Tests\Support\ProcessFieldReadRuntime;

#[CoversClass(AbstractKernel::class)]
#[CoversClass(PackageManifest::class)]
#[CoversClass(PackageManifestCompiler::class)]
final class KernelRouteParticipationTest extends TestCase
{
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

final class RouteParticipationKernelFixture extends AbstractKernel
{
    public ?PackageManifest $suppliedManifest = null;
    public bool $failFinalization = false;
    public bool $omitProviders = false;
    public bool $loadCachedManifest = false;
    public ?string $duringFinalization = null;

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
    }
}

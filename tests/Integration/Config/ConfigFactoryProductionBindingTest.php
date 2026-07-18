<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Config;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigManagerInterface;
use Waaseyaa\Config\ConfigServiceProvider;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Proves `Waaseyaa\Config\ConfigFactoryInterface` is reachable from a real
 * kernel boot, the way any consumer ServiceProvider reaches it: via
 * `resolveOptional(ConfigFactoryInterface::class)` in its own `boot()`.
 *
 * Before this fix, NO production ServiceProvider bound
 * `ConfigFactoryInterface`, so every `resolveOptional()` consumer (e.g.
 * `Waaseyaa\SSR\ThemeServiceProvider`, and the forthcoming CW-v1
 * `WorkflowServiceProvider` guard/seed wiring) silently received `null` and
 * no-opped in a real boot (#1920 WP-1 follow-up).
 *
 * This test drives `AbstractKernel::boot()` directly (mirrors
 * `DefinitionValidatorBootTest`) rather than requiring a full composer
 * install, injecting `ConfigServiceProvider` plus a tiny probe provider into
 * the manifest so we can observe exactly what a real consumer sees.
 */
#[CoversNothing]
final class ConfigFactoryProductionBindingTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = $this->createMinimalProjectRoot();
        ConfigFactoryProbeProvider::reset();
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->projectRoot);
        parent::tearDown();
    }

    #[Test]
    public function configFactoryInterfaceResolvesForAnyConsumerProviderAtBoot(): void
    {
        $kernel = $this->buildKernel();

        $kernel->publicBoot();

        self::assertTrue(
            ConfigFactoryProbeProvider::$probed,
            'probe provider boot() must have run',
        );
        self::assertInstanceOf(
            ConfigFactoryInterface::class,
            ConfigFactoryProbeProvider::$resolvedFactory,
            'ConfigFactoryInterface must resolve via resolveOptional() at boot, not silently return null',
        );
    }

    #[Test]
    public function resolvedFactoryRoundTripsASetSaveGetCycle(): void
    {
        $kernel = $this->buildKernel();
        $kernel->publicBoot();

        $factory = ConfigFactoryProbeProvider::$resolvedFactory;
        self::assertNotNull($factory);

        $config = $factory->get('workflows.assignments');
        self::assertTrue($config->isNew(), 'a never-written config name starts out new');

        $editable = $factory->getEditable('workflows.assignments');
        $editable->set('example_key', 'example_value')->save();

        // A fresh get() must observe the saved value (cache invalidated by
        // EventAwareStorage on save, storage read from the same active store).
        $reread = $factory->get('workflows.assignments');
        self::assertFalse($reread->isNew());
        self::assertSame('example_value', $reread->get('example_key'));
    }

    #[Test]
    public function configManagerInterfaceResolvesFromARealKernelBoot(): void
    {
        $kernel = $this->buildKernel();
        $kernel->publicBoot();

        self::assertInstanceOf(ConfigManagerInterface::class, ConfigFactoryProbeProvider::$resolvedManager);
    }

    private function buildKernel(): object
    {
        $projectRoot = $this->projectRoot;

        return new class ($projectRoot) extends AbstractKernel {
            public function publicBoot(): void
            {
                $this->boot();
            }

            protected function compileManifest(): void
            {
                parent::compileManifest();

                $this->manifest = new PackageManifest(
                    providers: array_merge(
                        $this->manifest->providers,
                        [ConfigServiceProvider::class, ConfigFactoryProbeProvider::class],
                    ),
                    migrations: $this->manifest->migrations,
                    fieldTypes: $this->manifest->fieldTypes,
                    formatters: $this->manifest->formatters,
                    middleware: $this->manifest->middleware,
                    permissions: $this->manifest->permissions,
                    policies: $this->manifest->policies,
                    packageDeclarations: $this->manifest->packageDeclarations,
                    attributeEntityTypes: $this->manifest->attributeEntityTypes,
                    consoleCommandProviders: $this->manifest->consoleCommandProviders,
                );
            }
        };
    }

    private function createMinimalProjectRoot(): string
    {
        $projectRoot = sys_get_temp_dir() . '/waaseyaa_configbinding_test_' . uniqid();
        mkdir($projectRoot . '/config', 0o755, true);
        mkdir($projectRoot . '/storage', 0o755, true);

        file_put_contents(
            $projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => ':memory:', 'environment' => 'testing'];",
        );

        file_put_contents(
            $projectRoot . '/config/entity-types.php',
            <<<'PHP'
                <?php
                return [
                    new \Waaseyaa\Entity\EntityType(
                        id: 'probe_type',
                        label: 'Probe Type',
                        class: \stdClass::class,
                    ),
                ];
                PHP,
        );

        return $projectRoot;
    }
}

// ---------------------------------------------------------------------------
// Test-only fixture provider — defined here (not under src/) for autoload-dev.
// ---------------------------------------------------------------------------

/**
 * Stand-in for a real consumer ServiceProvider (e.g. the CW-v1
 * `WorkflowServiceProvider`). Its `boot()` calls `resolveOptional()` exactly
 * the way a real consumer would — the framework-wide contract this test
 * exists to prove.
 */
final class ConfigFactoryProbeProvider extends ServiceProvider
{
    public static bool $probed = false;
    public static ?ConfigFactoryInterface $resolvedFactory = null;
    public static ?ConfigManagerInterface $resolvedManager = null;

    public function register(): void {}

    public function boot(): void
    {
        self::$probed = true;
        self::$resolvedFactory = $this->resolveOptional(ConfigFactoryInterface::class);
        self::$resolvedManager = $this->resolveOptional(ConfigManagerInterface::class);
    }

    public static function reset(): void
    {
        self::$probed = false;
        self::$resolvedFactory = null;
        self::$resolvedManager = null;
    }
}

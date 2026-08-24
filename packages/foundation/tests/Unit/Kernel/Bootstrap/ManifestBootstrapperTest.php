<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel\Bootstrap;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Foundation\Kernel\Bootstrap\ManifestBootstrapper;

/**
 * WP01: dev mode compiles the manifest fresh (discovering new app classes without
 * a manual recompile); production uses the compiled cache.
 */
#[CoversClass(ManifestBootstrapper::class)]
final class ManifestBootstrapperTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_mb_' . uniqid();
        mkdir($this->tempDir . '/vendor/composer', 0o755, true);
        mkdir($this->tempDir . '/storage/framework', 0o755, true);

        // Root project declares an app provider (compile() reads this; the stale
        // cache does not contain it). This MUST be a real, autoload-reachable
        // Waaseyaa\... class, not a fabricated fixture string: load()'s cached
        // path (production_uses_the_compiled_cache) merges the root manifest's
        // declared providers into the cached manifest via
        // mergeRootWaaseyaaIntoManifest() and then class_exists()-checks every
        // provider in validateCachedProviders()/assertProvidersExist(). A
        // non-existent class here throws StaleManifestException and forces a
        // fresh recompile, silently bypassing the stale-cache trust path this
        // fixture exists to exercise (found the hard way — a "fabricated
        // fixture string" rename to App\Seo\SeoServiceProvider broke
        // production_uses_the_compiled_cache; do not repeat that mistake, and
        // do not rename this one the way ManifestBootstrapperTest's own
        // vendor-provider fixture (Waaseyaa\Node\NodeServiceProvider, below)
        // is not renamed either).
        file_put_contents(
            $this->tempDir . '/composer.json',
            json_encode([
                'name' => 'app/app',
                'extra' => ['waaseyaa' => ['providers' => ['Waaseyaa\\Seo\\SeoServiceProvider']]],
            ], \JSON_THROW_ON_ERROR),
        );

        // Installed vendor package providing a provider — compile() discovers it.
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode([
                'packages' => [[
                    'name' => 'waaseyaa/node',
                    'extra' => ['waaseyaa' => ['providers' => ['Waaseyaa\\Node\\NodeServiceProvider']]],
                ]],
            ], \JSON_THROW_ON_ERROR),
        );

        // A STALE compiled cache carrying a sentinel that exists ONLY in the cache
        // (a permission — a data field load() does not class-validate, unlike
        // providers). No fingerprint key -> load() trusts the cache as-is.
        $staleManifest = [
            'providers' => [],
            'migrations' => [],
            'field_types' => [],
            'formatters' => [],
            'middleware' => [],
            'permissions' => ['stale_cached_sentinel' => ['label' => 'Stale Sentinel']],
            'policies' => [],
            'package_declarations' => [],
            'attribute_entity_types' => [],
            'console_command_providers' => [],
            'agent_tools' => [],
            'agent_definitions' => [],
            'schedule_entries' => [],
        ];
        file_put_contents(
            $this->tempDir . '/storage/framework/packages.php',
            '<?php return ' . var_export($staleManifest, true) . ';' . "\n",
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tempDir);
    }

    #[Test]
    public function production_uses_the_compiled_cache(): void
    {
        $manifest = new ManifestBootstrapper()->boot($this->tempDir, freshCompile: false);

        self::assertArrayHasKey('stale_cached_sentinel', $manifest->permissions, 'prod must use the cached manifest');
    }

    #[Test]
    public function dev_compiles_fresh_bypassing_the_cache(): void
    {
        $manifest = new ManifestBootstrapper()->boot($this->tempDir, freshCompile: true);

        // Fresh compile reads installed.json + root composer, NOT the stale cache.
        self::assertContains('Waaseyaa\\Node\\NodeServiceProvider', $manifest->providers, 'dev must discover vendor providers fresh');
        self::assertContains('Waaseyaa\\Seo\\SeoServiceProvider', $manifest->providers, 'dev must read root/app providers fresh');
        self::assertArrayNotHasKey('stale_cached_sentinel', $manifest->permissions, 'dev must NOT use the stale cache');
    }

    #[Test]
    public function dev_does_not_write_or_clobber_the_cache(): void
    {
        $before = file_get_contents($this->tempDir . '/storage/framework/packages.php');
        new ManifestBootstrapper()->boot($this->tempDir, freshCompile: true);
        $after = file_get_contents($this->tempDir . '/storage/framework/packages.php');

        self::assertSame($before, $after, 'dev fresh-compile must not rewrite the production cache file');
    }

}

<?php
declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Discovery;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;
use Waaseyaa\Foundation\Discovery\StaleManifestException;

#[CoversClass(PackageManifestCompiler::class)]
final class PackageManifestCompilerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid();
        mkdir($this->tempDir . '/vendor/composer', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    #[Test]
    public function compile_reads_installed_json_manifest(): void
    {
        $installed = [
            'packages' => [
                [
                    'name' => 'waaseyaa/node',
                    'extra' => [
                        'waaseyaa' => [
                            'providers' => ['Waaseyaa\\Node\\NodeServiceProvider'],
                            'commands' => ['Waaseyaa\\Node\\Command\\NodeCreateCommand'],
                        ],
                    ],
                ],
            ],
        ];

        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode($installed, JSON_THROW_ON_ERROR),
        );

        $storagePath = $this->tempDir . '/storage';
        $compiler = new PackageManifestCompiler($this->tempDir, $storagePath);
        $manifest = $compiler->compile();

        $this->assertSame(['Waaseyaa\\Node\\NodeServiceProvider'], $manifest->providers);
        $this->assertSame(['Waaseyaa\\Node\\Command\\NodeCreateCommand'], $manifest->commands);
    }

    #[Test]
    public function compile_includes_foundation_provider_in_repo_manifest(): void
    {
        $repoRoot = dirname(__DIR__, 5);
        $compiler = new PackageManifestCompiler($repoRoot, $repoRoot . '/storage');

        $manifest = $compiler->compile();

        $this->assertContains('Waaseyaa\\Foundation\\FoundationServiceProvider', $manifest->providers);
    }

    #[Test]
    public function compile_reads_local_package_composer_metadata_when_installed_manifest_omits_waaseyaa_extra(): void
    {
        mkdir($this->tempDir . '/packages/foundation/src', 0o755, true);

        file_put_contents(
            $this->tempDir . '/packages/foundation/composer.json',
            json_encode([
                'name' => 'waaseyaa/foundation',
                'autoload' => [
                    'psr-4' => ['Waaseyaa\\Foundation\\' => 'src/'],
                ],
                'extra' => [
                    'waaseyaa' => [
                        'providers' => ['Waaseyaa\\Foundation\\FoundationServiceProvider'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );

        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode([
                'packages' => [
                    [
                        'name' => 'waaseyaa/foundation',
                        'type' => 'library',
                        'install-path' => '../../../packages/foundation',
                        'autoload' => [
                            'psr-4' => ['Waaseyaa\\Foundation\\' => 'src/'],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $compiler = new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage');
        $manifest = $compiler->compile();

        $this->assertContains('Waaseyaa\\Foundation\\FoundationServiceProvider', $manifest->providers);
    }

    #[Test]
    public function compile_handles_missing_installed_json(): void
    {
        $storagePath = $this->tempDir . '/storage';
        $compiler = new PackageManifestCompiler($this->tempDir, $storagePath);
        $manifest = $compiler->compile();

        $this->assertSame([], $manifest->providers);
        $this->assertSame([], $manifest->commands);
    }

    #[Test]
    public function compile_and_cache_writes_php_file(): void
    {
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        $storagePath = $this->tempDir . '/storage';
        $compiler = new PackageManifestCompiler($this->tempDir, $storagePath);
        $compiler->compileAndCache();

        $this->assertFileExists($storagePath . '/framework/packages.php');

        $cached = require $storagePath . '/framework/packages.php';
        $this->assertIsArray($cached);
        $this->assertArrayHasKey('providers', $cached);
        $this->assertArrayHasKey('_manifest_inputs_fp', $cached);
        $this->assertIsString($cached['_manifest_inputs_fp']);
    }

    #[Test]
    public function load_uses_cache_when_available(): void
    {
        $storagePath = $this->tempDir . '/storage';
        mkdir($storagePath . '/framework', 0o755, true);

        $data = [
            'providers' => [\stdClass::class],
            'commands' => [],
            'routes' => [],
            'migrations' => [],
            'field_types' => [],
            'listeners' => [],
            'middleware' => [],
            'permissions' => [],
            'policies' => [],
        ];

        file_put_contents(
            $storagePath . '/framework/packages.php',
            '<?php return ' . var_export($data, true) . ';' . "\n",
        );

        $compiler = new PackageManifestCompiler($this->tempDir, $storagePath);
        $manifest = $compiler->load();

        $this->assertSame([\stdClass::class], $manifest->providers);
    }

    #[Test]
    public function load_recompiles_when_cache_missing(): void
    {
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        $storagePath = $this->tempDir . '/storage';
        $compiler = new PackageManifestCompiler($this->tempDir, $storagePath);

        $manifest = $compiler->load();

        $this->assertInstanceOf(PackageManifest::class, $manifest);
        $this->assertFileExists($storagePath . '/framework/packages.php');
    }

    #[Test]
    public function load_recompiles_when_inputs_change(): void
    {
        $storagePath = $this->tempDir . '/storage';
        mkdir($storagePath . '/framework', 0o755, true);

        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        file_put_contents(
            $storagePath . '/framework/packages.php',
            '<?php return ' . var_export([
                'providers' => ['stale'],
                'commands' => [],
                'routes' => [],
                'migrations' => [],
                'field_types' => [],
                'listeners' => [],
                'middleware' => [],
                'permissions' => [],
                'policies' => [],
                '_manifest_inputs_fp' => 'stale',
            ], true) . ';' . "\n",
        );

        $compiler = new PackageManifestCompiler($this->tempDir, $storagePath);
        $manifest = $compiler->load();

        $this->assertSame([], $manifest->providers);
    }

    #[Test]
    public function load_throws_when_cache_is_invalid_and_recompile_is_disabled(): void
    {
        $storagePath = $this->tempDir . '/storage';
        mkdir($storagePath . '/framework', 0o755, true);

        file_put_contents($storagePath . '/framework/packages.php', '<?php return "invalid";' . "\n");

        $compiler = new PackageManifestCompiler($this->tempDir, $storagePath);

        $this->expectException(StaleManifestException::class);
        $compiler->load(recompileIfStale: false);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($dir);
    }
}

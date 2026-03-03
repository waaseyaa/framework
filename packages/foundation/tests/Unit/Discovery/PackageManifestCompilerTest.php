<?php
declare(strict_types=1);
namespace Waaseyaa\Foundation\Tests\Unit\Discovery;

use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
        // Write empty installed.json
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
    }

    #[Test]
    public function load_uses_cache_when_available(): void
    {
        $storagePath = $this->tempDir . '/storage';
        mkdir($storagePath . '/framework', 0o755, true);

        $data = [
            'providers' => ['CachedProvider'],
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

        $this->assertSame(['CachedProvider'], $manifest->providers);
    }

    #[Test]
    public function load_compiles_when_no_cache(): void
    {
        // Write installed.json with a provider
        $installed = [
            'packages' => [
                [
                    'name' => 'waaseyaa/test',
                    'extra' => [
                        'waaseyaa' => [
                            'providers' => ['Waaseyaa\\Test\\TestProvider'],
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
        $manifest = $compiler->load();

        $this->assertSame(['Waaseyaa\\Test\\TestProvider'], $manifest->providers);
        // Cache file should now exist
        $this->assertFileExists($storagePath . '/framework/packages.php');
    }

    #[Test]
    public function load_recompiles_when_cache_is_corrupt(): void
    {
        $storagePath = $this->tempDir . '/storage';
        mkdir($storagePath . '/framework', 0o755, true);

        // Write a corrupt cache file
        file_put_contents(
            $storagePath . '/framework/packages.php',
            '<?php throw new \RuntimeException("corrupt");',
        );

        // Write valid installed.json so recompile succeeds
        $installed = [
            'packages' => [
                [
                    'name' => 'waaseyaa/test',
                    'extra' => [
                        'waaseyaa' => [
                            'providers' => ['Waaseyaa\\Test\\RecompiledProvider'],
                        ],
                    ],
                ],
            ],
        ];
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode($installed, JSON_THROW_ON_ERROR),
        );

        $compiler = new PackageManifestCompiler($this->tempDir, $storagePath);
        $manifest = $compiler->load();

        $this->assertSame(['Waaseyaa\\Test\\RecompiledProvider'], $manifest->providers);
    }

    #[Test]
    public function load_recompiles_when_cache_returns_non_array(): void
    {
        $storagePath = $this->tempDir . '/storage';
        mkdir($storagePath . '/framework', 0o755, true);

        file_put_contents(
            $storagePath . '/framework/packages.php',
            '<?php return "not an array";',
        );

        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        $compiler = new PackageManifestCompiler($this->tempDir, $storagePath);
        $manifest = $compiler->load();

        $this->assertSame([], $manifest->providers);
    }

    #[Test]
    public function compile_collects_permissions_from_installed_json(): void
    {
        $installed = [
            'packages' => [
                [
                    'name' => 'waaseyaa/node',
                    'extra' => [
                        'waaseyaa' => [
                            'permissions' => [
                                'access content' => ['title' => 'Access published content'],
                                'create article' => ['title' => 'Create Article', 'description' => 'Create article nodes'],
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'waaseyaa/user',
                    'extra' => [
                        'waaseyaa' => [
                            'permissions' => [
                                'administer users' => ['title' => 'Administer users'],
                            ],
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

        $this->assertCount(3, $manifest->permissions);
        $this->assertSame('Access published content', $manifest->permissions['access content']['title']);
        $this->assertSame('Administer users', $manifest->permissions['administer users']['title']);
    }

    #[Test]
    public function compile_discovers_policy_classes(): void
    {
        $fixtureDir = $this->tempDir . '/src';
        mkdir($fixtureDir, 0o755, true);

        $fixtureClass = <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace Waaseyaa\TestFixtures;
        use Waaseyaa\Access\Gate\PolicyAttribute;
        #[PolicyAttribute(entityType: 'node')]
        final class NodePolicy {}
        PHP;

        file_put_contents($fixtureDir . '/NodePolicy.php', $fixtureClass);

        require_once $fixtureDir . '/NodePolicy.php';

        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_classmap.php',
            '<?php return [\'Waaseyaa\\\\TestFixtures\\\\NodePolicy\' => \'' . $fixtureDir . '/NodePolicy.php\'];',
        );

        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        $storagePath = $this->tempDir . '/storage';
        $compiler = new PackageManifestCompiler($this->tempDir, $storagePath);
        $manifest = $compiler->compile();

        $this->assertSame(['node'], $manifest->policies['Waaseyaa\\TestFixtures\\NodePolicy'] ?? null);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}

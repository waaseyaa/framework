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
    public function compile_collects_normalized_package_declarations(): void
    {
        $installed = [
            'packages' => [
                [
                    'name' => 'waaseyaa/core',
                    'type' => 'metapackage',
                ],
                [
                    'name' => 'waaseyaa/deployer',
                    'type' => 'library',
                ],
                [
                    'name' => 'waaseyaa/auth',
                    'type' => 'library',
                    'autoload' => [
                        'psr-4' => ['Waaseyaa\\Auth\\' => 'src/'],
                    ],
                    'extra' => [
                        'waaseyaa' => [
                            'providers' => ['Waaseyaa\\Auth\\AuthServiceProvider'],
                        ],
                    ],
                ],
                [
                    'name' => 'waaseyaa/api',
                    'type' => 'library',
                    'autoload' => [
                        'psr-4' => ['Waaseyaa\\Api\\' => 'src/'],
                    ],
                ],
            ],
        ];

        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode($installed, JSON_THROW_ON_ERROR),
        );

        $compiler = new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage');
        $manifest = $compiler->compile();

        $this->assertSame(
            [
                'surface' => 'aggregate',
                'activation' => 'none',
            ],
            $manifest->packageDeclarations['waaseyaa/core'] ?? null,
        );
        $this->assertSame(
            [
                'surface' => 'tooling',
                'activation' => 'none',
            ],
            $manifest->packageDeclarations['waaseyaa/deployer'] ?? null,
        );
        $this->assertSame(
            [
                'surface' => 'implementation',
                'activation' => 'provider',
            ],
            $manifest->packageDeclarations['waaseyaa/auth'] ?? null,
        );
        $this->assertSame(
            [
                'surface' => 'implementation',
                'activation' => 'discovery',
            ],
            $manifest->packageDeclarations['waaseyaa/api'] ?? null,
        );
    }

    #[Test]
    public function compile_includes_foundation_provider_in_repo_manifest(): void
    {
        $repoRoot = dirname(__DIR__, 5);
        $compiler = new PackageManifestCompiler($repoRoot, $repoRoot . '/storage');

        $manifest = $compiler->compile();

        $this->assertContains('Waaseyaa\\Foundation\\FoundationServiceProvider', $manifest->providers);
        $this->assertSame(
            [
                'surface' => 'implementation',
                'activation' => 'provider',
            ],
            $manifest->packageDeclarations['waaseyaa/foundation'] ?? null,
        );
    }

    #[Test]
    public function compile_includes_mcp_provider_in_repo_manifest(): void
    {
        $repoRoot = dirname(__DIR__, 5);
        $compiler = new PackageManifestCompiler($repoRoot, $repoRoot . '/storage');

        $manifest = $compiler->compile();

        $this->assertContains('Waaseyaa\\Mcp\\McpServiceProvider', $manifest->providers);
        $this->assertSame(
            [
                'surface' => 'implementation',
                'activation' => 'provider',
            ],
            $manifest->packageDeclarations['waaseyaa/mcp'] ?? null,
        );
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
        $this->assertSame(
            [
                'surface' => 'implementation',
                'activation' => 'provider',
            ],
            $manifest->packageDeclarations['waaseyaa/foundation'] ?? null,
        );
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
    public function load_auto_recovers_when_cached_provider_class_is_missing(): void
    {
        $storagePath = $this->tempDir . '/storage';
        mkdir($storagePath . '/framework', 0o755, true);

        $data = [
            'providers' => ['App\\Provider\\MissingProvider'],
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

        // Auto-recovery: stale cache is discarded, fresh manifest compiled (no missing provider)
        $manifest = $compiler->load();

        $this->assertNotContains('App\\Provider\\MissingProvider', $manifest->providers);
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
    public function load_recompiles_when_cached_fingerprint_mismatches_installed_json(): void
    {
        file_put_contents($this->tempDir . '/composer.json', json_encode(['name' => 'test/root'], JSON_THROW_ON_ERROR));

        $installedV1 = [
            'packages' => [
                [
                    'name' => 'waaseyaa/pkg-a',
                    'extra' => [
                        'waaseyaa' => [
                            'providers' => [\stdClass::class],
                        ],
                    ],
                ],
            ],
        ];
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode($installedV1, JSON_THROW_ON_ERROR),
        );

        $storagePath = $this->tempDir . '/storage';
        $compiler = new PackageManifestCompiler($this->tempDir, $storagePath);
        $compiler->compileAndCache();

        $installedV2 = [
            'packages' => [
                [
                    'name' => 'waaseyaa/pkg-a',
                    'extra' => [
                        'waaseyaa' => [
                            'providers' => [\stdClass::class],
                        ],
                    ],
                ],
                [
                    'name' => 'waaseyaa/pkg-b',
                    'extra' => [
                        'waaseyaa' => [
                            'providers' => [\ArrayObject::class],
                        ],
                    ],
                ],
            ],
        ];
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode($installedV2, JSON_THROW_ON_ERROR),
        );

        $manifest = $compiler->load();

        $this->assertSame([\stdClass::class, \ArrayObject::class], $manifest->providers);
    }

    #[Test]
    public function load_merges_root_providers_when_cache_incomplete_but_fingerprint_matches(): void
    {
        $composer = [
            'name' => 'test/root',
            'extra' => [
                'waaseyaa' => [
                    'providers' => [\ArrayObject::class],
                ],
            ],
        ];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));

        $installed = ['packages' => []];
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode($installed, JSON_THROW_ON_ERROR),
        );

        $fingerprint = hash(
            'xxh128',
            (string) file_get_contents($this->tempDir . '/composer.json')
            . "\0"
            . (string) file_get_contents($this->tempDir . '/vendor/composer/installed.json'),
        );

        $storagePath = $this->tempDir . '/storage';
        mkdir($storagePath . '/framework', 0o755, true);

        $data = [
            'providers' => [],
            'commands' => [],
            'routes' => [],
            'migrations' => [],
            'field_types' => [],
            'listeners' => [],
            'middleware' => [],
            'permissions' => [],
            'policies' => [],
            '_manifest_inputs_fp' => $fingerprint,
        ];

        file_put_contents(
            $storagePath . '/framework/packages.php',
            '<?php return ' . var_export($data, true) . ';' . "\n",
        );

        $compiler = new PackageManifestCompiler($this->tempDir, $storagePath);
        $manifest = $compiler->load();

        $this->assertSame([\ArrayObject::class], $manifest->providers);
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
    public function compile_discovers_policies_via_psr4_fallback(): void
    {
        $fixtureDir = $this->tempDir . '/src/Gate';
        mkdir($fixtureDir, 0o755, true);

        $fixtureClass = <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace Waaseyaa\TestFixturesPsr4\Gate;
        use Waaseyaa\Access\Gate\PolicyAttribute;
        #[PolicyAttribute(entityType: 'taxonomy_term')]
        final class Psr4Policy {}
        PHP;

        file_put_contents($fixtureDir . '/Psr4Policy.php', $fixtureClass);

        require_once $fixtureDir . '/Psr4Policy.php';

        // Empty classmap — no Waaseyaa classes
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_classmap.php',
            '<?php return [];',
        );

        // PSR-4 map pointing to fixture directory
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_psr4.php',
            '<?php return [\'Waaseyaa\\\\TestFixturesPsr4\\\\\' => [\'' . $this->tempDir . '/src\']];',
        );

        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        $storagePath = $this->tempDir . '/storage';
        $compiler = new PackageManifestCompiler($this->tempDir, $storagePath);
        $manifest = $compiler->compile();

        $this->assertSame(
            ['taxonomy_term'],
            $manifest->policies['Waaseyaa\\TestFixturesPsr4\\Gate\\Psr4Policy'] ?? null,
        );
    }

    #[Test]
    public function compile_prefers_classmap_over_psr4(): void
    {
        $fixtureDir = $this->tempDir . '/src';
        mkdir($fixtureDir, 0o755, true);

        $fixtureClass = <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace Waaseyaa\TestFixturesClassmap;
        use Waaseyaa\Access\Gate\PolicyAttribute;
        #[PolicyAttribute(entityType: 'media')]
        final class ClassmapPolicy {}
        PHP;

        file_put_contents($fixtureDir . '/ClassmapPolicy.php', $fixtureClass);

        require_once $fixtureDir . '/ClassmapPolicy.php';

        // Classmap includes the policy class — should be used
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_classmap.php',
            '<?php return [\'Waaseyaa\\\\TestFixturesClassmap\\\\ClassmapPolicy\' => \'' . $fixtureDir . '/ClassmapPolicy.php\'];',
        );

        // PSR-4 also points to the same directory — should be skipped
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_psr4.php',
            '<?php return [\'Waaseyaa\\\\TestFixturesClassmap\\\\\' => [\'' . $fixtureDir . '\']];',
        );

        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        $storagePath = $this->tempDir . '/storage';
        $compiler = new PackageManifestCompiler($this->tempDir, $storagePath);
        $manifest = $compiler->compile();

        // Policy discovered via classmap, not duplicated
        $this->assertSame(
            ['media'],
            $manifest->policies['Waaseyaa\\TestFixturesClassmap\\ClassmapPolicy'] ?? null,
        );
        $this->assertCount(1, $manifest->policies);
    }

    #[Test]
    public function compile_discovers_field_formatters_via_attribute(): void
    {
        $fixtureDir = $this->tempDir . '/src/Formatter';
        mkdir($fixtureDir, 0o755, true);

        $fixtureClass = <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace Waaseyaa\TestFixturesFormatter;
        use Waaseyaa\Field\FieldFormatterInterface;
        use Waaseyaa\SSR\Attribute\AsFormatter;
        #[AsFormatter(fieldType: 'string')]
        final class FixturePlainTextFormatter implements FieldFormatterInterface
        {
            public function format(mixed $value, array $settings = []): string { return (string) $value; }
        }
        PHP;

        file_put_contents($fixtureDir . '/FixturePlainTextFormatter.php', $fixtureClass);
        require_once $fixtureDir . '/FixturePlainTextFormatter.php';

        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_classmap.php',
            '<?php return [\'Waaseyaa\\\\TestFixturesFormatter\\\\FixturePlainTextFormatter\' => \'' . $fixtureDir . '/FixturePlainTextFormatter.php\'];',
        );
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        $compiler = new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage');
        $manifest = $compiler->compile();

        $this->assertSame(
            'Waaseyaa\\TestFixturesFormatter\\FixturePlainTextFormatter',
            $manifest->formatters['string'] ?? null,
        );
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

    // --- Issue #21: PSR-4 fallback edge cases ---

    #[Test]
    public function classmap_with_only_third_party_entries_triggers_psr4_fallback(): void
    {
        // Classmap exists but contains no Waaseyaa\ classes — should fall back to PSR-4.
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_classmap.php',
            '<?php return [\'Symfony\\\\Component\\\\Console\\\\Application\' => \'/path/to/Application.php\'];',
        );

        // PSR-4 map with no Waaseyaa namespaces — fallback returns empty.
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_psr4.php',
            '<?php return [];',
        );

        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        $compiler = new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage');
        $manifest = $compiler->compile();

        // No Waaseyaa classes found — empty manifest is the correct result.
        $this->assertSame([], $manifest->policies);
        $this->assertSame([], $manifest->middleware);
    }

    #[Test]
    public function psr4_fallback_excludes_test_namespaces(): void
    {
        $srcDir = $this->tempDir . '/src';
        mkdir($srcDir, 0o755, true);

        // Empty classmap triggers PSR-4 fallback.
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_classmap.php',
            '<?php return [];',
        );

        // PSR-4 map includes both a real namespace and a Tests\ namespace pointing to
        // the same source directory. The Tests\ entry should be skipped.
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_psr4.php',
            '<?php return ['
            . "'Waaseyaa\\\\Entity\\\\Tests\\\\' => ['" . $srcDir . "'], "
            . "'Symfony\\\\Component\\\\' => ['/non-waaseyaa/src']"
            . '];',
        );

        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        $compiler = new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage');
        $manifest = $compiler->compile();

        // No classes should be scanned from the Tests\ namespace.
        $this->assertSame([], $manifest->policies);
    }

    #[Test]
    public function compile_handles_missing_classmap_and_psr4(): void
    {
        // No autoload_classmap.php and no autoload_psr4.php in vendor/composer/ —
        // scanClasses() should return [] gracefully.
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        $compiler = new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage');
        $manifest = $compiler->compile();

        $this->assertSame([], $manifest->policies);
        $this->assertSame([], $manifest->middleware);
    }

    #[Test]
    public function psr4_fallback_handles_corrupt_psr4_map(): void
    {
        // Empty classmap triggers PSR-4 fallback.
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_classmap.php',
            '<?php return [];',
        );

        // Corrupt PSR-4 map — scanPsr4Classes() must catch the error and return [].
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_psr4.php',
            '<?php throw new \RuntimeException("corrupt psr4");',
        );

        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        $compiler = new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage');
        $manifest = $compiler->compile();

        // Corrupt map is silently ignored — empty manifest.
        $this->assertSame([], $manifest->policies);
    }

    #[Test]
    public function psr4_fallback_skips_nonexistent_directories(): void
    {
        // Empty classmap triggers PSR-4 fallback.
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_classmap.php',
            '<?php return [];',
        );

        // PSR-4 map points to a directory that does not exist on disk.
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_psr4.php',
            '<?php return [\'Waaseyaa\\\\Nonexistent\\\\\' => [\'' . $this->tempDir . '/nonexistent/src\']];',
        );

        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        $compiler = new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage');
        $manifest = $compiler->compile();

        // Non-existent directory is skipped — empty manifest.
        $this->assertSame([], $manifest->policies);
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

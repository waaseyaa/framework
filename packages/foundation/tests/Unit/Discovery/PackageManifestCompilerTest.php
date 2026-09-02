<?php
declare(strict_types=1);
namespace Waaseyaa\Foundation\Tests\Unit\Discovery;

use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Foundation\Discovery\MalformedConfigContractException;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;
use Waaseyaa\Foundation\Discovery\PolicyManifestMismatchException;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LoggerTrait;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Tests\Unit\Discovery\Fixture\DiscoverableExtServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\ContentEntityBase;

#[CoversClass(PackageManifestCompiler::class)]
#[CoversClass(MalformedConfigContractException::class)]
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
        (new Filesystem())->remove($this->tempDir);
    }

    /**
     * CFG-03 (#2430): package configuration contracts are a discovery
     * convention like providers and policies. The compatibility authority that
     * gates a verified import is built from these declarations, so a package
     * that owns configuration must be visible here or its content can never be
     * staged.
     */
    #[Test]
    public function compile_collects_declared_configuration_contracts(): void
    {
        $installed = [
            'packages' => [
                [
                    'name' => 'waaseyaa/config',
                    'extra' => ['waaseyaa' => ['config-contract' => [
                        'schema-provider' => 'Acme\\Example\\SchemaProvider',
                        'version' => 2,
                        'readable_versions' => [1, 2],
                    ]]],
                ],
                [
                    'name' => 'waaseyaa/node',
                    'extra' => ['waaseyaa' => ['providers' => ['Waaseyaa\\Node\\NodeServiceProvider']]],
                ],
            ],
        ];
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode($installed, JSON_THROW_ON_ERROR),
        );

        $manifest = new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage')->compile();

        self::assertSame(
            [
                'waaseyaa/config' => [
                    'schema-provider' => 'Acme\\Example\\SchemaProvider',
                    'version' => 2,
                    'readable_versions' => [1, 2],
                ],
            ],
            $manifest->configContracts,
            'Only packages declaring extra.waaseyaa.config-contract appear, verbatim.',
        );
    }

    /**
     * A malformed declaration is not the same as no declaration. Demoting it to
     * "contributes nothing" yields an under-specified compatibility cohort that
     * both the signer and the verifier would accept, so discovery fails closed
     * and says which package and which field.
     */
    #[Test]
    public function compile_refuses_a_malformed_configuration_contract(): void
    {
        $this->writeInstalled([
            ['name' => 'waaseyaa/broken', 'extra' => ['waaseyaa' => ['config-contract' => ['version' => 1]]]],
        ]);

        try {
            new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage')->compile();
            self::fail('A malformed configuration contract was accepted.');
        } catch (MalformedConfigContractException $exception) {
            self::assertStringContainsString('waaseyaa/broken', $exception->getMessage());
            self::assertStringContainsString('config-contract', $exception->getMessage());
        }
    }

    #[Test]
    public function compile_names_the_invalid_field_of_a_configuration_contract(): void
    {
        $cases = [
            'schema-provider' => ['schema-provider' => 42, 'version' => 1, 'readable_versions' => [1]],
            'version' => ['schema-provider' => 'Acme\\Example\\SchemaProvider', 'version' => '1', 'readable_versions' => [1]],
            'readable_versions' => ['schema-provider' => 'Acme\\Example\\SchemaProvider', 'version' => 1, 'readable_versions' => ['1']],
        ];

        foreach ($cases as $field => $declaration) {
            $this->writeInstalled([
                ['name' => 'waaseyaa/broken', 'extra' => ['waaseyaa' => ['config-contract' => $declaration]]],
            ]);

            try {
                new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage')->compile();
                self::fail(sprintf('A contract with an invalid "%s" was accepted.', $field));
            } catch (MalformedConfigContractException $exception) {
                self::assertStringContainsString($field, $exception->getMessage());
                self::assertStringContainsString('waaseyaa/broken', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function compile_refuses_a_configuration_contract_that_is_not_an_object(): void
    {
        foreach ([[1, 2, 3], []] as $declaration) {
            $this->writeInstalled([
                ['name' => 'waaseyaa/broken', 'extra' => ['waaseyaa' => ['config-contract' => $declaration]]],
            ]);

            try {
                new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage')->compile();
                self::fail('A non-object configuration contract was accepted.');
            } catch (MalformedConfigContractException $exception) {
                self::assertStringContainsString('must be a JSON object', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function compile_refuses_readable_versions_that_are_not_a_list(): void
    {
        $this->writeInstalled([
            ['name' => 'waaseyaa/broken', 'extra' => ['waaseyaa' => ['config-contract' => [
                'schema-provider' => 'Acme\\Example\\SchemaProvider',
                'version' => 1,
                'readable_versions' => ['first' => 1],
            ]]]],
        ]);

        $this->expectException(MalformedConfigContractException::class);
        $this->expectExceptionMessageMatches('/readable_versions/');
        new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage')->compile();
    }

    /** A package with no name cannot own a contract, and is skipped quietly. */
    #[Test]
    public function compile_skips_a_package_with_no_name(): void
    {
        $this->writeInstalled([
            ['extra' => ['waaseyaa' => ['config-contract' => ['version' => 1]]]],
        ]);

        self::assertSame([], new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage')->compile()->configContracts);
    }

    /** A package that declares nothing contributes nothing, which is complete. */
    #[Test]
    public function compile_accepts_a_package_that_declares_no_configuration_contract(): void
    {
        $this->writeInstalled([
            ['name' => 'waaseyaa/node', 'extra' => ['waaseyaa' => ['providers' => ['Waaseyaa\\Node\\NodeServiceProvider']]]],
        ]);

        $manifest = new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage')->compile();

        self::assertSame([], $manifest->configContracts);
    }

    /** @param list<array<string, mixed>> $packages */
    private function writeInstalled(array $packages): void
    {
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => $packages], JSON_THROW_ON_ERROR),
        );
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
        $logger = new class implements LoggerInterface {
            use LoggerTrait;

            /** @var list<string> */
            public array $messages = [];

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $compiler = new PackageManifestCompiler($this->tempDir, $storagePath, $logger);
        $manifest = $compiler->compile();

        $this->assertSame(['Waaseyaa\\Node\\NodeServiceProvider'], $manifest->providers);
        $warned = array_filter(
            $logger->messages,
            static fn(string $m): bool => str_contains($m, 'extra.waaseyaa.commands'),
        );
        $this->assertNotEmpty($warned, 'legacy extra.waaseyaa.commands should log a deprecation warning');
    }

    #[Test]
    public function compileDiscoversCanonicalContentEntityAttribute(): void
    {
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_classmap.php',
            '<?php return [' . var_export(CompilerContentEntityFixture::class, true)
            . ' => ' . var_export(__FILE__, true) . '];',
        );
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        $manifest = (new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage'))->compile();

        self::assertContains(CompilerContentEntityFixture::class, $manifest->attributeEntityTypes);
    }

    #[Test]
    public function compile_discovers_conditional_agent_tools_only_with_their_package(): void
    {
        $fixtureClass = 'Waaseyaa\\TestFixtures\\ConditionalAgentTool';
        $fixtureFile = $this->tempDir . '/ConditionalAgentTool.php';
        file_put_contents($fixtureFile, <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Waaseyaa\TestFixtures;
            #[\Waaseyaa\AI\Tools\Attribute\AsAgentTool(
                name: 'conditional_tool',
                capability: 'use conditional tool',
                requiresPackage: 'waaseyaa/wayfinding',
            )]
            final class ConditionalAgentTool {}
            PHP);
        require_once $fixtureFile;
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_classmap.php',
            '<?php return [' . var_export($fixtureClass, true) . ' => ' . var_export($fixtureFile, true) . '];',
        );
        file_put_contents($this->tempDir . '/vendor/composer/autoload_psr4.php', '<?php return [];');
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        $withoutPackage = (new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage'))->compile();
        self::assertNotContains($fixtureClass, array_column($withoutPackage->agentTools, 'class'));

        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => [['name' => 'waaseyaa/wayfinding']]], JSON_THROW_ON_ERROR),
        );
        $withPackage = (new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage'))->compile();
        self::assertContains($fixtureClass, array_column($withPackage->agentTools, 'class'));
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
    public function compile_includes_cache_rekey_provider_in_repo_manifest(): void
    {
        $repoRoot = dirname(__DIR__, 5);
        $compiler = new PackageManifestCompiler($repoRoot, $repoRoot . '/storage');

        $manifest = $compiler->compile();

        $this->assertContains('Waaseyaa\\Cache\\CacheServiceProvider', $manifest->providers);
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
            'migrations' => [],
            'field_types' => [],
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
            'migrations' => [],
            'field_types' => [],
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
                            // Fixture provider FQCN — round-tripped as a raw string by
                            // load()/compile(), never autoloaded here, so it deliberately
                            // is not "Waaseyaa\…"-namespaced (matches the App\Provider\…
                            // fixture convention above; see PL010 in bin/check-package-layers).
                            'providers' => ['App\\Test\\TestProvider'],
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

        $this->assertSame(['App\\Test\\TestProvider'], $manifest->providers);
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
                            // Deliberately not "Waaseyaa\…"-namespaced — see the rationale
                            // on the equivalent fixture in load_compiles_when_no_cache().
                            'providers' => ['App\\Test\\RecompiledProvider'],
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

        $this->assertSame(['App\\Test\\RecompiledProvider'], $manifest->providers);
    }

    #[Test]
    public function load_logs_a_warning_naming_the_cache_path_and_exception_before_recompiling_on_corrupt_cache(): void
    {
        $storagePath = $this->tempDir . '/storage';
        mkdir($storagePath . '/framework', 0o755, true);

        $cachePath = $storagePath . '/framework/packages.php';
        file_put_contents($cachePath, '<?php throw new \RuntimeException("corrupt");');

        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        $logger = new class implements LoggerInterface {
            use LoggerTrait;

            /** @var list<array{level: LogLevel, message: string}> */
            public array $messages = [];

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = ['level' => $level, 'message' => (string) $message];
            }
        };

        $compiler = new PackageManifestCompiler($this->tempDir, $storagePath, $logger);
        $manifest = $compiler->load();

        // Self-heal preserved: load() still returns a usable manifest.
        $this->assertInstanceOf(PackageManifest::class, $manifest);

        $warnings = array_values(array_filter(
            $logger->messages,
            static fn(array $m): bool => $m['level'] === LogLevel::WARNING
                && str_contains($m['message'], 'recompiling')
                && str_contains($m['message'], 'RuntimeException'),
        ));

        $this->assertNotEmpty(
            $warnings,
            'A corrupt manifest cache must log a warning naming the exception class before recompiling.',
        );
        $this->assertStringContainsString($cachePath, $warnings[0]['message']);
    }

    #[Test]
    public function load_recompiles_silently_when_cache_returns_a_non_array_wrong_type(): void
    {
        // A cache file that returns a wrong-shaped value (not an array) falls through
        // the is_array($data) branch without ever entering the catch(\Throwable) path —
        // no exception is thrown, so no "recompiling" warning is expected here. Self-heal
        // (no crash, fresh compile) must still work.
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

        $logger = new class implements LoggerInterface {
            use LoggerTrait;

            /** @var list<string> */
            public array $messages = [];

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        $compiler = new PackageManifestCompiler($this->tempDir, $storagePath, $logger);
        $manifest = $compiler->load();

        $this->assertInstanceOf(PackageManifest::class, $manifest);
        $this->assertSame([], $manifest->providers);
    }

    #[Test]
    public function compile_performs_exactly_one_reflective_class_scan_per_compile(): void
    {
        // No vendor/composer/autoload_classmap.php and no app-level PSR-4 prefixes
        // (no root composer.json psr-4 entries) — scanClasses() takes the
        // "no discoverable classes" path and logs exactly one warning
        // EACH TIME IT RUNS. compile() invokes scanClasses() directly (the
        // attribute-scan loop) and indirectly via scanScheduleEntryClasses() (the
        // schedule-entries pass) — before memoization this logged the fallback
        // warning TWICE per compile(); memoized, it must log exactly once.
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        $logger = new class implements LoggerInterface {
            use LoggerTrait;

            /** @var list<string> */
            public array $messages = [];

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        $compiler = new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage', $logger);
        $compiler->compile();

        $fallbackWarnings = array_values(array_filter(
            $logger->messages,
            static fn(string $m): bool => str_contains($m, 'classmap and PSR-4 scanning both returned no candidates'),
        ));

        $this->assertCount(
            1,
            $fallbackWarnings,
            'scanClasses() must run exactly once per compile() (memoized) — got ' . count($fallbackWarnings) . ' invocations.',
        );
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
    public function load_recompiles_when_composer_rewrites_the_autoload_classmap(): void
    {
        file_put_contents($this->tempDir . '/composer.json', '{"name":"test/root"}');
        file_put_contents($this->tempDir . '/vendor/composer/installed.json', '{"packages":[]}');
        file_put_contents($this->tempDir . '/vendor/composer/autoload_classmap.php', '<?php return [];');
        file_put_contents($this->tempDir . '/vendor/composer/autoload_psr4.php', '<?php return [];');

        $storagePath = $this->tempDir . '/storage';
        (new PackageManifestCompiler($this->tempDir, $storagePath))->compileAndCache();
        $before = require $storagePath . '/framework/packages.php';

        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_classmap.php',
            '<?php return ["Composer\\\\InstalledVersions" => "/tmp/InstalledVersions.php"];',
        );
        (new PackageManifestCompiler($this->tempDir, $storagePath))->load();
        $after = require $storagePath . '/framework/packages.php';

        $this->assertNotSame($before['_manifest_inputs_fp'], $after['_manifest_inputs_fp']);
    }

    /**
     * #2778: `hydrateInstalledPackageMetadata()` re-reads a path package's own
     * composer.json on every compile, but the cache fingerprint previously
     * covered only root composer.json + installed.json + the two autoload
     * dumps. A path package editing its own extra.waaseyaa.migrations with
     * root/installed/autoload byte-identical left the cache permanently stale
     * — exactly the fresh-install evidence in the issue (waaseyaa/ai-agent
     * declaring extra.waaseyaa.migrations after the cache was compiled).
     */
    #[Test]
    public function load_recompiles_when_a_path_packages_own_composer_json_declares_new_migrations(): void
    {
        mkdir($this->tempDir . '/packages/acme-ext/src', 0o755, true);
        $pathComposer = $this->tempDir . '/packages/acme-ext/composer.json';
        file_put_contents(
            $pathComposer,
            json_encode([
                'name' => 'acme/ext',
                'autoload' => ['psr-4' => ['Acme\\Ext\\' => 'src/']],
                'extra' => ['waaseyaa' => [
                    'providers' => ['Acme\\Ext\\ExtServiceProvider'],
                ]],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );

        file_put_contents($this->tempDir . '/composer.json', '{"name":"test/root"}');
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode([
                'packages' => [
                    [
                        'name' => 'acme/ext',
                        'type' => 'library',
                        'install-path' => '../../../packages/acme-ext',
                        'dist' => ['type' => 'path', 'url' => '../../../packages/acme-ext'],
                        'autoload' => ['psr-4' => ['Acme\\Ext\\' => 'src/']],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $storagePath = $this->tempDir . '/storage';
        $compilerBefore = new PackageManifestCompiler($this->tempDir, $storagePath);
        $before = $compilerBefore->load();

        $this->assertSame([], $before->migrations, 'Precondition: no migrations declared yet.');

        // Root composer.json, installed.json, and both autoload dumps stay
        // byte-identical. Only the path package's own composer.json changes
        // — the exact upgrade shape the issue describes.
        file_put_contents(
            $pathComposer,
            json_encode([
                'name' => 'acme/ext',
                'autoload' => ['psr-4' => ['Acme\\Ext\\' => 'src/']],
                'extra' => ['waaseyaa' => [
                    'providers' => ['Acme\\Ext\\ExtServiceProvider'],
                    'migrations' => 'migrations',
                ]],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );

        $after = (new PackageManifestCompiler($this->tempDir, $storagePath))->load();

        $this->assertSame(
            ['acme/ext' => 'migrations'],
            $after->migrations,
            'load() must recompile and expose the new migrations declaration.',
        );
    }

    /**
     * #2778: the fix must not be special-cased to migrations — any
     * extra.waaseyaa declaration on a path package's own composer.json
     * (here: permissions) is a manifest input.
     */
    #[Test]
    public function load_recompiles_when_a_path_packages_own_composer_json_declares_new_permissions(): void
    {
        mkdir($this->tempDir . '/packages/acme-ext/src', 0o755, true);
        $pathComposer = $this->tempDir . '/packages/acme-ext/composer.json';
        file_put_contents(
            $pathComposer,
            json_encode([
                'name' => 'acme/ext',
                'autoload' => ['psr-4' => ['Acme\\Ext\\' => 'src/']],
                'extra' => ['waaseyaa' => []],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );

        file_put_contents($this->tempDir . '/composer.json', '{"name":"test/root"}');
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode([
                'packages' => [
                    [
                        'name' => 'acme/ext',
                        'type' => 'library',
                        'install-path' => '../../../packages/acme-ext',
                        'dist' => ['type' => 'path', 'url' => '../../../packages/acme-ext'],
                        'autoload' => ['psr-4' => ['Acme\\Ext\\' => 'src/']],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $storagePath = $this->tempDir . '/storage';
        $before = (new PackageManifestCompiler($this->tempDir, $storagePath))->load();

        $this->assertSame([], $before->permissions, 'Precondition: no permissions declared yet.');

        file_put_contents(
            $pathComposer,
            json_encode([
                'name' => 'acme/ext',
                'autoload' => ['psr-4' => ['Acme\\Ext\\' => 'src/']],
                'extra' => ['waaseyaa' => [
                    'permissions' => ['acme.ext.manage' => ['title' => 'Manage Acme Ext']],
                ]],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );

        $after = (new PackageManifestCompiler($this->tempDir, $storagePath))->load();

        $this->assertSame(
            ['acme.ext.manage' => ['title' => 'Manage Acme Ext']],
            $after->permissions,
            'load() must recompile and expose the new permissions declaration.',
        );
    }

    /**
     * #2778: proves the fix does not hash unrelated source trees on every
     * request — an unchanged path package composer.json (and every other
     * input) must remain a cache hit, evidenced by an identical fingerprint
     * and by the on-disk cache file being left untouched by the second load().
     *
     * #2829: the declared provider must be genuinely discoverable, otherwise
     * load() legitimately recompiles to stamp the known-missing roster and the
     * proof measures a rewrite, not a hit. A rewrite is detected by bytes: a
     * trailing sentinel the compiler never emits survives a hit and vanishes
     * on regeneration. Never by inode identity (filesystems reuse inode numbers
     * across an atomic replacement) and never by one-second mtime resolution.
     */
    #[Test]
    public function load_reuses_cache_when_a_path_packages_composer_json_is_unchanged(): void
    {
        $this->writePathPackageFixture(DiscoverableExtServiceProvider::class);

        $storagePath = $this->tempDir . '/storage';
        (new PackageManifestCompiler($this->tempDir, $storagePath))->compileAndCache();
        $cachePath = $storagePath . '/framework/packages.php';
        $before = require $cachePath;
        $this->assertContains(DiscoverableExtServiceProvider::class, $before['providers'], 'Precondition: the fixture provider is discovered.');
        $this->assertArrayNotHasKey('_known_missing_providers', $before, 'Precondition: nothing is missing, so nothing is stamped.');

        // Any rewrite regenerates the file from the manifest, so a trailing
        // comment sentinel that the compiler would never emit is a
        // deterministic oracle: it survives a hit and vanishes on a rewrite.
        $bytesBefore = $this->appendCacheSentinel($cachePath);

        // Nothing changes on disk. A second load() must be a pure cache hit:
        // same fingerprint, and load() (unlike compileAndCache()) never
        // rewrites the cache file on a hit.
        clearstatcache();
        (new PackageManifestCompiler($this->tempDir, $storagePath))->load();
        clearstatcache();

        $after = require $cachePath;
        $this->assertSame($before['_manifest_inputs_fp'], $after['_manifest_inputs_fp']);
        $this->assertSame($bytesBefore, file_get_contents($cachePath), 'A cache hit must leave the cache bytes, sentinel included, untouched.');
        $this->assertStringEndsWith(self::CACHE_SENTINEL, (string) file_get_contents($cachePath));
    }

    /**
     * #2829 negative control for the proof above: with a declared provider the
     * autoloader cannot resolve, load() is not a cache hit. It recompiles to
     * stamp the known-missing roster, which the sentinel oracle must detect as
     * a rewrite. This pins that the detection is real, not vacuous.
     */
    #[Test]
    public function load_recompiles_and_stamps_when_a_declared_provider_is_undiscoverable(): void
    {
        $this->writePathPackageFixture('Acme\\Ext\\UndiscoverableServiceProvider');

        $storagePath = $this->tempDir . '/storage';
        (new PackageManifestCompiler($this->tempDir, $storagePath))->compileAndCache();
        $cachePath = $storagePath . '/framework/packages.php';
        $before = require $cachePath;
        $this->assertArrayNotHasKey('_known_missing_providers', $before, 'compileAndCache() alone does not stamp the roster.');
        $bytesBefore = $this->appendCacheSentinel($cachePath);

        clearstatcache();
        (new PackageManifestCompiler($this->tempDir, $storagePath))->load();
        clearstatcache();

        $bytesAfter = (string) file_get_contents($cachePath);
        $after = require $cachePath;
        $this->assertSame($before['_manifest_inputs_fp'], $after['_manifest_inputs_fp'], 'The inputs did not change; only the roster did.');
        $this->assertNotSame($bytesBefore, $bytesAfter, 'The recompile regenerates the cache file.');
        $this->assertStringNotContainsString(self::CACHE_SENTINEL, $bytesAfter, 'A regenerated cache carries no trace of the sentinel.');
        $this->assertSame(['Acme\\Ext\\UndiscoverableServiceProvider'], $after['_known_missing_providers']);
    }

    /** Valid PHP the compiler never emits: appended after the closing `;` of the returned array. */
    private const string CACHE_SENTINEL = "// #2829 cache-hit sentinel: a rewrite regenerates this file and drops this line\n";

    /**
     * Append the sentinel and return the exact bytes the cache now holds. The
     * file stays loadable: `require` still returns the manifest array.
     */
    private function appendCacheSentinel(string $cachePath): string
    {
        $this->assertNotFalse(file_put_contents($cachePath, self::CACHE_SENTINEL, FILE_APPEND));
        $bytes = (string) file_get_contents($cachePath);
        $this->assertStringEndsWith(self::CACHE_SENTINEL, $bytes);
        $this->assertIsArray(require $cachePath, 'The sentinel must keep the cache file valid PHP.');

        return $bytes;
    }

    private function writePathPackageFixture(string $providerClass): void
    {
        mkdir($this->tempDir . '/packages/acme-ext/src', 0o755, true);
        file_put_contents(
            $this->tempDir . '/packages/acme-ext/composer.json',
            json_encode([
                'name' => 'acme/ext',
                'autoload' => ['psr-4' => ['Acme\\Ext\\' => 'src/']],
                'extra' => ['waaseyaa' => [
                    'providers' => [$providerClass],
                ]],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );

        file_put_contents($this->tempDir . '/composer.json', '{"name":"test/root"}');
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode([
                'packages' => [
                    [
                        'name' => 'acme/ext',
                        'type' => 'library',
                        'install-path' => '../../../packages/acme-ext',
                        'dist' => ['type' => 'path', 'url' => '../../../packages/acme-ext'],
                        'autoload' => ['psr-4' => ['Acme\\Ext\\' => 'src/']],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * #2778 follow-up (PR review): Composer 2.x stamps `install-path` on
     * every installed package regardless of origin — verified against this
     * repo's own vendor/composer/installed.json, where all 200/200 packages
     * (path and ordinary dist alike) carry `install-path`. The reliable
     * "is this a path package" signal is `dist.type === 'path'` (or
     * `source.type === 'path'`), not `install-path` presence. Gating on
     * `install-path` alone makes the #2778 fingerprint component read and
     * hash every installed package's composer.json on every load() —
     * including the previously-cheap cache-hit path — not just true path
     * (monorepo-sibling) packages, directly contradicting the issue's
     * "without hashing unrelated source trees on every request" criterion.
     *
     * This proves an ordinary dist package's own on-disk composer.json is
     * excluded from the fingerprint even though it carries `install-path`,
     * exactly like a real Composer-installed dist package would.
     */
    #[Test]
    public function load_ignores_a_dist_installed_packages_composer_json_even_though_install_path_is_present(): void
    {
        mkdir($this->tempDir . '/vendor/acme/dist-pkg', 0o755, true);
        $distPackageComposer = $this->tempDir . '/vendor/acme/dist-pkg/composer.json';
        file_put_contents(
            $distPackageComposer,
            json_encode([
                'name' => 'acme/dist-pkg',
                'extra' => ['waaseyaa' => []],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );

        file_put_contents($this->tempDir . '/composer.json', '{"name":"test/root"}');
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode([
                'packages' => [
                    [
                        'name' => 'acme/dist-pkg',
                        'type' => 'library',
                        // Real Composer 2.x sets install-path for a plain
                        // dist install too (pointing into vendor/), not
                        // only for path repositories.
                        'install-path' => '../acme/dist-pkg',
                        'dist' => ['type' => 'zip', 'url' => 'https://example.test/dist-pkg.zip'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $storagePath = $this->tempDir . '/storage';
        (new PackageManifestCompiler($this->tempDir, $storagePath))->compileAndCache();
        $cachePath = $storagePath . '/framework/packages.php';
        $before = require $cachePath;
        $mtimeBefore = filemtime($cachePath);

        // Edit the dist package's own on-disk composer.json — root
        // composer.json, installed.json, and both autoload dumps stay
        // byte-identical. A real dist package cannot change like this
        // without a version bump (which changes installed.json), so this
        // must remain a cache hit: load() must not treat it as a manifest
        // input the way a true path package's composer.json is.
        file_put_contents(
            $distPackageComposer,
            json_encode([
                'name' => 'acme/dist-pkg',
                'extra' => ['waaseyaa' => ['providers' => ['Acme\\DistPkg\\ServiceProvider']]],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );

        clearstatcache();
        (new PackageManifestCompiler($this->tempDir, $storagePath))->load();
        $after = require $cachePath;

        // Fingerprint equality is the load-bearing assertion — filemtime is
        // second-resolution on some filesystems and can alias a same-second
        // rewrite, so it is checked only as a secondary signal.
        $this->assertSame(
            $before['_manifest_inputs_fp'],
            $after['_manifest_inputs_fp'],
            'A dist package composer.json change must not change the fingerprint — only true path packages are a fingerprint input.',
        );
        $this->assertSame(
            [],
            $after['providers'],
            'A dist package composer.json change must not be merged into the manifest via a spurious recompile.',
        );
        $this->assertSame($mtimeBefore, filemtime($cachePath), 'A cache hit must not rewrite the cache file.');
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
            implode("\0", [
                (string) file_get_contents($this->tempDir . '/composer.json'),
                (string) file_get_contents($this->tempDir . '/vendor/composer/installed.json'),
                '',
                '',
                // No installed package declares `install-path`, so #2778's
                // path-package fingerprint input is the empty string.
                '',
            ]),
        );

        $storagePath = $this->tempDir . '/storage';
        mkdir($storagePath . '/framework', 0o755, true);

        $data = [
            'providers' => [],
            'migrations' => [],
            'field_types' => [],
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
    public function load_ignores_deprecated_root_commands_and_routes_when_fingerprint_matches(): void
    {
        $composer = [
            'name' => 'test/root',
            'extra' => [
                'waaseyaa' => [
                    'commands' => [\Iterator::class],
                    'routes' => [\Stringable::class],
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
            implode("\0", [
                (string) file_get_contents($this->tempDir . '/composer.json'),
                (string) file_get_contents($this->tempDir . '/vendor/composer/installed.json'),
                '',
                '',
                // No installed package declares `install-path`, so #2778's
                // path-package fingerprint input is the empty string.
                '',
            ]),
        );

        $storagePath = $this->tempDir . '/storage';
        mkdir($storagePath . '/framework', 0o755, true);

        $data = [
            'providers' => [],
            'migrations' => [],
            'field_types' => [],
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
    public function partial_classmap_does_not_hide_framework_policies_behind_app_policies(): void
    {
        $appDir = $this->tempDir . '/app';
        mkdir($appDir, 0o755, true);

        for ($i = 1; $i <= 4; ++$i) {
            $class = sprintf('AppPolicy%d', $i);
            $path = $appDir . '/' . $class . '.php';
            file_put_contents($path, sprintf(<<<'PHP'
                <?php
                declare(strict_types=1);
                namespace App;
                use Waaseyaa\Access\Gate\PolicyAttribute;
                #[PolicyAttribute(entityType: 'app_fixture_%1$d')]
                final class %2$s {}
                PHP, $i, $class));
            require_once $path;
        }

        file_put_contents(
            $this->tempDir . '/composer.json',
            json_encode([
                'name' => 'app/field-reproduction',
                'autoload' => ['psr-4' => ['App\\' => 'app/']],
            ], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        $repoRoot = dirname(__DIR__, 5);
        $psr4 = require $repoRoot . '/vendor/composer/autoload_psr4.php';
        $psr4['App\\'] = [$appDir];
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_psr4.php',
            '<?php return ' . var_export($psr4, true) . ';',
        );

        // A routine non-optimized install can contain a partial classmap. The four
        // app policies are found by the app-prefix scan, but that non-empty result
        // must not suppress the framework PSR-4 namespaces.
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_classmap.php',
            '<?php return [\Composer\InstalledVersions::class => ' . var_export($repoRoot . '/vendor/composer/InstalledVersions.php', true) . '];',
        );

        $manifest = (new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage'))->compile();

        $this->assertCount(28, $manifest->policies);
        $this->assertArrayHasKey('Waaseyaa\\Node\\NodeAccessPolicy', $manifest->policies);
        $this->assertArrayHasKey('Waaseyaa\\Workflows\\Access\\WorkflowAuthorityAccessPolicy', $manifest->policies);
        $this->assertArrayHasKey('Waaseyaa\\Groups\\GroupAccessPolicy', $manifest->policies);
        $this->assertArrayHasKey('Waaseyaa\\Taxonomy\\VocabularyAccessPolicy', $manifest->policies);
        $this->assertArrayHasKey(
            'Waaseyaa\\Groups\\StaffDirectory\\CapabilityScopedStaffDirectoryAccessPolicy',
            $manifest->policies,
        );
        $this->assertArrayHasKey('Waaseyaa\\Field\\Classification\\Policy\\RetentionPolicyAccessPolicy', $manifest->policies);
        for ($i = 1; $i <= 4; ++$i) {
            $this->assertArrayHasKey(sprintf('App\\AppPolicy%d', $i), $manifest->policies);
        }
        $this->assertContains(
            'Waaseyaa\\AI\\Tools\\Entity\\EntityReadTool',
            array_column($manifest->agentTools, 'class'),
            'The MCP/agent tool catalogue must survive a non-optimized classmap.',
        );
        $this->assertSame(
            'Waaseyaa\\SSR\\Formatter\\PlainTextFormatter',
            $manifest->formatters['string'] ?? null,
        );
        $this->assertContains(
            'Waaseyaa\\Access\\Middleware\\AuthorizationMiddleware',
            array_column($manifest->middleware['http'] ?? [], 'class'),
        );
    }

    #[Test]
    public function compile_refuses_to_boot_when_declared_policy_manifest_is_incomplete(): void
    {
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => [[
                'name' => 'waaseyaa/security-fixture',
                'extra' => ['waaseyaa' => ['policies' => [
                    'Waaseyaa\\SecurityFixture\\MissingPolicy',
                ]]],
            ]]], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_classmap.php',
            '<?php return [];',
        );
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_psr4.php',
            '<?php return [];',
        );

        $this->expectException(PolicyManifestMismatchException::class);
        $this->expectExceptionMessage(
            'POLICY_MANIFEST_MISMATCH: discovered 0 package access policies, manifest declares 1; refusing to boot. Missing: Waaseyaa\\SecurityFixture\\MissingPolicy; unexpected: (none)',
        );

        (new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage'))->load();
    }

    #[Test]
    public function compile_discovers_a_declared_policy_from_an_external_extension_namespace(): void
    {
        $packageRoot = $this->tempDir . '/vendor/acme/security-fixture';
        $sourceDir = $packageRoot . '/src';
        mkdir($sourceDir, 0o755, true);
        $policyClass = 'Acme\\SecurityFixture\\ExternalPolicy';
        $policyPath = $sourceDir . '/ExternalPolicy.php';
        file_put_contents($policyPath, <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Acme\SecurityFixture;
            use Waaseyaa\Access\Gate\PolicyAttribute;
            #[PolicyAttribute(entityType: 'external_fixture')]
            final class ExternalPolicy {}
            PHP);
        require_once $policyPath;

        $middlewareClass = 'Acme\\SecurityFixture\\ExternalMiddleware';
        $middlewarePath = $sourceDir . '/ExternalMiddleware.php';
        file_put_contents($middlewarePath, <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Acme\SecurityFixture;
            use Waaseyaa\Foundation\Attribute\AsMiddleware;
            #[AsMiddleware(pipeline: 'http')]
            final class ExternalMiddleware {}
            PHP);
        require_once $middlewarePath;

        $packageComposer = [
            'name' => 'acme/security-fixture',
            'autoload' => ['psr-4' => ['Acme\\SecurityFixture\\' => 'src/']],
            'extra' => ['waaseyaa' => ['policies' => [$policyClass]]],
        ];
        file_put_contents(
            $packageRoot . '/composer.json',
            json_encode($packageComposer, JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => [[
                ...$packageComposer,
                'install-path' => '../acme/security-fixture',
            ]]], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_classmap.php',
            '<?php return [];',
        );
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_psr4.php',
            '<?php return ["Acme\\\\SecurityFixture\\\\" => [' . var_export($sourceDir, true) . ']];',
        );

        $manifest = (new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage'))->compile();

        $this->assertSame(['external_fixture'], $manifest->policies[$policyClass] ?? null);
        $this->assertNotContains(
            $middlewareClass,
            array_column($manifest->middleware['http'] ?? [], 'class'),
            'Policy participation must not widen unrelated external attribute discovery.',
        );
    }

    #[Test]
    public function compile_discovers_an_external_extension_policy_from_an_optimized_classmap(): void
    {
        $packageRoot = $this->tempDir . '/vendor/acme/classmap-fixture';
        $sourceDir = $packageRoot . '/src';
        mkdir($sourceDir, 0o755, true);
        $policyClass = 'Acme\\ClassmapFixture\\ExternalPolicy';
        $policyPath = $sourceDir . '/ExternalPolicy.php';
        file_put_contents($policyPath, <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Acme\ClassmapFixture;
            use Waaseyaa\Access\Gate\PolicyAttribute;
            #[PolicyAttribute(entityType: 'external_classmap_fixture')]
            final class ExternalPolicy {}
            PHP);
        require_once $policyPath;

        $packageComposer = [
            'name' => 'acme/classmap-fixture',
            'autoload' => ['psr-4' => ['Acme\\ClassmapFixture\\' => 'src/']],
            'extra' => ['waaseyaa' => ['policies' => [$policyClass]]],
        ];
        file_put_contents(
            $packageRoot . '/composer.json',
            json_encode($packageComposer, JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => [[
                ...$packageComposer,
                'install-path' => '../acme/classmap-fixture',
            ]]], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_classmap.php',
            '<?php return [' . var_export($policyClass, true) . ' => ' . var_export($policyPath, true) . '];',
        );
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_psr4.php',
            '<?php return [];',
        );

        $manifest = (new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage'))->compile();

        $this->assertSame(['external_classmap_fixture'], $manifest->policies[$policyClass] ?? null);
    }

    #[Test]
    public function compile_rejects_an_undeclared_policy_from_a_participating_external_extension(): void
    {
        $packageRoot = $this->tempDir . '/vendor/acme/undeclared-fixture';
        $sourceDir = $packageRoot . '/src';
        mkdir($sourceDir, 0o755, true);
        $policyClass = 'Acme\\UndeclaredFixture\\ExternalPolicy';
        $policyPath = $sourceDir . '/ExternalPolicy.php';
        file_put_contents($policyPath, <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Acme\UndeclaredFixture;
            use Waaseyaa\Access\Gate\PolicyAttribute;
            #[PolicyAttribute(entityType: 'external_undeclared_fixture')]
            final class ExternalPolicy {}
            PHP);
        require_once $policyPath;

        $packageComposer = [
            'name' => 'acme/undeclared-fixture',
            'autoload' => ['psr-4' => ['Acme\\UndeclaredFixture\\' => 'src/']],
            'extra' => ['waaseyaa' => ['providers' => []]],
        ];
        file_put_contents(
            $packageRoot . '/composer.json',
            json_encode($packageComposer, JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => [[
                ...$packageComposer,
                'install-path' => '../acme/undeclared-fixture',
            ]]], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_classmap.php',
            '<?php return [];',
        );
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_psr4.php',
            '<?php return ["Acme\\\\UndeclaredFixture\\\\" => [' . var_export($sourceDir, true) . ']];',
        );

        $this->expectException(PolicyManifestMismatchException::class);
        $this->expectExceptionMessage(
            'Missing: (none); unexpected: Acme\\UndeclaredFixture\\ExternalPolicy',
        );

        (new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage'))->compile();
    }

    #[Test]
    public function load_rejects_a_cached_manifest_missing_a_declared_policy(): void
    {
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => [[
                'name' => 'waaseyaa/node',
                'extra' => ['waaseyaa' => ['policies' => [
                    'Waaseyaa\\Node\\NodeAccessPolicy',
                ]]],
            ]]], JSON_THROW_ON_ERROR),
        );
        $cacheDir = $this->tempDir . '/storage/framework';
        mkdir($cacheDir, 0o755, true);
        file_put_contents(
            $cacheDir . '/packages.php',
            '<?php return ' . var_export((new PackageManifest())->toArray(), true) . ';',
        );

        $this->expectException(PolicyManifestMismatchException::class);
        $this->expectExceptionMessage('discovered 0 package access policies, manifest declares 1');

        (new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage'))->load();
    }

    #[Test]
    public function compile_refuses_an_installed_package_policy_omitted_from_the_inventory(): void
    {
        $packageRoot = $this->tempDir . '/vendor/waaseyaa/security-fixture';
        $sourceDir = $packageRoot . '/src';
        mkdir($sourceDir, 0o755, true);
        $policyPath = $sourceDir . '/UndeclaredPolicy.php';
        file_put_contents($policyPath, <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Waaseyaa\SecurityFixture;
            use Waaseyaa\Access\Gate\PolicyAttribute;
            #[PolicyAttribute(entityType: 'security_fixture')]
            final class UndeclaredPolicy {}
            PHP);
        require_once $policyPath;
        $packageComposer = [
            'name' => 'waaseyaa/security-fixture',
            'autoload' => ['psr-4' => ['Waaseyaa\\SecurityFixture\\' => 'src/']],
        ];
        file_put_contents(
            $packageRoot . '/composer.json',
            json_encode($packageComposer, JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => [[
                ...$packageComposer,
                'install-path' => '../waaseyaa/security-fixture',
            ]]], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_classmap.php',
            '<?php return [];',
        );
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_psr4.php',
            '<?php return ["Waaseyaa\\\\SecurityFixture\\\\" => [' . var_export($sourceDir, true) . ']];',
        );

        $this->expectException(PolicyManifestMismatchException::class);
        $this->expectExceptionMessage(
            'Missing: (none); unexpected: Waaseyaa\\SecurityFixture\\UndeclaredPolicy',
        );

        (new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage'))->compile();
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

    #[Test]
    public function load_logs_error_and_returns_manifest_when_provider_permanently_missing(): void
    {
        $composer = [
            'name' => 'test/root',
            'extra' => [
                'waaseyaa' => [
                    'providers' => ['App\\Provider\\PermanentlyMissing'],
                ],
            ],
        ];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        $storagePath = $this->tempDir . '/storage';

        $logger = new class implements LoggerInterface {
            use LoggerTrait;

            /** @var list<array{level: LogLevel, message: string}> */
            public array $messages = [];

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = ['level' => $level, 'message' => (string) $message];
            }
        };

        $compiler = new PackageManifestCompiler($this->tempDir, $storagePath, $logger);
        $manifest = $compiler->load();

        // Manifest is returned despite missing provider (no crash, no recompile loop)
        $this->assertContains('App\\Provider\\PermanentlyMissing', $manifest->providers);

        // Error (not warning) is logged with actionable guidance
        $errorMessages = array_filter($logger->messages, fn($m) => $m['level'] === LogLevel::ERROR);
        $this->assertNotEmpty($errorMessages, 'Expected an error log for permanently missing provider');
        $errorMessage = array_values($errorMessages)[0]['message'];
        $this->assertStringContainsString('PermanentlyMissing', $errorMessage);
        $this->assertStringContainsString('composer.json', $errorMessage);
    }

    #[Test]
    public function load_does_not_recompile_on_subsequent_requests_when_provider_permanently_missing(): void
    {
        $composer = [
            'name' => 'test/root',
            'extra' => [
                'waaseyaa' => [
                    'providers' => ['App\\Provider\\PermanentlyMissing'],
                ],
            ],
        ];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        $storagePath = $this->tempDir . '/storage';

        $logger = new class implements LoggerInterface {
            use LoggerTrait;

            /** @var list<array{level: LogLevel, message: string}> */
            public array $messages = [];

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = ['level' => $level, 'message' => (string) $message];
            }
        };

        // First load: compiles, detects permanently missing, logs error
        $compiler = new PackageManifestCompiler($this->tempDir, $storagePath, $logger);
        $compiler->load();

        $logger->messages = [];

        // Second load (simulates next request): should NOT recompile
        $compiler2 = new PackageManifestCompiler($this->tempDir, $storagePath, $logger);
        $manifest = $compiler2->load();

        // Manifest returned with the missing provider (it's declared in composer.json)
        $this->assertContains('App\\Provider\\PermanentlyMissing', $manifest->providers);

        // Should NOT have a warning about stale manifest (which would indicate recompile)
        $warningMessages = array_filter($logger->messages, fn($m) => $m['level'] === LogLevel::WARNING);
        $this->assertEmpty(
            $warningMessages,
            'Expected no recompile warning on second load — provider was already known-missing',
        );

        // Should still log an error about the missing provider
        $errorMessages = array_filter($logger->messages, fn($m) => $m['level'] === LogLevel::ERROR);
        $this->assertNotEmpty($errorMessages, 'Expected error log for permanently missing provider');
    }

    #[Test]
    public function load_recompiles_when_known_missing_set_changes(): void
    {
        $composer = [
            'name' => 'test/root',
            'extra' => [
                'waaseyaa' => [
                    'providers' => ['App\\Provider\\MissingA'],
                ],
            ],
        ];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        $storagePath = $this->tempDir . '/storage';

        $logger = new class implements LoggerInterface {
            use LoggerTrait;

            /** @var list<array{level: LogLevel, message: string}> */
            public array $messages = [];

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = ['level' => $level, 'message' => (string) $message];
            }
        };

        // First load: stamps MissingA as known-missing
        $compiler = new PackageManifestCompiler($this->tempDir, $storagePath, $logger);
        $compiler->load();

        // Now change composer.json to declare a different missing provider
        $composer['extra']['waaseyaa']['providers'] = ['App\\Provider\\MissingA', 'App\\Provider\\MissingB'];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));

        $logger->messages = [];

        // Second load: fingerprint changed, so full recompile (new missing set)
        $compiler2 = new PackageManifestCompiler($this->tempDir, $storagePath, $logger);
        $manifest = $compiler2->load();

        $this->assertContains('App\\Provider\\MissingA', $manifest->providers);
        $this->assertContains('App\\Provider\\MissingB', $manifest->providers);

        // Should have logged error (post-recompile), confirming recompile happened
        $errorMessages = array_filter($logger->messages, fn($m) => $m['level'] === LogLevel::ERROR);
        $this->assertNotEmpty($errorMessages, 'Expected recompile when known-missing set changes');
    }

    #[Test]
    public function load_clears_known_missing_when_provider_is_fixed(): void
    {
        $composer = [
            'name' => 'test/root',
            'extra' => [
                'waaseyaa' => [
                    'providers' => ['App\\Provider\\PermanentlyMissing'],
                ],
            ],
        ];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        $storagePath = $this->tempDir . '/storage';

        $logger = new class implements LoggerInterface {
            use LoggerTrait;

            /** @var list<array{level: LogLevel, message: string}> */
            public array $messages = [];

            public function log(LogLevel $level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = ['level' => $level, 'message' => (string) $message];
            }
        };

        // First load: stamps as known-missing
        $compiler = new PackageManifestCompiler($this->tempDir, $storagePath, $logger);
        $compiler->load();

        // Fix: remove the bad provider from composer.json
        $composer['extra']['waaseyaa']['providers'] = [\stdClass::class];
        file_put_contents($this->tempDir . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));

        $logger->messages = [];

        // Second load: fingerprint changed, recompile picks up the fix
        $compiler2 = new PackageManifestCompiler($this->tempDir, $storagePath, $logger);
        $manifest = $compiler2->load();

        $this->assertContains(\stdClass::class, $manifest->providers);
        $this->assertNotContains('App\\Provider\\PermanentlyMissing', $manifest->providers);

        // No errors — the fix worked
        $errorMessages = array_filter($logger->messages, fn($m) => $m['level'] === LogLevel::ERROR);
        $this->assertEmpty($errorMessages, 'No error expected after fixing the provider declaration');
    }

    #[Test]
    public function discoversScheduleEntries(): void
    {
        // Load the fixture class so it is available in the classmap scan
        // __DIR__ = packages/foundation/tests/Unit/Discovery
        // dirname(__DIR__, 4) = packages/
        $fixtureFile = dirname(__DIR__, 4)
            . '/scheduler/tests/fixtures/TestScheduleEntries.php';
        require_once $fixtureFile;

        $fixtureClass = 'Waaseyaa\\Scheduler\\Tests\\Fixtures\\TestScheduleEntries';

        // Classmap pointing to the fixture
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_classmap.php',
            '<?php return [\'' . $fixtureClass . '\' => \'' . $fixtureFile . '\'];',
        );

        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        $compiler = new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage');

        $start = microtime(true);
        $manifest = $compiler->compile();
        $elapsed = (microtime(true) - $start) * 1000;

        // FR-009: fixture FQCN appears in scheduleEntries
        $this->assertContains(
            $fixtureClass,
            $manifest->scheduleEntries,
            'ScheduleEntriesInterface implementor should be discovered and recorded',
        );

        // NFR-001: compile should complete well under 50 ms for a minimal fixture scan
        $this->assertLessThan(50, $elapsed, sprintf('Compile took %.1f ms, expected < 50 ms', $elapsed));

        // round-trip: schedule_entries key survives toArray()/fromArray()
        $round = PackageManifest::fromArray($manifest->toArray());
        $this->assertContains($fixtureClass, $round->scheduleEntries);
    }

    /**
     * Regression test: a Waaseyaa\ class in the classmap whose parent is a
     * dev-only dependency (absent in production) throws \Error — not
     * \ReflectionException — when PHP's autoloader requires its file.
     * filterDiscoveryClasses() must catch \Throwable and skip the class so
     * compile() completes without crashing. This covers the alpha.106→107
     * outage pattern (waaseyaa/graphql) and the W4-1 scanner-hardening gate.
     */
    #[Test]
    public function compile_skips_class_that_fatals_on_autoload(): void
    {
        $uniqueId = str_replace('.', '', uniqid('', true));
        $ns = 'Waaseyaa\\Foundation\\Tests\\TmpScan\\N' . $uniqueId;
        $brokenFqcn = $ns . '\\BrokenClass';

        // Write a class file whose parent does not exist in any autoloader.
        // Requiring this file causes PHP to throw \Error when it cannot resolve
        // the parent class — the exact fatal that crashed production in alpha.106.
        $brokenFile = $this->tempDir . '/BrokenClass.php';
        file_put_contents($brokenFile, sprintf(
            "<?php\ndeclare(strict_types=1);\nnamespace %s;\nfinal class BrokenClass extends \\Definitely\\Missing\\NonexistentParentClass {}\n",
            $ns,
        ));

        // Temporary autoloader registered so that when filterDiscoveryClasses()
        // does new \ReflectionClass($brokenFqcn), PHP triggers spl_autoload_call,
        // our closure requires the file, and \Error propagates — reproducing the
        // production crash chain without touching Composer's real autoload files.
        $autoloader = static function (string $class) use ($brokenFqcn, $brokenFile): void {
            if ($class === $brokenFqcn) {
                require $brokenFile;
            }
        };
        spl_autoload_register($autoloader);

        // Classmap lists the broken FQCN so scanClasses() includes it as a candidate.
        file_put_contents(
            $this->tempDir . '/vendor/composer/autoload_classmap.php',
            sprintf('<?php return [%s => %s];', var_export($brokenFqcn, true), var_export($brokenFile, true)),
        );
        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode(['packages' => []], JSON_THROW_ON_ERROR),
        );

        try {
            $compiler = new PackageManifestCompiler($this->tempDir, $this->tempDir . '/storage');

            // Must not throw — filterDiscoveryClasses() catches \Throwable from autoload.
            $manifest = $compiler->compile();

            $this->assertArrayNotHasKey($brokenFqcn, $manifest->policies, 'Fatal-on-autoload class must not appear in discovered policies.');
            $this->assertSame([], $manifest->middleware, 'Fatal-on-autoload class must not appear in discovered middleware.');
        } finally {
            spl_autoload_unregister($autoloader);
            if (file_exists($brokenFile)) {
                unlink($brokenFile);
            }
        }
    }

}

#[ContentEntityType(id: 'compiler_content_fixture')]
#[ContentEntityKeys(id: 'id', uuid: 'uuid', label: 'label')]
final class CompilerContentEntityFixture extends ContentEntityBase
{
}

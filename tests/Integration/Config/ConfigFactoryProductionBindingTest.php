<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Config;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Authority\ConfigurationAuthorityResolver;
use Waaseyaa\Config\Authority\ConfigurationAuthorityServiceProvider;
use Waaseyaa\Config\Authority\ConfigurationAuthorityUnavailableException;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigManagerInterface;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\EntityStorage\Config\ConfigurationStorageServiceProvider;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
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
    private string $projectRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = $this->createMinimalProjectRoot();
        $this->seedActiveConfiguration();
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
            'ConfigFactoryInterface must resolve at boot: ' . (ConfigFactoryProbeProvider::$resolutionErrors[ConfigFactoryInterface::class] ?? 'no diagnostic'),
        );
        self::assertInstanceOf(ConfigurationAuthorityContext::class, ConfigFactoryProbeProvider::$resolvedContext);
        self::assertSame(str_repeat('b', 64), ConfigFactoryProbeProvider::$resolvedContext->activeGenerationId);
    }

    #[Test]
    public function resolvedFactoryReadsThePinnedGenerationAndRefusesDirectMutation(): void
    {
        $kernel = $this->buildKernel();
        $kernel->publicBoot();

        $factory = ConfigFactoryProbeProvider::$resolvedFactory;
        self::assertNotNull($factory);

        $config = $factory->get('system.site');
        self::assertFalse($config->isNew());
        self::assertSame('Waaseyaa', $config->get('name'));

        $this->expectException(ConfigurationAuthorityUnavailableException::class);
        $this->expectExceptionMessage('CFG-02 transactional activation');
        $factory->getEditable('system.site')->set('name', 'Changed')->save();
    }

    #[Test]
    public function configManagerInterfaceResolvesFromARealKernelBoot(): void
    {
        $kernel = $this->buildKernel();
        $kernel->publicBoot();

        self::assertInstanceOf(ConfigManagerInterface::class, ConfigFactoryProbeProvider::$resolvedManager);
        self::assertSame(['system.site'], ConfigFactoryProbeProvider::$resolvedManager->getActiveStorage()->listAll());
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
                        [
                            ConfigurationAuthorityServiceProvider::class,
                            ConfigurationStorageServiceProvider::class,
                            ConfigFactoryProbeProvider::class,
                        ],
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
            sprintf(
                "<?php return ['database' => %s, 'environment' => 'testing'];",
                var_export($projectRoot . '/storage/waaseyaa.sqlite', true),
            ),
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

    private function seedActiveConfiguration(): void
    {
        $database = DBALDatabase::createSqlite($this->projectRoot . '/storage/waaseyaa.sqlite', 'testing');
        $migration = require dirname(__DIR__, 3) . '/packages/entity-storage/migrations/2026_08_12_000002_configuration_authority.php';
        $migration->up(new SchemaBuilder($database->getConnection()));
        $context = new ConfigurationAuthorityResolver()->resolve(
            $this->projectRoot,
            $database->databaseIdentity(),
            ['database' => $this->projectRoot . '/storage/waaseyaa.sqlite', 'environment' => 'testing'],
            [],
        );
        $generationId = str_repeat('b', 64);
        $file = new ConfigSyncFile(
            entityType: 'system',
            entityId: 'site',
            uuid: ConfigSyncFile::deterministicUuid('system', 'site'),
            dependencies: [],
            langcode: 'en',
            fields: ['name' => 'Waaseyaa'],
        );
        $database->query(
            'INSERT INTO waaseyaa_config_generation '
            . '(authority_id, generation_id, activation_sequence, schema_version, manifest_hash, lifecycle_state, created_at) '
            . 'VALUES (?, ?, 1, ?, ?, ?, ?)',
            [$context->authorityId, $generationId, 'config-schema.v1', str_repeat('c', 64), 'active', '2026-08-12T00:00:00Z'],
        );
        $database->query(
            'INSERT INTO waaseyaa_config_entry '
            . '(authority_id, generation_id, config_name, entity_type, entity_id, uuid, dependencies_json, langcode, fields_json, content_hash) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $context->authorityId,
                $generationId,
                $file->ref(),
                $file->entityType,
                $file->entityId,
                $file->uuid,
                '[]',
                $file->langcode,
                json_encode($file->fields, JSON_THROW_ON_ERROR),
                $file->contentHash(),
            ],
        );
        $database->query(
            'INSERT INTO waaseyaa_config_activation (authority_id, generation_id, activation_sequence, activated_at) '
            . 'VALUES (?, ?, 1, ?)',
            [$context->authorityId, $generationId, '2026-08-12T00:00:00Z'],
        );
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
    public static ?ConfigurationAuthorityContext $resolvedContext = null;
    /** @var array<class-string, string> */
    public static array $resolutionErrors = [];

    public function register(): void {}

    public function boot(): void
    {
        self::$probed = true;
        self::$resolvedFactory = $this->capture(ConfigFactoryInterface::class);
        self::$resolvedManager = $this->capture(ConfigManagerInterface::class);
        $context = $this->capture(ConfigurationAuthorityContext::class);
        self::$resolvedContext = $context instanceof ConfigurationAuthorityContext ? $context : null;
    }

    public static function reset(): void
    {
        self::$probed = false;
        self::$resolvedFactory = null;
        self::$resolvedManager = null;
        self::$resolvedContext = null;
        self::$resolutionErrors = [];
    }

    /** @param class-string $abstract */
    private function capture(string $abstract): ?object
    {
        try {
            return $this->resolve($abstract);
        } catch (\Throwable $exception) {
            self::$resolutionErrors[$abstract] = $exception::class . ': ' . $exception->getMessage();

            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Waaseyaa\Access\PermissionHandler;
use Waaseyaa\AI\Vector\EmbeddingProviderFactory;
use Waaseyaa\AI\Vector\SemanticIndexWarmer;
use Waaseyaa\AI\Vector\SqliteEmbeddingStorage;
use Waaseyaa\Cache\Backend\DatabaseBackend;
use Waaseyaa\Cache\CacheConfiguration;
use Waaseyaa\Cache\CacheFactory;
use Waaseyaa\CLI\Command\AboutCommand;
use Waaseyaa\CLI\Command\AuditLogCommand;
use Waaseyaa\CLI\Command\BundleScaffoldCommand;
use Waaseyaa\CLI\Command\CacheClearCommand;
use Waaseyaa\CLI\Command\ConfigExportCommand;
use Waaseyaa\CLI\Command\ConfigImportCommand;
use Waaseyaa\CLI\Command\DebugContextCommand;
use Waaseyaa\CLI\Command\EntityCreateCommand;
use Waaseyaa\CLI\Command\EntityListCommand;
use Waaseyaa\CLI\Command\EntityTypeListCommand;
use Waaseyaa\CLI\Command\EventListCommand;
use Waaseyaa\CLI\Command\ExtensionScaffoldCommand;
use Waaseyaa\CLI\Command\FixtureGenerateCommand;
use Waaseyaa\CLI\Command\FixturePackRefreshCommand;
use Waaseyaa\CLI\Command\FixtureScaffoldCommand;
use Waaseyaa\CLI\Command\HealthCheckCommand;
use Waaseyaa\CLI\Command\HealthReportCommand;
use Waaseyaa\CLI\Command\IngestDashboardCommand;
use Waaseyaa\CLI\Command\IngestRunCommand;
use Waaseyaa\CLI\Command\InstallCommand;
use Waaseyaa\CLI\Command\Make\MakeEntityCommand;
use Waaseyaa\CLI\Command\Make\MakeJobCommand;
use Waaseyaa\CLI\Command\Make\MakeListenerCommand;
use Waaseyaa\CLI\Command\Make\MakeMigrationCommand;
use Waaseyaa\CLI\Command\Make\MakePolicyCommand;
use Waaseyaa\CLI\Command\MigrateCommand;
use Waaseyaa\CLI\Command\MigrateRollbackCommand;
use Waaseyaa\CLI\Command\MigrateStatusCommand;
use Waaseyaa\CLI\Command\Make\MakeProviderCommand;
use Waaseyaa\CLI\Command\Make\MakeTestCommand;
use Waaseyaa\CLI\Command\MakeEntityTypeCommand;
use Waaseyaa\CLI\Command\MakePluginCommand;
use Waaseyaa\CLI\Command\MigrateDefaultsCommand;
use Waaseyaa\CLI\Command\Optimize\OptimizeClearCommand;
use Waaseyaa\CLI\Command\Optimize\OptimizeCommand;
use Waaseyaa\CLI\Command\Optimize\OptimizeConfigCommand;
use Waaseyaa\CLI\Command\Optimize\OptimizeManifestCommand;
use Waaseyaa\CLI\Command\Perf\PerformanceBaselineCommand;
use Waaseyaa\CLI\Command\Perf\PerformanceCompareCommand;
use Waaseyaa\CLI\Command\PermissionListCommand;
use Waaseyaa\CLI\Command\RelationshipTypeScaffoldCommand;
use Waaseyaa\CLI\Command\RouteListCommand;
use Waaseyaa\CLI\Command\SchemaCheckCommand;
use Waaseyaa\CLI\Command\SchemaListCommand;
use Waaseyaa\CLI\Command\SemanticRefreshCommand;
use Waaseyaa\CLI\Command\SemanticWarmCommand;
use Waaseyaa\CLI\Command\Telescope\TelescopeClearCommand;
use Waaseyaa\CLI\Command\Telescope\TelescopeListCommand;
use Waaseyaa\CLI\Command\Telescope\TelescopePruneCommand;
use Waaseyaa\CLI\Command\TypeDisableCommand;
use Waaseyaa\CLI\Command\TypeEnableCommand;
use Waaseyaa\CLI\Command\UserCreateCommand;
use Waaseyaa\CLI\Command\UserRoleCommand;
use Waaseyaa\CLI\Command\WorkflowScaffoldCommand;
use Waaseyaa\CLI\WaaseyaaApplication;
use Waaseyaa\Config\Cache\ConfigCacheCompiler;
use Waaseyaa\Config\ConfigManager;
use Waaseyaa\Config\Storage\FileStorage;
use Waaseyaa\Entity\EntityTypeIdNormalizer;
use Waaseyaa\Foundation\Diagnostic\HealthChecker;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;
use Waaseyaa\Foundation\Schema\DefaultsSchemaRegistry;
use Waaseyaa\Routing\WaaseyaaRouter;

final class ConsoleKernel extends AbstractKernel
{
    public function handle(): int
    {
        try {
            $this->boot();
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf(
                "[Waaseyaa] Bootstrap failed: %s\n  in %s:%d\n",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));
            return 1;
        }

        try {
            $configDir = $this->config['config_dir']
                ?? (getenv('WAASEYAA_CONFIG_DIR') ?: $this->projectRoot . '/config/sync');
            $activeDir = $this->projectRoot . '/config/active';

            if (!is_dir($activeDir) && !mkdir($activeDir, 0755, true) && !is_dir($activeDir)) {
                throw new \RuntimeException(sprintf('Unable to create config active directory: %s', $activeDir));
            }
            if (!is_dir($configDir) && !mkdir($configDir, 0755, true) && !is_dir($configDir)) {
                throw new \RuntimeException(sprintf('Unable to create config sync directory: %s', $configDir));
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf(
                "[Waaseyaa] Startup failed: %s\n  in %s:%d\n",
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));
            return 1;
        }

        $activeStorage = new FileStorage($activeDir);
        $syncStorage = new FileStorage($configDir);
        $configManager = new ConfigManager($activeStorage, $syncStorage, $this->dispatcher);

        $cacheConfig = new CacheConfiguration();
        $cacheConfig->setFactoryForBin('render', fn(): DatabaseBackend => new DatabaseBackend(
            $this->database->getPdo(),
            'cache_render',
        ));
        $cacheFactory = new CacheFactory($cacheConfig);
        $router = new WaaseyaaRouter();
        $permissionHandler = new PermissionHandler();
        $manifestCompiler = new PackageManifestCompiler(
            basePath: $this->projectRoot,
            storagePath: $this->projectRoot . '/storage',
        );
        $embeddingStorage = new SqliteEmbeddingStorage($this->database->getPdo());
        $embeddingProvider = EmbeddingProviderFactory::fromConfig($this->config);
        $semanticWarmer = new SemanticIndexWarmer(
            entityTypeManager: $this->entityTypeManager,
            embeddingStorage: $embeddingStorage,
            embeddingProvider: $embeddingProvider,
        );
        $schemaRegistry = new DefaultsSchemaRegistry($this->projectRoot . '/defaults');
        $healthChecker = new HealthChecker(
            bootReport: $this->getBootReport(),
            database: $this->database,
            entityTypeManager: $this->entityTypeManager,
            projectRoot: $this->projectRoot,
        );

        $typeIdNormalizer = new EntityTypeIdNormalizer($this->entityTypeManager);

        $app = new WaaseyaaApplication();
        $app->setAutoExit(false);

        $app->registerCommands([
            new InstallCommand($this->entityTypeManager, $configManager),
            new CacheClearCommand($cacheFactory),
            new ConfigExportCommand($configManager),
            new ConfigImportCommand($configManager),
            new DebugContextCommand(),
            new EntityCreateCommand($this->entityTypeManager),
            new EntityListCommand($this->entityTypeManager),
            new UserCreateCommand($this->entityTypeManager),
            new UserRoleCommand($this->entityTypeManager),
            new MakePluginCommand(),
            new MigrateDefaultsCommand(
                $this->entityTypeManager,
                $this->lifecycleManager,
                $this->entityAuditLogger,
                $this->projectRoot,
                $typeIdNormalizer,
            ),
            new MakeEntityTypeCommand(),
            new MakeEntityCommand(),
            new MakeJobCommand(),
            new MakeListenerCommand(),
            new MakeMigrationCommand($this->projectRoot),
            new MakePolicyCommand(),
            new MakeProviderCommand(),
            new MakeTestCommand(),
            new AboutCommand(info: [
                'name' => 'Waaseyaa',
                'version' => (static function (): string {
                    $pkg = \Composer\InstalledVersions::getRootPackage();
                    return $pkg['pretty_version'];
                })(),
                'php' => PHP_VERSION,
                'environment' => getenv('APP_ENV') ?: 'production',
            ]),
            new EntityTypeListCommand($this->entityTypeManager),
            new TypeDisableCommand($this->entityTypeManager, $this->lifecycleManager, $typeIdNormalizer),
            new TypeEnableCommand($this->entityTypeManager, $this->lifecycleManager, $typeIdNormalizer),
            new AuditLogCommand($this->lifecycleManager, $this->entityAuditLogger),
            new EventListCommand($this->dispatcher),
            new RouteListCommand($router),
            new SchemaListCommand($schemaRegistry),
            new SchemaCheckCommand($healthChecker),
            new HealthCheckCommand($healthChecker),
            new HealthReportCommand($healthChecker, $this->projectRoot),
            new PermissionListCommand($permissionHandler),
            new SemanticWarmCommand($semanticWarmer),
            new SemanticRefreshCommand($semanticWarmer),
            new FixtureScaffoldCommand(),
            new FixtureGenerateCommand(),
            new FixturePackRefreshCommand(),
            new IngestDashboardCommand(),
            new IngestRunCommand(),
            new BundleScaffoldCommand(),
            new RelationshipTypeScaffoldCommand(),
            new WorkflowScaffoldCommand(),
            new ExtensionScaffoldCommand(),
            new OptimizeCommand(),
            new OptimizeManifestCommand($manifestCompiler),
            new OptimizeConfigCommand(new ConfigCacheCompiler(
                $activeStorage,
                $this->projectRoot . '/storage/framework/config.php',
            )),
            new OptimizeClearCommand($this->projectRoot . '/storage'),
            new PerformanceBaselineCommand(),
            new PerformanceCompareCommand(),
            new TelescopeClearCommand(),
            new TelescopeListCommand(),
            new TelescopePruneCommand(),
        ]);

        $migrationsProvider = fn () => $this->migrationLoader->loadAll();
        $app->registerCommands([
            new MigrateCommand($this->migrator, $migrationsProvider),
            new MigrateRollbackCommand($this->migrator, $migrationsProvider),
            new MigrateStatusCommand($this->migrator, $migrationsProvider),
        ]);

        foreach ($this->providers as $provider) {
            $pluginCommands = $provider->commands($this->entityTypeManager, $this->database, $this->dispatcher);
            if ($pluginCommands !== []) {
                $app->registerCommands($pluginCommands);
            }
        }

        return $app->run();
    }
}

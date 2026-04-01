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
use Waaseyaa\CLI\CliCommandRegistry;
use Waaseyaa\CLI\Command\Optimize\OptimizeManifestCommand;
use Waaseyaa\CLI\Command\WaaseyaaVersionCommand;
use Waaseyaa\CLI\WaaseyaaApplication;
use Waaseyaa\Config\ConfigManager;
use Waaseyaa\Config\Storage\FileStorage;
use Waaseyaa\Entity\EntityTypeIdNormalizer;
use Waaseyaa\Foundation\Diagnostic\HealthChecker;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;
use Waaseyaa\Foundation\Discovery\StaleManifestException;
use Waaseyaa\Foundation\Schema\DefaultsSchemaRegistry;
use Waaseyaa\Routing\WaaseyaaRouter;

final class ConsoleKernel extends AbstractKernel
{
    public function handle(): int
    {
        if ($this->shouldUseMinimalConsole()) {
            return $this->runMinimalConsole();
        }

        try {
            $this->boot();
        } catch (StaleManifestException $e) {
            fwrite(STDERR, sprintf(
                "[Waaseyaa] %s\n",
                $e->getMessage(),
            ));
            return 1;
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

        // Escape hatch: components that still require raw PDO (cache, embeddings).
        // These will be migrated to DBAL Connection in a future PR.
        assert($this->database instanceof \Waaseyaa\Database\DBALDatabase);
        $pdo = $this->database->getConnection()->getNativeConnection();
        assert($pdo instanceof \PDO);

        $cacheConfig = new CacheConfiguration();
        $cacheConfig->setFactoryForBin('render', fn(): DatabaseBackend => new DatabaseBackend(
            $pdo,
            'cache_render',
        ));
        $cacheFactory = new CacheFactory($cacheConfig);
        $router = new WaaseyaaRouter();
        $permissionHandler = new PermissionHandler();
        $manifestCompiler = new PackageManifestCompiler(
            basePath: $this->projectRoot,
            storagePath: $this->projectRoot . '/storage',
        );
        $semanticWarmer = null;
        if (class_exists(SqliteEmbeddingStorage::class)) {
            $embeddingStorage = new SqliteEmbeddingStorage($pdo);
            $embeddingProvider = EmbeddingProviderFactory::fromConfig($this->config);
            $semanticWarmer = new SemanticIndexWarmer(
                entityTypeManager: $this->entityTypeManager,
                embeddingStorage: $embeddingStorage,
                embeddingProvider: $embeddingProvider,
            );
        }
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
        $commandRegistry = new CliCommandRegistry();

        $app->registerCommands($commandRegistry->coreCommands(
            projectRoot: $this->projectRoot,
            config: $this->config,
            manifest: $this->manifest,
            dispatcher: $this->dispatcher,
            entityTypeManager: $this->entityTypeManager,
            lifecycleManager: $this->lifecycleManager,
            entityAuditLogger: $this->entityAuditLogger,
            database: $this->database,
            configManager: $configManager,
            cacheFactory: $cacheFactory,
            router: $router,
            permissionHandler: $permissionHandler,
            manifestCompiler: $manifestCompiler,
            schemaRegistry: $schemaRegistry,
            healthChecker: $healthChecker,
            typeIdNormalizer: $typeIdNormalizer,
            semanticWarmer: $semanticWarmer,
            pdo: $pdo,
        ));

        $migrationsProvider = fn() => $this->migrationLoader->loadAll();
        $app->registerCommands($commandRegistry->migrationCommands($this->migrator, $migrationsProvider));

        foreach ($this->providers as $provider) {
            $pluginCommands = $provider->commands($this->entityTypeManager, $this->database, $this->dispatcher);
            if ($pluginCommands !== []) {
                $app->registerCommands($pluginCommands);
            }
        }

        return $app->run();
    }

    private function shouldUseMinimalConsole(): bool
    {
        $name = $this->requestedCommandName();

        return $name === 'optimize:manifest' || $name === 'waaseyaa:version';
    }

    private function requestedCommandName(): ?string
    {
        $argv = $_SERVER['argv'] ?? [];

        foreach (array_slice($argv, 1) as $arg) {
            if (!is_string($arg)) {
                continue;
            }

            if ($arg === '' || str_starts_with($arg, '-')) {
                continue;
            }

            return $arg;
        }

        return null;
    }

    private function runMinimalConsole(): int
    {
        $app = new WaaseyaaApplication();
        $app->setAutoExit(false);
        if ($this->requestedCommandName() === 'waaseyaa:version') {
            $app->registerCommands([
                new WaaseyaaVersionCommand($this->projectRoot),
            ]);
        } else {
            $app->registerCommands([
                new OptimizeManifestCommand(new PackageManifestCompiler(
                    basePath: $this->projectRoot,
                    storagePath: $this->projectRoot . '/storage',
                )),
            ]);
        }

        return $app->run();
    }
}

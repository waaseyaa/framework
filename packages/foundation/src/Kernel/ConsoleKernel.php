<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Waaseyaa\Access\PermissionHandler;
use Waaseyaa\Cache\CacheFactory;
use Waaseyaa\CLI\Command\AboutCommand;
use Waaseyaa\CLI\Command\CacheClearCommand;
use Waaseyaa\CLI\Command\ConfigExportCommand;
use Waaseyaa\CLI\Command\ConfigImportCommand;
use Waaseyaa\CLI\Command\EntityCreateCommand;
use Waaseyaa\CLI\Command\EntityListCommand;
use Waaseyaa\CLI\Command\EntityTypeListCommand;
use Waaseyaa\CLI\Command\EventListCommand;
use Waaseyaa\CLI\Command\InstallCommand;
use Waaseyaa\CLI\Command\Make\MakeEntityCommand;
use Waaseyaa\CLI\Command\Make\MakeJobCommand;
use Waaseyaa\CLI\Command\Make\MakeListenerCommand;
use Waaseyaa\CLI\Command\Make\MakeMigrationCommand;
use Waaseyaa\CLI\Command\Make\MakePolicyCommand;
use Waaseyaa\CLI\Command\Make\MakeProviderCommand;
use Waaseyaa\CLI\Command\Make\MakeTestCommand;
use Waaseyaa\CLI\Command\MakeEntityTypeCommand;
use Waaseyaa\CLI\Command\MakePluginCommand;
use Waaseyaa\CLI\Command\Optimize\OptimizeClearCommand;
use Waaseyaa\CLI\Command\Optimize\OptimizeCommand;
use Waaseyaa\CLI\Command\Optimize\OptimizeConfigCommand;
use Waaseyaa\CLI\Command\Optimize\OptimizeManifestCommand;
use Waaseyaa\CLI\Command\PermissionListCommand;
use Waaseyaa\CLI\Command\RouteListCommand;
use Waaseyaa\CLI\Command\Telescope\TelescopeClearCommand;
use Waaseyaa\CLI\Command\Telescope\TelescopeListCommand;
use Waaseyaa\CLI\Command\Telescope\TelescopePruneCommand;
use Waaseyaa\CLI\Command\UserCreateCommand;
use Waaseyaa\CLI\Command\UserRoleCommand;
use Waaseyaa\CLI\WaaseyaaApplication;
use Waaseyaa\Config\Cache\ConfigCacheCompiler;
use Waaseyaa\Config\ConfigManager;
use Waaseyaa\Config\Storage\FileStorage;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;
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

        $configDir = $this->config['config_dir']
            ?? (getenv('WAASEYAA_CONFIG_DIR') ?: $this->projectRoot . '/config/sync');
        $activeDir = $this->projectRoot . '/config/active';

        if (!is_dir($activeDir) && !mkdir($activeDir, 0755, true) && !is_dir($activeDir)) {
            throw new \RuntimeException(sprintf('Unable to create config active directory: %s', $activeDir));
        }
        if (!is_dir($configDir) && !mkdir($configDir, 0755, true) && !is_dir($configDir)) {
            throw new \RuntimeException(sprintf('Unable to create config sync directory: %s', $configDir));
        }

        $activeStorage = new FileStorage($activeDir);
        $syncStorage = new FileStorage($configDir);
        $configManager = new ConfigManager($activeStorage, $syncStorage, $this->dispatcher);

        $cacheFactory = new CacheFactory();
        $router = new WaaseyaaRouter();
        $permissionHandler = new PermissionHandler();
        $manifestCompiler = new PackageManifestCompiler(
            basePath: $this->projectRoot,
            storagePath: $this->projectRoot . '/storage',
        );

        $app = new WaaseyaaApplication();

        $app->registerCommands([
            new InstallCommand($this->entityTypeManager, $configManager),
            new CacheClearCommand($cacheFactory),
            new ConfigExportCommand($configManager),
            new ConfigImportCommand($configManager),
            new EntityCreateCommand($this->entityTypeManager),
            new EntityListCommand($this->entityTypeManager),
            new UserCreateCommand($this->entityTypeManager),
            new UserRoleCommand($this->entityTypeManager),
            new MakePluginCommand(),
            new MakeEntityTypeCommand(),
            new MakeEntityCommand(),
            new MakeJobCommand(),
            new MakeListenerCommand(),
            new MakeMigrationCommand(),
            new MakePolicyCommand(),
            new MakeProviderCommand(),
            new MakeTestCommand(),
            new AboutCommand(info: [
                'name' => 'Waaseyaa',
                'version' => '0.1.0',
                'php' => PHP_VERSION,
                'environment' => getenv('APP_ENV') ?: 'production',
            ]),
            new EntityTypeListCommand($this->entityTypeManager),
            new EventListCommand($this->dispatcher),
            new RouteListCommand($router),
            new PermissionListCommand($permissionHandler),
            new OptimizeCommand(),
            new OptimizeManifestCommand($manifestCompiler),
            new OptimizeConfigCommand(new ConfigCacheCompiler(
                $activeStorage,
                $this->projectRoot . '/storage/framework/config.php',
            )),
            new OptimizeClearCommand($this->projectRoot . '/storage'),
            new TelescopeClearCommand(),
            new TelescopeListCommand(),
            new TelescopePruneCommand(),
        ]);

        return $app->run();
    }
}

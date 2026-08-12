<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Provider;

use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\OptimizeClearHandler;
use Waaseyaa\CLI\Handler\OptimizeConfigHandler;
use Waaseyaa\CLI\Handler\OptimizeHandler;
use Waaseyaa\CLI\Handler\OptimizeManifestHandler;
use Waaseyaa\Config\Authority\ActiveConfigurationBridgeInterface;
use Waaseyaa\Config\Authority\ConfigurationAuthorityConflictException;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Cache\ConfigCacheCompiler;
use Waaseyaa\Foundation\Discovery\PackageManifestCompiler;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesConsoleCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class OptimizeServiceProvider extends ServiceProvider implements ProvidesConsoleCommandsInterface
{
    public function register(): void {}

    public function consoleCommands(): iterable
    {
        $root = $this->projectRoot !== '' ? $this->projectRoot : (string) getcwd();

        $manifestCompiler = new PackageManifestCompiler(
            basePath: $root,
            storagePath: $root . '/storage',
        );
        $manifestHandler = new OptimizeManifestHandler($manifestCompiler);

        $compileConfig = function (\Waaseyaa\CLI\Command\SymfonyCommandIO $io) use ($root): int {
            $context = $this->resolve(ConfigurationAuthorityContext::class);
            $bridge = $this->resolve(ActiveConfigurationBridgeInterface::class);
            assert($context instanceof ConfigurationAuthorityContext);
            assert($bridge instanceof ActiveConfigurationBridgeInterface);
            if ($bridge->authorityContext() !== $context) {
                throw new ConfigurationAuthorityConflictException(
                    'optimize:config received a divergent configuration authority context.',
                );
            }

            return new OptimizeConfigHandler(new ConfigCacheCompiler(
                storage: $bridge->activeStorage(),
                cachePath: $root . '/storage/framework/config.php',
                authorityContext: $context,
            ))->execute($io);
        };

        $clearHandler = new OptimizeClearHandler(storagePath: $root . '/storage');

        $optimizeHandler = new OptimizeHandler(subHandlers: [
            'optimize:manifest' => \Closure::fromCallable([$manifestHandler, 'execute']),
            'optimize:config'   => $compileConfig,
        ]);

        yield new HandlerCommand(
            name: 'optimize',
            description: 'Run all optimization compilers',
            handler: \Closure::fromCallable([$optimizeHandler, 'execute']),
        );

        yield new HandlerCommand(
            name: 'optimize:clear',
            description: 'Remove all cached optimization artifacts',
            handler: \Closure::fromCallable([$clearHandler, 'execute']),
        );

        yield new HandlerCommand(
            name: 'optimize:config',
            description: 'Compile and cache all configuration',
            handler: $compileConfig,
        );

        yield new HandlerCommand(
            name: 'optimize:manifest',
            description: 'Compile the package discovery manifest',
            handler: \Closure::fromCallable([$manifestHandler, 'execute']),
        );
    }
}

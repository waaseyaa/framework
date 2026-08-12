<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Config;

use Waaseyaa\Config\Authority\ActiveConfigurationBridgeInterface;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Authority\ConfigurationGenerationResolverInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/** Higher-layer implementation of the configuration authority seams. */
final class ConfigurationStorageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(ConfigurationGenerationResolverInterface::class, function (): DatabaseConfigurationGenerationResolver {
            $database = $this->resolve(DatabaseInterface::class);
            assert($database instanceof DatabaseInterface);

            return new DatabaseConfigurationGenerationResolver($database);
        });
        $this->singleton(ActiveConfigurationBridgeInterface::class, function (): DatabaseActiveConfigurationBridge {
            $database = $this->resolve(DatabaseInterface::class);
            $context = $this->resolve(ConfigurationAuthorityContext::class);
            assert($database instanceof DatabaseInterface);
            assert($context instanceof ConfigurationAuthorityContext);

            return new DatabaseActiveConfigurationBridge($database, $context);
        });
    }
}

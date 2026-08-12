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
        $testing = strtolower((string) ($this->config['environment'] ?? '')) === 'testing';
        $this->singleton(ConfigurationGenerationResolverInterface::class, function () use ($testing): ConfigurationGenerationResolverInterface {
            $database = $this->resolve(DatabaseInterface::class);
            assert($database instanceof DatabaseInterface);

            $resolver = new DatabaseConfigurationGenerationResolver($database);

            return $testing ? new TestingConfigurationGenerationResolver($resolver) : $resolver;
        });
        $this->singleton(ActiveConfigurationBridgeInterface::class, function () use ($testing): ActiveConfigurationBridgeInterface {
            $database = $this->resolve(DatabaseInterface::class);
            $context = $this->resolve(ConfigurationAuthorityContext::class);
            assert($database instanceof DatabaseInterface);
            assert($context instanceof ConfigurationAuthorityContext);

            if ($testing && $context->activeGenerationId === TestingConfigurationGenerationResolver::generationId($context)) {
                return new TestingActiveConfigurationBridge($context);
            }

            return new DatabaseActiveConfigurationBridge($database, $context);
        });
    }
}

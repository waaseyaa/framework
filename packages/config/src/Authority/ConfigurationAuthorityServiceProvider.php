<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Authority;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as ComponentEventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface as ContractEventDispatcherInterface;
use Waaseyaa\Config\ConfigFactory;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigManager;
use Waaseyaa\Config\ConfigManagerInterface;
use Waaseyaa\Database\DatabaseIdentityProviderInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\CapabilityDeclaration;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesCapabilitiesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/** Sole production composition root for configuration.authority.v1. @api */
class ConfigurationAuthorityServiceProvider extends ServiceProvider implements ProvidesCapabilitiesInterface
{
    private const array BOOTSTRAP_ENVIRONMENT_KEYS = [
        'WAASEYAA_CONFIG_SYNC_PATH',
        'WAASEYAA_CONFIG_DIR',
    ];

    public function register(): void
    {
        $root = $this->projectRoot !== '' ? $this->projectRoot : (string) getcwd();
        $bootstrap = $this->config;

        $this->singleton(ConfigurationAuthorityResolver::class, ConfigurationAuthorityResolver::class);
        $this->singleton(ConfigurationAuthorityContext::class, function () use ($root, $bootstrap): ConfigurationAuthorityContext {
            $database = $this->resolveOptional(DatabaseIdentityProviderInterface::class);
            if (!$database instanceof DatabaseIdentityProviderInterface) {
                throw new ConfigurationAuthorityUnavailableException(
                    'configuration.authority.v1 database identity provider is unavailable.',
                );
            }

            $environment = [];
            foreach (self::BOOTSTRAP_ENVIRONMENT_KEYS as $key) {
                $value = getenv($key);
                if ($value !== false) {
                    $environment[$key] = $value;
                }
            }

            $resolver = $this->resolve(ConfigurationAuthorityResolver::class);
            assert($resolver instanceof ConfigurationAuthorityResolver);

            $context = $resolver->resolve($root, $database->databaseIdentity(), $bootstrap, $environment);
            $generationResolver = $this->kernelServices?->get(ConfigurationGenerationResolverInterface::class);

            return $generationResolver instanceof ConfigurationGenerationResolverInterface
                ? $generationResolver->bind($context)
                : $context;
        });
        $this->singleton(ConfigFactoryInterface::class, function (): ConfigFactory {
            $bridge = $this->resolveActiveBridge();

            return new ConfigFactory($bridge->activeStorage(), $this->resolveEventDispatcher());
        });
        $this->singleton(ConfigManagerInterface::class, function (): ConfigManager {
            $context = $this->resolve(ConfigurationAuthorityContext::class);
            $bridge = $this->resolveActiveBridge();
            assert($context instanceof ConfigurationAuthorityContext);

            return new ConfigManager(
                activeStorage: $bridge->activeStorage(),
                syncStorage: new SyncArtifactStorageAdapter($context),
                eventDispatcher: $this->resolveEventDispatcher(),
            );
        });
    }

    public function capabilityDeclarations(): iterable
    {
        $context = $this->resolve(ConfigurationAuthorityContext::class);
        assert($context instanceof ConfigurationAuthorityContext);

        yield new CapabilityDeclaration('configuration.authority.v1', 1, $context->authorityId);
    }

    private function resolveActiveBridge(): ActiveConfigurationBridgeInterface
    {
        $bridge = $this->kernelServices?->get(ActiveConfigurationBridgeInterface::class);
        if (!$bridge instanceof ActiveConfigurationBridgeInterface) {
            throw new ConfigurationAuthorityUnavailableException(
                'configuration.authority.v1 active-store bridge is unavailable; install the entity-storage authority bridge.',
            );
        }
        $context = $this->resolve(ConfigurationAuthorityContext::class);
        assert($context instanceof ConfigurationAuthorityContext);
        if ($bridge->authorityContext() !== $context) {
            throw new ConfigurationAuthorityConflictException(
                'configuration.authority.v1 bridge published a divergent authority context.',
            );
        }

        return $bridge;
    }

    private function resolveEventDispatcher(): ComponentEventDispatcherInterface
    {
        $dispatcher = $this->resolveOptional(ContractEventDispatcherInterface::class);

        return $dispatcher instanceof ComponentEventDispatcherInterface
            ? $dispatcher
            : new EventDispatcher();
    }
}

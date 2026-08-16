<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Authority;

use Waaseyaa\Config\Activation\ConfigurationActivatorInterface;
use Waaseyaa\Config\Activation\TransactionalConfigurationStorage;
use Waaseyaa\Config\Cache\CachedConfigFactory;
use Waaseyaa\Config\ConfigFactory;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigManager;
use Waaseyaa\Config\ConfigManagerInterface;
use Waaseyaa\Config\Event\ConfigurationSelectorDeprecationEvent;
use Waaseyaa\Config\Schema\ConfigSchemaValidator;
use Waaseyaa\Database\DatabaseIdentityProviderInterface;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\CapabilityDeclaration;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesCapabilitiesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/** Sole production composition root for configuration.authority.v1. @api */
class ConfigurationAuthorityServiceProvider extends ServiceProvider implements ProvidesCapabilitiesInterface
{
    private const array BOOTSTRAP_ONLY_ENVIRONMENTS = ['local', 'dev', 'development', 'testing'];

    private const array BOOTSTRAP_ENVIRONMENT_KEYS = [
        'WAASEYAA_CONFIG_SYNC_PATH',
        'WAASEYAA_CONFIG_DIR',
    ];

    public function register(): void
    {
        $root = $this->projectRoot !== '' ? $this->projectRoot : (string) getcwd();
        $bootstrap = $this->config;

        $this->singleton(ConfigurationAuthorityResolver::class, ConfigurationAuthorityResolver::class);
        $this->singleton(ConfigSchemaValidator::class, ConfigSchemaValidator::class);
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

            $context = $generationResolver instanceof ConfigurationGenerationResolverInterface
                ? $generationResolver->bind($context)
                : $context;
            foreach (['config_dir', 'WAASEYAA_CONFIG_DIR'] as $legacySelector) {
                if (!in_array($legacySelector, $context->selectorProvenance, true)) {
                    continue;
                }
                $canonicalSelector = $legacySelector === 'config_dir'
                    ? 'config.sync_path'
                    : 'WAASEYAA_CONFIG_SYNC_PATH';
                $this->resolveEventDispatcher()->dispatch(new ConfigurationSelectorDeprecationEvent(
                    legacySelector: $legacySelector,
                    canonicalSelector: $canonicalSelector,
                    authorityId: $context->authorityId,
                ));
            }

            return $context;
        });
        $this->singleton(ConfigFactoryInterface::class, function () use ($root): ConfigFactoryInterface {
            $bridge = $this->resolveActiveBridge();
            $context = $this->resolve(ConfigurationAuthorityContext::class);
            assert($context instanceof ConfigurationAuthorityContext);
            $storage = $this->mutationStorage($bridge, $context);
            $inner = new ConfigFactory($storage, $this->resolveEventDispatcher());

            return new CachedConfigFactory(
                $inner,
                $root . '/storage/framework/config.php',
                $context,
            );
        });
        $this->singleton(ConfigManagerInterface::class, function (): ConfigManager {
            $context = $this->resolve(ConfigurationAuthorityContext::class);
            $bridge = $this->resolveActiveBridge();
            assert($context instanceof ConfigurationAuthorityContext);

            return new ConfigManager(
                activeStorage: $this->mutationStorage($bridge, $context),
                syncStorage: new SyncArtifactStorageAdapter($context),
                eventDispatcher: $this->resolveEventDispatcher(),
            );
        });
    }

    public function capabilityDeclarations(): iterable
    {
        $context = $this->resolve(ConfigurationAuthorityContext::class);
        assert($context instanceof ConfigurationAuthorityContext);
        $environment = strtolower(trim((string) ($this->config['environment'] ?? 'production')));
        if (!in_array($environment, self::BOOTSTRAP_ONLY_ENVIRONMENTS, true)) {
            $context->requireActiveGenerationId();
        }

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

    private function mutationStorage(
        ActiveConfigurationBridgeInterface $bridge,
        ConfigurationAuthorityContext $context,
    ): \Waaseyaa\Config\StorageInterface {
        $storage = $bridge->activeStorage();
        $environment = strtolower(trim((string) ($this->config['environment'] ?? 'production')));
        if (in_array($environment, self::BOOTSTRAP_ONLY_ENVIRONMENTS, true)) {
            return $storage;
        }
        $activator = $this->kernelServices?->get(ConfigurationActivatorInterface::class);
        if (!$activator instanceof ConfigurationActivatorInterface) {
            throw new ConfigurationAuthorityUnavailableException(
                'Production editable configuration requires the transactional activation authority.',
            );
        }

        return new TransactionalConfigurationStorage(
            $storage,
            $activator,
            new ConfigurationActiveToken(
                $context->requireActiveGenerationId(),
                $context->activationSequence
                    ?? throw new \LogicException('Active configuration sequence is unavailable.'),
            ),
        );
    }

    private function resolveEventDispatcher(): EventDispatcherInterface&\Symfony\Contracts\EventDispatcher\EventDispatcherInterface
    {
        $dispatcher = $this->resolveOptional(EventDispatcherInterface::class);

        return $dispatcher instanceof EventDispatcherInterface
            && $dispatcher instanceof \Symfony\Contracts\EventDispatcher\EventDispatcherInterface
            ? $dispatcher
            : throw new ConfigurationAuthorityUnavailableException(
                'configuration.authority.v1 event dispatcher is unavailable.',
            );
    }
}

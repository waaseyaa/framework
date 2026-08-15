<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Authority;

use Waaseyaa\Config\Cache\CachedConfigFactory;
use Waaseyaa\Config\ConfigFactory;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigManager;
use Waaseyaa\Config\ConfigManagerInterface;
use Waaseyaa\Config\Event\ConfigurationSelectorDeprecationEvent;
use Waaseyaa\Config\Manifest\ConfigManifestEd25519Signer;
use Waaseyaa\Config\Manifest\ConfigManifestEd25519Verifier;
use Waaseyaa\Config\Manifest\ConfigManifestSignatureVerifierInterface;
use Waaseyaa\Config\Manifest\ConfigManifestSignerInterface;
use Waaseyaa\Config\Manifest\ConfigManifestTrustPolicy;
use Waaseyaa\Config\Manifest\Ed25519ManifestSigningOperation;
use Waaseyaa\Config\Manifest\UnsignedConfigPolicy;
use Waaseyaa\Config\Schema\Ai\McpServersConfig;
use Waaseyaa\Config\Schema\Ai\ProvidersConfig;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Schema\ConfigSchemaValidator;
use Waaseyaa\Database\DatabaseIdentityProviderInterface;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\ServiceProvider\Capability\CapabilityDeclaration;
use Waaseyaa\Foundation\ServiceProvider\Capability\FinalizesProviderBootInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesCapabilitiesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/** Sole production composition root for configuration.authority.v1. @api */
class ConfigurationAuthorityServiceProvider extends ServiceProvider implements FinalizesProviderBootInterface, ProvidesCapabilitiesInterface
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
        $this->registerManifestSigningCustody();

        $this->singleton(ConfigurationAuthorityResolver::class, ConfigurationAuthorityResolver::class);
        $this->singleton(ConfigSchemaValidator::class, ConfigSchemaValidator::class);
        $this->singleton(ConfigSchemaRegistry::class, fn(): ConfigSchemaRegistry => new ConfigSchemaRegistry(
            $this->resolve(ConfigSchemaValidator::class),
        ));
        $this->singleton(UnsignedConfigPolicy::class, function (): UnsignedConfigPolicy {
            $context = $this->resolve(ConfigurationAuthorityContext::class);
            assert($context instanceof ConfigurationAuthorityContext);

            // CFG-01 does not yet publish a sealed local/test bootstrap-policy
            // identity. Fail closed in every composed profile until it does;
            // mutable APP_ENV alone never grants unsigned authority.
            return UnsignedConfigPolicy::refusing($context->authorityId);
        });
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

    public function boot(): void
    {
        $registry = $this->resolve(ConfigSchemaRegistry::class);
        assert($registry instanceof ConfigSchemaRegistry);
        McpServersConfig::register($registry);
        ProvidersConfig::register($registry);
    }

    public function finalizeProviderBoot(): void
    {
        $registry = $this->resolve(ConfigSchemaRegistry::class);
        assert($registry instanceof ConfigSchemaRegistry);
        $registry->freeze();
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

    private function registerManifestSigningCustody(): void
    {
        $raw = $this->config['config_manifest_signing'] ?? [];
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('CFG-04 manifest signing configuration must be an array.');
        }
        self::assertExactKeys($raw, ['trust_keys', 'revoked_trust_keys', 'signing_key'], 'manifest signing');

        $trustKeys = $raw['trust_keys'] ?? [];
        $revoked = $raw['revoked_trust_keys'] ?? [];
        if (!is_array($trustKeys) || !is_array($revoked) || !array_is_list($revoked)) {
            throw new \InvalidArgumentException('CFG-04 manifest trust configuration is malformed.');
        }
        foreach ($trustKeys as $reference => $entry) {
            if (!is_string($reference) || !is_array($entry)) {
                throw new \InvalidArgumentException('CFG-04 manifest trust configuration is malformed.');
            }
            self::assertExactKeys($entry, ['algorithm', 'public_key'], 'manifest trust entry');
        }
        foreach ($revoked as $reference) {
            if (!is_string($reference)) {
                throw new \InvalidArgumentException('CFG-04 revoked trust references must be strings.');
            }
        }

        /** @var array<string, array{algorithm: string, public_key: string}> $trustKeys */
        /** @var list<string> $revoked */
        $trustPolicy = new ConfigManifestTrustPolicy($trustKeys, $revoked);
        $this->singleton(ConfigManifestTrustPolicy::class, static fn(): ConfigManifestTrustPolicy => $trustPolicy);
        $this->singleton(
            ConfigManifestEd25519Verifier::class,
            fn(): ConfigManifestEd25519Verifier => new ConfigManifestEd25519Verifier(
                $this->resolve(ConfigManifestTrustPolicy::class),
            ),
        );
        $this->singleton(
            ConfigManifestSignatureVerifierInterface::class,
            fn(): ConfigManifestSignatureVerifierInterface => $this->resolve(ConfigManifestEd25519Verifier::class),
        );

        if (!array_key_exists('signing_key', $raw)) {
            return;
        }
        $signingKey = $raw['signing_key'];
        if (!is_array($signingKey)) {
            throw new \InvalidArgumentException('CFG-04 manifest signing-key configuration is malformed.');
        }
        self::assertExactKeys($signingKey, ['trust_key_reference', 'secret_reference'], 'manifest signing key');
        $trustKeyReference = $signingKey['trust_key_reference'] ?? null;
        $secretReference = $signingKey['secret_reference'] ?? null;
        if (!is_string($trustKeyReference) || !is_array($secretReference)) {
            throw new \InvalidArgumentException('CFG-04 manifest signing-key configuration is malformed.');
        }
        self::assertExactKeys(
            $secretReference,
            ['provider', 'identifier', 'secret_class', 'purpose'],
            'manifest signing secret reference',
        );
        $provider = $secretReference['provider'] ?? null;
        $identifier = $secretReference['identifier'] ?? null;
        $secretClass = $secretReference['secret_class'] ?? null;
        $purpose = $secretReference['purpose'] ?? null;
        $trustedPublicKey = $trustPolicy->trustedPublicKey(
            $trustKeyReference,
            \Waaseyaa\Config\Manifest\SignedConfigManifestEnvelope::ALGORITHM_V1,
        );
        if (!is_string($provider) || !is_string($identifier) || !is_string($secretClass) || !is_string($purpose)
            || $secretClass !== SecretClass::TokenSigningPrivateKey->value
            || $purpose !== Ed25519ManifestSigningOperation::PURPOSE
            || $trustedPublicKey === null) {
            throw new \InvalidArgumentException('CFG-04 manifest signing-key reference is invalid or untrusted.');
        }

        $registry = $this->kernelServices?->get(SecretResolverRegistry::class);
        if (!$registry instanceof SecretResolverRegistry) {
            throw new ConfigurationAuthorityUnavailableException(
                'CFG-04 manifest signing requires the kernel secret resolver registry.',
            );
        }
        $reference = SecretReference::create(
            $provider,
            $identifier,
            SecretClass::TokenSigningPrivateKey,
            Ed25519ManifestSigningOperation::PURPOSE,
        );
        $registry->registerConsumer(ConfigManifestEd25519Signer::PACKAGE, Ed25519ManifestSigningOperation::class);
        $registry->allow(
            $provider,
            ConfigManifestEd25519Signer::PACKAGE,
            SecretClass::TokenSigningPrivateKey,
            Ed25519ManifestSigningOperation::PURPOSE,
            [$registry->environment()],
        );
        $this->singleton(
            ConfigManifestEd25519Signer::class,
            static fn(): ConfigManifestEd25519Signer => new ConfigManifestEd25519Signer(
                $registry,
                $reference,
                $trustKeyReference,
                $trustedPublicKey,
            ),
        );
        $this->singleton(
            ConfigManifestSignerInterface::class,
            fn(): ConfigManifestSignerInterface => $this->resolve(ConfigManifestEd25519Signer::class),
        );
    }

    /** @param array<mixed> $value @param list<string> $allowed */
    private static function assertExactKeys(array $value, array $allowed, string $label): void
    {
        $keys = array_keys($value);
        foreach ($keys as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException(sprintf('CFG-04 %s contains an unknown field.', $label));
            }
        }
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
        $context->requireActiveGenerationId();

        return new ReadOnlyActiveConfigurationStorage($storage);
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

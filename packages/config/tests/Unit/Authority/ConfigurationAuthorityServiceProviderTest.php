<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Authority;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Authority\ActiveConfigurationBridgeInterface;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Authority\ConfigurationAuthorityServiceProvider;
use Waaseyaa\Config\Authority\ConfigurationAuthorityUnavailableException;
use Waaseyaa\Config\Cache\CachedConfigFactory;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigManagerInterface;
use Waaseyaa\Config\Event\ConfigurationSelectorDeprecationEvent;
use Waaseyaa\Config\Manifest\ConfigReplayStateReaderInterface;
use Waaseyaa\Config\Manifest\UnsignedConfigPolicy;
use Waaseyaa\Config\Schema\ConfigPackageCompatibility;
use Waaseyaa\Config\Schema\ConfigSchemaRegistry;
use Waaseyaa\Config\Schema\ConfigSchemaValidator;
use Waaseyaa\Config\Storage\MemoryStorage;
use Waaseyaa\Config\Sync\ConfigImportApplyHookInterface;
use Waaseyaa\Config\Sync\ConfigImportPreflightInterface;
use Waaseyaa\Config\Sync\ConfigSyncBundleValidator;
use Waaseyaa\Config\Sync\RefusingConfigImportPreflight;
use Waaseyaa\Config\Sync\SignedEnvelopeConfigImportPreflight;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncFileSourceInterface;
use Waaseyaa\Database\DatabaseIdentityProviderInterface;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

final class ConfigurationAuthorityServiceProviderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/waaseyaa_cfg_authority_provider_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->root);
    }

    /**
     * CFG-03 (#2430): compatibility is the authority that decides whether
     * authored content may be staged. It is composed here, in the sole
     * production composition root, from discovered package declarations —
     * never recomputed from the authored bundle itself, which would let a
     * bundle authorize its own contract.
     */
    #[Test]
    public function itComposesPackageCompatibilityFromDiscoveredContracts(): void
    {
        $provider = $this->providerWithManifest(new PackageManifest(
            configContracts: [
                'waaseyaa/config' => [
                    'schema-provider' => 'Waaseyaa\\Config\\Authority\\ConfigurationAuthorityServiceProvider',
                    'version' => 1,
                    'readable_versions' => [1],
                ],
            ],
        ));

        $compatibility = $provider->resolve(ConfigPackageCompatibility::class);

        self::assertInstanceOf(ConfigPackageCompatibility::class, $compatibility);
        $compatibility->assertWritableCohort(['waaseyaa/config' => 1]);

        $this->expectException(\RuntimeException::class);
        $compatibility->assertWritableCohort(['waaseyaa/config' => 2]);
    }

    /**
     * An undeclared package must not be staged. Absence is a refusal, not an
     * implicit allow.
     */
    #[Test]
    public function compatibilityRefusesAPackageThatDeclaresNoContract(): void
    {
        $provider = $this->providerWithManifest(new PackageManifest());

        $compatibility = $provider->resolve(ConfigPackageCompatibility::class);
        assert($compatibility instanceof ConfigPackageCompatibility);

        $this->expectException(\RuntimeException::class);
        $compatibility->assertWritableCohort(['waaseyaa/config' => 1]);
    }

    /**
     * Without a package manifest there is no basis for a compatibility verdict,
     * so the authority is unavailable rather than empty-and-permissive.
     */
    #[Test]
    public function compatibilityIsUnavailableWithoutAPackageManifest(): void
    {
        $provider = $this->providerWithManifest(null);

        $this->expectException(ConfigurationAuthorityUnavailableException::class);
        $provider->resolve(ConfigPackageCompatibility::class);
    }

    /**
     * CFG-03 (#2430): strict bundle validation is part of the verified import
     * chain and had no production binding, so the preflight that needs it could
     * not be constructed. It must share the one frozen schema registry — a
     * second registry would validate against schemas the running site does not
     * actually have.
     */
    #[Test]
    public function itComposesTheStrictBundleValidatorOnTheOneSchemaRegistry(): void
    {
        $provider = $this->providerWithManifest(new PackageManifest());

        $validator = $provider->resolve(ConfigSyncBundleValidator::class);

        self::assertInstanceOf(ConfigSyncBundleValidator::class, $validator);
        self::assertSame($validator, $provider->resolve(ConfigSyncBundleValidator::class));

        $registry = $provider->resolve(ConfigSchemaRegistry::class);
        assert($registry instanceof ConfigSchemaRegistry);
        $registry->freeze();
        $result = $validator->validate($this->root . '/absent-sync');
        self::assertFalse($result->isValid(), 'The validator reports on the real filesystem, not a private copy.');
    }

    /**
     * CFG-03 (#2430): the binding whose absence made config:import refuse in
     * every environment. Publishing it here is what lets the CLI provider find
     * a real gate on the kernel-services bus instead of falling back to
     * RefusingConfigImportPreflight.
     */
    #[Test]
    public function itPublishesTheSignedEnvelopeImportGate(): void
    {
        $provider = $this->providerWithManifest(new PackageManifest(), [
            ConfigReplayStateReaderInterface::class => new class implements ConfigReplayStateReaderInterface {
                public function lastCommittedSequence(string $bundleScope, string $trustKeyReference): ?int
                {
                    return null;
                }
            },
        ]);

        self::assertInstanceOf(
            SignedEnvelopeConfigImportPreflight::class,
            $provider->resolve(ConfigImportPreflightInterface::class),
        );
    }

    /**
     * Without replay state a committed bundle could be reinstated silently. A
     * gate missing one of its checks is not a weaker gate, it is a different
     * one, so the composition refuses instead of verifying partially.
     */
    #[Test]
    public function theImportGateRefusesWhenReplayStateIsUnavailable(): void
    {
        $provider = $this->providerWithManifest(new PackageManifest());

        self::assertInstanceOf(
            RefusingConfigImportPreflight::class,
            $provider->resolve(ConfigImportPreflightInterface::class),
        );
    }

    /** @param array<string, object> $extraServices */
    private function providerWithManifest(?PackageManifest $manifest, array $extraServices = []): ConfigurationAuthorityServiceProvider
    {
        $services = [
            DatabaseIdentityProviderInterface::class => new TestDatabaseIdentityProvider(),
            ActiveConfigurationBridgeInterface::class => new TestActiveConfigurationBridge(),
            \Waaseyaa\Foundation\Event\EventDispatcherInterface::class => new \Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter(),
        ];
        if ($manifest instanceof PackageManifest) {
            $services[PackageManifest::class] = $manifest;
        }
        $services = [...$services, ...$extraServices];

        $provider = new ConfigurationAuthorityServiceProvider();
        $provider->setKernelContext($this->root, ['environment' => 'testing'], []);
        $provider->setKernelServices(new TestKernelServices($services));
        $provider->register();

        return $provider;
    }

    #[Test]
    public function itPublishesOneContextAndComposesLegacyAdaptersFromOneBridge(): void
    {
        $bridge = new TestActiveConfigurationBridge();
        $provider = new ConfigurationAuthorityServiceProvider();
        $provider->setKernelContext($this->root, ['environment' => 'testing'], []);
        $provider->setKernelServices(new TestKernelServices([
            DatabaseIdentityProviderInterface::class => new TestDatabaseIdentityProvider(),
            ActiveConfigurationBridgeInterface::class => $bridge,
            \Waaseyaa\Foundation\Event\EventDispatcherInterface::class => new \Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter(),
        ]));
        $provider->register();

        $first = $provider->resolve(ConfigurationAuthorityContext::class);
        $second = $provider->resolve(ConfigurationAuthorityContext::class);
        self::assertSame($first, $second);
        self::assertSame('database:v1:test', $first->databaseIdentity);
        self::assertSame(str_replace('\\', '/', $this->root) . '/storage/config-sync', $first->syncPath);
        $bridge->bindContext($first);

        $factory = $provider->resolve(ConfigFactoryInterface::class);
        self::assertInstanceOf(CachedConfigFactory::class, $factory);
        $manager = $provider->resolve(ConfigManagerInterface::class);
        self::assertInstanceOf(ConfigSchemaValidator::class, $provider->resolve(ConfigSchemaValidator::class));
        $unsignedPolicy = $provider->resolve(UnsignedConfigPolicy::class);
        self::assertInstanceOf(UnsignedConfigPolicy::class, $unsignedPolicy);
        self::assertFalse($unsignedPolicy->allowsUnsigned);
        self::assertSame($bridge->activeStorage(), $manager->getActiveStorage());

        $factory->getEditable('system.site')->set('name', 'Waaseyaa')->save();
        self::assertSame('Waaseyaa', $factory->get('system.site')->get('name'));
    }

    #[Test]
    public function activeConsumersFailClosedWhenTheHigherLayerBridgeIsMissing(): void
    {
        $provider = new ConfigurationAuthorityServiceProvider();
        $provider->setKernelContext($this->root, [], []);
        $provider->setKernelServices(new TestKernelServices([
            DatabaseIdentityProviderInterface::class => new TestDatabaseIdentityProvider(),
        ]));
        $provider->register();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('configuration.authority.v1 active-store bridge is unavailable');
        $provider->resolve(ConfigFactoryInterface::class);
    }

    #[Test]
    public function productionCapabilityPublicationRefusesAMissingActiveGeneration(): void
    {
        $provider = new ConfigurationAuthorityServiceProvider();
        $provider->setKernelContext($this->root, ['environment' => 'production'], []);
        $provider->setKernelServices(new TestKernelServices([
            DatabaseIdentityProviderInterface::class => new TestDatabaseIdentityProvider(),
        ]));
        $provider->register();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Active configuration generation is unavailable');
        iterator_to_array($provider->capabilityDeclarations());
    }

    #[Test]
    public function missingProfileCannotInheritProcessDevelopmentForCapabilityPublication(): void
    {
        $previous = getenv('APP_ENV');
        putenv('APP_ENV=local');

        try {
            $provider = new ConfigurationAuthorityServiceProvider();
            $provider->setKernelContext($this->root, [], []);
            $provider->setKernelServices(new TestKernelServices([
                DatabaseIdentityProviderInterface::class => new TestDatabaseIdentityProvider(),
            ]));
            $provider->register();

            iterator_to_array($provider->capabilityDeclarations());
            self::fail('A missing explicit profile must require an active generation.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Active configuration generation is unavailable', $e->getMessage());
        } finally {
            $previous === false
                ? putenv('APP_ENV')
                : putenv('APP_ENV=' . $previous);
        }
    }

    #[Test]
    public function explicitTestingProfileMayPublishBeforePersistentActivation(): void
    {
        $provider = new ConfigurationAuthorityServiceProvider();
        $provider->setKernelContext($this->root, ['environment' => 'testing'], []);
        $provider->setKernelServices(new TestKernelServices([
            DatabaseIdentityProviderInterface::class => new TestDatabaseIdentityProvider(),
        ]));
        $provider->register();

        $declarations = iterator_to_array($provider->capabilityDeclarations());

        self::assertCount(1, $declarations);
        self::assertSame('configuration.authority.v1', $declarations[0]->id);
    }

    #[Test]
    public function bootRegistersShippedSchemasAndFinalizationFreezesAuthority(): void
    {
        $provider = new ConfigurationAuthorityServiceProvider();
        $provider->setKernelContext($this->root, ['environment' => 'testing'], []);
        $provider->register();

        $provider->boot();
        $registry = $provider->resolve(ConfigSchemaRegistry::class);
        self::assertInstanceOf(ConfigSchemaRegistry::class, $registry);
        self::assertNotNull($registry->get('ai.mcp_servers', 1));
        self::assertNotNull($registry->get('ai.mcp_servers', 2));
        self::assertNotNull($registry->get('config.ai.providers', 1));
        self::assertNotNull($registry->get('config.ai.providers', 2));
        self::assertFalse($registry->isFrozen());

        $provider->finalizeProviderBoot();
        self::assertTrue($registry->isFrozen());
    }

    #[Test]
    public function equivalentLegacySelectorEmitsTypedDeprecationEvidenceOnce(): void
    {
        $events = [];
        $dispatcher = new \Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter();
        $dispatcher->addListener(
            ConfigurationSelectorDeprecationEvent::class,
            static function (ConfigurationSelectorDeprecationEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );
        $provider = new ConfigurationAuthorityServiceProvider();
        $provider->setKernelContext($this->root, [
            'environment' => 'testing',
            'config' => ['sync_path' => 'storage/config-sync'],
            'config_dir' => 'storage/./config-sync',
        ], []);
        $provider->setKernelServices(new TestKernelServices([
            DatabaseIdentityProviderInterface::class => new TestDatabaseIdentityProvider(),
            \Waaseyaa\Foundation\Event\EventDispatcherInterface::class => $dispatcher,
        ]));
        $provider->register();

        $first = $provider->resolve(ConfigurationAuthorityContext::class);
        self::assertSame($first, $provider->resolve(ConfigurationAuthorityContext::class));
        self::assertCount(1, $events);
        self::assertSame('config_dir', $events[0]->legacySelector);
        self::assertSame('config.sync_path', $events[0]->canonicalSelector);
        self::assertSame($first->authorityId, $events[0]->authorityId);
    }

    #[Test]
    public function itRejectsABridgeWithADifferentAuthorityContext(): void
    {
        $bridge = new TestActiveConfigurationBridge();
        $provider = new ConfigurationAuthorityServiceProvider();
        $provider->setKernelContext($this->root, [], []);
        $provider->setKernelServices(new TestKernelServices([
            DatabaseIdentityProviderInterface::class => new TestDatabaseIdentityProvider(),
            ActiveConfigurationBridgeInterface::class => $bridge,
        ]));
        $provider->register();
        $context = $provider->resolve(ConfigurationAuthorityContext::class);
        assert($context instanceof ConfigurationAuthorityContext);
        $bridge->bindContext(new ConfigurationAuthorityContext(
            authorityId: str_repeat('a', 64),
            databaseIdentity: $context->databaseIdentity,
            syncPath: $context->syncPath,
            selectorProvenance: $context->selectorProvenance,
        ));

        $this->expectExceptionMessage('divergent authority context');
        $provider->resolve(ConfigFactoryInterface::class);
    }
}

final class TestDatabaseIdentityProvider implements DatabaseIdentityProviderInterface
{
    public function databaseIdentity(): string
    {
        return 'database:v1:test';
    }
}

final class TestKernelServices implements KernelServicesInterface
{
    /** @param array<class-string, object> $services */
    public function __construct(private readonly array $services) {}

    public function get(string $abstract): ?object
    {
        return $this->services[$abstract] ?? null;
    }
}

final class TestActiveConfigurationBridge implements ActiveConfigurationBridgeInterface, ConfigSyncFileSourceInterface, ConfigImportApplyHookInterface
{
    private MemoryStorage $storage;
    private ?ConfigurationAuthorityContext $context = null;

    public function __construct()
    {
        $this->storage = new MemoryStorage();
    }

    public function authorityContext(): ConfigurationAuthorityContext
    {
        return $this->context ?? throw new \LogicException('Test bridge context was not bound.');
    }

    public function bindContext(ConfigurationAuthorityContext $context): void
    {
        $this->context = $context;
    }

    public function activeStorage(): MemoryStorage
    {
        return $this->storage;
    }

    public function iterate(): iterable
    {
        return [];
    }
    public function apply(ConfigSyncFile $file): string
    {
        return 'unchanged';
    }
    public function delete(string $ref): void {}
}

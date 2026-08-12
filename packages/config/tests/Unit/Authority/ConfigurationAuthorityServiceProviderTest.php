<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Authority;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Authority\ActiveConfigurationBridgeInterface;
use Waaseyaa\Config\Authority\ConfigurationAuthorityContext;
use Waaseyaa\Config\Authority\ConfigurationAuthorityServiceProvider;
use Waaseyaa\Config\ConfigFactoryInterface;
use Waaseyaa\Config\ConfigManagerInterface;
use Waaseyaa\Config\Schema\ConfigSchemaValidator;
use Waaseyaa\Config\Storage\MemoryStorage;
use Waaseyaa\Config\Sync\ConfigImportApplyHookInterface;
use Waaseyaa\Config\Sync\ConfigSyncFile;
use Waaseyaa\Config\Sync\ConfigSyncFileSourceInterface;
use Waaseyaa\Database\DatabaseIdentityProviderInterface;
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

    #[Test]
    public function itPublishesOneContextAndComposesLegacyAdaptersFromOneBridge(): void
    {
        $bridge = new TestActiveConfigurationBridge();
        $provider = new ConfigurationAuthorityServiceProvider();
        $provider->setKernelContext($this->root, [], []);
        $provider->setKernelServices(new TestKernelServices([
            DatabaseIdentityProviderInterface::class => new TestDatabaseIdentityProvider(),
            ActiveConfigurationBridgeInterface::class => $bridge,
        ]));
        $provider->register();

        $first = $provider->resolve(ConfigurationAuthorityContext::class);
        $second = $provider->resolve(ConfigurationAuthorityContext::class);
        self::assertSame($first, $second);
        self::assertSame('database:v1:test', $first->databaseIdentity);
        self::assertSame(str_replace('\\', '/', $this->root) . '/storage/config-sync', $first->syncPath);
        $bridge->bindContext($first);

        $factory = $provider->resolve(ConfigFactoryInterface::class);
        $manager = $provider->resolve(ConfigManagerInterface::class);
        self::assertInstanceOf(ConfigSchemaValidator::class, $provider->resolve(ConfigSchemaValidator::class));
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

<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Field\FieldSchemaAuthority;
use Waaseyaa\Field\FieldServiceProvider;
use Waaseyaa\Field\FieldTypeManager;
use Waaseyaa\Field\FieldTypeManagerInterface;
use Waaseyaa\Field\Tests\Fixtures\ExtensionFieldTypeFixture;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

/**
 * Production-mirroring wiring (#2786 B1): the kernel owns the one boot-scoped
 * field-type registry and hands it to providers through the kernel-services
 * bus. FieldServiceProvider adopts that exact instance for every binding it
 * publishes, so a downstream plugin admitted from the package manifest is
 * visible to every container consumer.
 */
#[CoversClass(FieldServiceProvider::class)]
final class FieldServiceProviderFieldTypeWiringTest extends TestCase
{
    #[Test]
    public function provider_adopts_the_kernel_owned_registry_from_the_bus(): void
    {
        $kernelOwned = FieldTypeManager::fromManifest([
            'fixture_bus' => ExtensionFieldTypeFixture::declare('fixture_bus'),
        ]);

        $provider = new FieldServiceProvider();
        $provider->setKernelServices($this->bus([
            FieldTypeManagerInterface::class => $kernelOwned,
            FieldTypeManager::class => $kernelOwned,
        ]));
        $provider->register();

        self::assertSame($kernelOwned, $provider->resolve(FieldTypeManagerInterface::class));
        self::assertSame($kernelOwned, $provider->resolve(FieldTypeManager::class));

        $registry = $provider->resolve(FieldDefinitionRegistryInterface::class);
        self::assertInstanceOf(FieldDefinitionRegistry::class, $registry);
        self::assertSame($kernelOwned, $registry->fieldTypeManager());

        $authority = $provider->resolve(FieldSchemaAuthority::class);
        self::assertInstanceOf(FieldSchemaAuthority::class, $authority);
        $schema = $authority->fieldSchema(new FieldDefinition(name: 'price', type: 'fixture_bus'));
        self::assertSame('fixture_bus', $schema['x-field-type']);
        self::assertSame('string', $schema['type']);
    }

    #[Test]
    public function provider_without_a_kernel_builds_an_isolated_built_in_registry(): void
    {
        $provider = new FieldServiceProvider();
        $provider->register();

        $manager = $provider->resolve(FieldTypeManagerInterface::class);
        self::assertInstanceOf(FieldTypeManager::class, $manager);
        self::assertTrue($manager->hasDefinition('string'));
        self::assertSame($manager, $provider->resolve(FieldTypeManager::class));

        $registry = $provider->resolve(FieldDefinitionRegistryInterface::class);
        self::assertInstanceOf(FieldDefinitionRegistry::class, $registry);
        self::assertSame($manager, $registry->fieldTypeManager());
    }

    /** @param array<string, object> $services */
    private function bus(array $services): KernelServicesInterface
    {
        return new class ($services) implements KernelServicesInterface {
            /** @param array<string, object> $services */
            public function __construct(private readonly array $services) {}

            public function get(string $abstract): ?object
            {
                return $this->services[$abstract] ?? null;
            }
        };
    }
}

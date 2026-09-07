<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Field\BundleTemplateCompiler;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldDefinitionRegistry;
use Waaseyaa\Field\FieldSchemaAuthority;
use Waaseyaa\Field\FieldServiceProvider;
use Waaseyaa\Field\FieldTypeManager;
use Waaseyaa\Field\FieldTypeManagerInterface;
use Waaseyaa\Field\Tests\Fixtures\ExtensionFieldTypeFixture;
use Waaseyaa\Field\Tests\Fixtures\Templates\SampleArticleTemplate;
use Waaseyaa\Foundation\Kernel\Http\HttpKernelServiceResolver;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;

/**
 * Production-mirroring wiring (#2786 B1): the kernel owns the one boot-scoped
 * field-type registry and hands it to providers through the kernel-services
 * bus. FieldServiceProvider adopts that exact instance for every binding it
 * publishes, so a downstream plugin admitted from the package manifest is
 * visible to every container consumer.
 */
#[CoversClass(FieldServiceProvider::class)]
#[UsesClass(BundleTemplateCompiler::class)]
#[UsesClass(HttpKernelServiceResolver::class)]
final class FieldServiceProviderFieldTypeWiringTest extends TestCase
{
    #[Test]
    public function provider_adopts_the_kernel_owned_registry_from_the_bus(): void
    {
        $kernelOwned = FieldTypeManager::fromManifest([
            'fixture_bus' => ExtensionFieldTypeFixture::declare('fixture_bus'),
        ]);

        $canonical = new FieldDefinitionRegistry($kernelOwned);
        $services = $this->bus([
            FieldDefinitionRegistryInterface::class => $canonical,
            FieldTypeManagerInterface::class => $kernelOwned,
            FieldTypeManager::class => $kernelOwned,
        ]);
        $provider = new FieldServiceProvider();
        $provider->setKernelServices($services);
        $provider->register();

        self::assertSame($kernelOwned, $provider->resolve(FieldTypeManagerInterface::class));
        self::assertSame($kernelOwned, $provider->resolve(FieldTypeManager::class));
        self::assertSame($canonical, $provider->resolve(FieldDefinitionRegistryInterface::class));
        self::assertSame($canonical, $provider->resolve(FieldDefinitionRegistry::class));

        $compiler = $provider->resolve(BundleTemplateCompiler::class);
        $compiler->compile([SampleArticleTemplate::class]);
        self::assertSame(['title', 'body', 'tags'], array_keys($canonical->bundleFieldsFor('node', 'article')));

        $httpResolver = new HttpKernelServiceResolver(
            providersAccessor: static fn(): array => [$provider],
            kernelServices: $services,
            logger: new NullLogger(),
        );
        self::assertSame($canonical, $httpResolver->resolve(FieldDefinitionRegistryInterface::class));

        $authority = $provider->resolve(FieldSchemaAuthority::class);
        self::assertInstanceOf(FieldSchemaAuthority::class, $authority);
        $schema = $authority->fieldSchema(new FieldDefinition(name: 'price', type: 'fixture_bus'));
        self::assertSame('fixture_bus', $schema['x-field-type']);
        self::assertSame('string', $schema['type']);
    }

    #[Test]
    public function provider_refuses_a_wrong_typed_kernel_field_registry(): void
    {
        $provider = new FieldServiceProvider();
        $provider->setKernelServices($this->bus([
            FieldDefinitionRegistryInterface::class => new \stdClass(),
        ]));
        $provider->register();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Kernel service for Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface must implement that interface; stdClass given.');
        $provider->resolve(FieldDefinitionRegistryInterface::class);
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

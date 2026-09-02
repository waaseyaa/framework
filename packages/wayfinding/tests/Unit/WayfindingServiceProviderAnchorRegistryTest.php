<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Field\FieldSchemaAuthority;
use Waaseyaa\Field\FieldTypeManager;
use Waaseyaa\Field\Tests\Fixtures\ExtensionFieldTypeFixture;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Wayfinding\Anchor\AnchorRegistry;
use Waaseyaa\Wayfinding\Tests\Support\InMemoryEntityTypeManager;
use Waaseyaa\Wayfinding\Tests\Support\WidgetEntity;
use Waaseyaa\Wayfinding\WayfindingServiceProvider;

/**
 * The anchor catalog derives field anchors through the kernel's field schema
 * authority (#2786 B1), so a downstream plugin admitted at boot yields anchors
 * instead of aborting the catalog with an unknown-type refusal.
 */
#[CoversClass(WayfindingServiceProvider::class)]
final class WayfindingServiceProviderAnchorRegistryTest extends TestCase
{
    #[Test]
    public function anchor_registry_catalogs_registry_admitted_extension_types(): void
    {
        $fieldTypes = FieldTypeManager::fromManifest([
            'anchor_money' => ExtensionFieldTypeFixture::declare('anchor_money'),
        ]);

        $provider = new WayfindingServiceProvider();
        $provider->setKernelServices($this->bus([
            EntityTypeManager::class => $this->entityTypeManager(),
            FieldSchemaAuthority::class => new FieldSchemaAuthority($fieldTypes),
        ]));
        $provider->register();

        $registry = $provider->resolve(AnchorRegistry::class);
        self::assertInstanceOf(AnchorRegistry::class, $registry);
        self::assertContains('field:widget:price', $registry->anchorIds());
    }

    #[Test]
    public function anchor_registry_refuses_to_compose_without_the_field_schema_authority(): void
    {
        $provider = new WayfindingServiceProvider();
        $provider->setKernelServices($this->bus([
            EntityTypeManager::class => $this->entityTypeManager(),
        ]));
        $provider->register();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('field schema authority');
        $provider->resolve(AnchorRegistry::class);
    }

    private function entityTypeManager(): EntityTypeManagerInterface
    {
        $widget = new EntityType(
            id: 'widget',
            label: 'Widget',
            class: WidgetEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            translatable: false,
            revisionable: false,
        );

        return new InMemoryEntityTypeManager(
            ['widget' => $widget],
            ['widget' => ['price' => ['type' => 'anchor_money', 'label' => 'Price']]],
        );
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

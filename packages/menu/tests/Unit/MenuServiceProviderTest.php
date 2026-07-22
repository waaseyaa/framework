<?php

declare(strict_types=1);

namespace Waaseyaa\Menu\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Menu\Menu;
use Waaseyaa\Menu\MenuLink;
use Waaseyaa\Menu\MenuServiceProvider;

#[CoversClass(MenuServiceProvider::class)]
final class MenuServiceProviderTest extends TestCase
{
    #[Test]
    public function registers_menu_and_menu_link(): void
    {
        $provider = new MenuServiceProvider();
        $provider->register();

        $entityTypes = $provider->getEntityTypes();

        $this->assertCount(2, $entityTypes);
        $this->assertSame('menu', $entityTypes[0]->id());
        $this->assertSame(Menu::class, $entityTypes[0]->getClass());
        $this->assertSame('menu_link', $entityTypes[1]->id());
        $this->assertSame(MenuLink::class, $entityTypes[1]->getClass());
        $this->assertSame('menu', $entityTypes[1]->getBundleEntityType());
        $definitions = $entityTypes[1]->getFieldDefinitions();
        $this->assertSame(
            ['title', 'url', 'target_entity_type', 'target_entity_id', 'menu_name', 'target', 'parent_id', 'weight', 'enabled', 'expanded'],
            array_keys($definitions),
        );
        $this->assertSame('string', $definitions['target_entity_type']->getType());
        $this->assertSame('string', $definitions['target_entity_id']->getType());
        $this->assertSame(FieldReadLevel::Public, $definitions['target_entity_type']->getReadLevel());
        $this->assertSame(FieldReadLevel::Public, $definitions['target_entity_id']->getReadLevel());
    }

    #[Test]
    public function menu_link_schema_defaults_enabled_to_true(): void
    {
        $provider = new MenuServiceProvider();
        $provider->register();

        $definitions = $provider->getEntityTypes()[1]->getFieldDefinitions();

        self::assertTrue($definitions['enabled']->getDefaultValue());
    }
}

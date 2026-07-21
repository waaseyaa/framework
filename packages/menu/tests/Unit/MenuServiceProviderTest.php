<?php

declare(strict_types=1);

namespace Waaseyaa\Menu\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
        $this->assertSame(
            ['title', 'url', 'menu_name', 'target', 'parent_id', 'weight', 'enabled', 'expanded'],
            array_keys($entityTypes[1]->getFieldDefinitions()),
        );
    }
}

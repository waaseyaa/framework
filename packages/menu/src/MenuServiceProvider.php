<?php

declare(strict_types=1);

namespace Waaseyaa\Menu;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class MenuServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'menu',
            label: 'Menu',
            description: 'Navigation menus for site structure',
            class: Menu::class,
            keys: ['id' => 'id', 'label' => 'label'],
            group: 'structure',
        ));

        $this->entityType(new EntityType(
            id: 'menu_link',
            label: 'Menu Link',
            description: 'Individual links within navigation menus',
            class: MenuLink::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'menu_name'],
            group: 'structure',
        ));
    }
}

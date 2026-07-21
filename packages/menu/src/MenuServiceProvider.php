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
            api: true,
        ));

        $this->entityType(EntityType::fromClass(
            MenuLink::class,
            bundleEntityType: 'menu',
            group: 'structure',
        ));
    }
}

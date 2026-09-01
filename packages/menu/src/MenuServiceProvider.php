<?php

declare(strict_types=1);

namespace Waaseyaa\Menu;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\FieldReadLevel;
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
            // #2755: 'menu' is a configuration entity (ConfigEntityBase) and has
            // no field-attribute metadata, so an undeclared field defaults to
            // FieldReadLevel::Internal once the entity type is registered —
            // Menu::isLocked() (which reads through get()) would throw
            // FieldReadDenied for every caller with no read policy registered,
            // masking the invariant it exists to enforce. Declare it Public,
            // mirroring node_type's explicit _fieldDefinitions for the same
            // ConfigEntityBase shape.
            _fieldDefinitions: [
                'locked' => ['type' => 'boolean', 'read' => FieldReadLevel::Public],
            ],
        ));

        $this->entityType(EntityType::fromClass(
            MenuLink::class,
            bundleEntityType: 'menu',
            group: 'structure',
        ));
    }
}

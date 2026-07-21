<?php

declare(strict_types=1);

namespace Waaseyaa\Groups;

use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Groups\Membership\GroupMembershipService;
use Waaseyaa\Groups\StaffDirectory\StaffDirectoryReadDeclaration;
use Waaseyaa\Groups\StaffDirectory\StaffDirectoryReader;
use Waaseyaa\Groups\StaffDirectory\StaffDirectoryReaderInterface;

/**
 * Registers the `group` content entity type and its `group_type` bundle
 * config entity.
 *
 * Ships with zero pre-registered bundles. Products declare their own
 * GroupType config entities and register bundle-scoped fields via
 * EntityTypeManager::addBundleFields().
 */
final class GroupsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(EntityType::fromClass(
            Group::class,
            bundleEntityType: 'group_type',
            group: 'groups',
            discoverable: true,
        ));

        // GroupType is a config entity (extends ConfigEntityBase). Attribute
        // reflection only applies to ContentEntityBase subclasses, so the
        // config entity registration stays explicit per AD-3 in the plan.
        $this->entityType(new EntityType(
            id: 'group_type',
            label: 'Group type',
            description: 'Declares a Group bundle.',
            class: GroupType::class,
            keys: [
                'id' => 'id',
                'label' => 'label',
            ],
            group: 'groups',
            discoverable: true,
            api: true,
            _fieldDefinitions: [
                'description' => new FieldDefinition(
                    name: 'description',
                    type: 'text',
                    targetEntityTypeId: 'group_type',
                    label: 'Description',
                    description: 'Human-readable description of this group type.',
                    settings: ['weight' => 5],
                    read: \Waaseyaa\Entity\FieldReadLevel::Public,
                ),
            ],
        ));

        $this->singleton(GroupMembershipService::class, function (): GroupMembershipService {
            /** @var EntityTypeManager $manager */
            $manager = $this->resolve(EntityTypeManager::class);

            return new GroupMembershipService($manager);
        });

        // The host supplies StaffDirectoryReadDeclaration. Resolution remains
        // lazy so products without a staff roster keep the feature dormant,
        // while configured products receive the final discovered handler.
        $this->singleton(StaffDirectoryReaderInterface::class, function (): StaffDirectoryReader {
            $manager = $this->resolve(EntityTypeManager::class);
            $handler = $this->resolve(EntityAccessHandler::class);
            $declaration = $this->resolve(StaffDirectoryReadDeclaration::class);
            if (!$manager instanceof EntityTypeManager
                || !$handler instanceof EntityAccessHandler
                || !$declaration instanceof StaffDirectoryReadDeclaration
            ) {
                throw new \LogicException('Staff directory service dependencies have invalid types.');
            }

            return new StaffDirectoryReader($manager, $handler, $declaration);
        });
    }
}

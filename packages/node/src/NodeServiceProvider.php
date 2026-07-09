<?php

declare(strict_types=1);

namespace Waaseyaa\Node;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class NodeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(EntityType::fromClass(
            Node::class,
            group: 'content',
            bundleEntityType: 'node_type',
            revisionable: true,
            revisionDefault: true,
        ));

        // node_type is a configuration entity (ConfigEntityBase) and has no
        // field-attribute metadata; keep the explicit EntityType registration.
        $this->entityType(new EntityType(
            id: 'node_type',
            label: 'Content Type',
            description: 'Content type definitions and field configuration',
            class: NodeType::class,
            keys: ['id' => 'type', 'label' => 'name'],
            group: 'content',
        ));
    }
}

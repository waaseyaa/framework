<?php

declare(strict_types=1);

namespace Waaseyaa\Node;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Node\Listener\NodeRevisionDefaultListener;

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
            api: true,
        ));
    }

    /**
     * Wires {@see NodeRevisionDefaultListener} onto `EntityEvents::PRE_SAVE`
     * so the per-bundle `NodeType::isNewRevision()` knob (CW-v1 WP-2 Task
     * 2.3) actually controls whether an ordinary node save creates a new
     * revision.
     *
     * The kernel-services bus serves the event dispatcher ONLY under the
     * Symfony-contracts FQCN (`ProviderRegistryKernelServices::get()`);
     * resolving the foundation FQCN returns null and would silently skip
     * registration — same gotcha `RelationshipServiceProvider::boot()` fixed
     * for the delete guard (#1852). Resolve the served key, then type-check
     * against the foundation contract.
     */
    public function boot(): void
    {
        $dispatcher = $this->resolveOptional(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
        if (!$dispatcher instanceof EventDispatcherInterface) {
            return;
        }

        $entityTypeManager = $this->resolveOptional(EntityTypeManager::class);
        if (!$entityTypeManager instanceof EntityTypeManagerInterface) {
            return;
        }

        $dispatcher->addListener(
            EntityEvents::PRE_SAVE->value,
            new NodeRevisionDefaultListener($entityTypeManager),
        );
    }
}

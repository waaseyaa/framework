<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class RelationshipServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'relationship',
            label: 'Relationship',
            description: 'Connections between entities for cross-referencing',
            class: Relationship::class,
            keys: [
                'id' => 'rid',
                'uuid' => 'uuid',
                'label' => 'relationship_type',
                'bundle' => 'relationship_type',
            ],
            group: 'content',
        ));
    }

    public function boot(): void
    {
        // Wire the referential-integrity delete guard: deleting an entity that
        // is still referenced as a relationship endpoint must fail loudly, not
        // silently orphan edge rows. (Historically this listener existed but
        // was never registered.)
        $dispatcher = $this->resolveOptional(EventDispatcherInterface::class);
        if (!$dispatcher instanceof EventDispatcherInterface) {
            return;
        }

        $entityTypeManager = $this->resolveOptional(EntityTypeManager::class);
        if (!$entityTypeManager instanceof EntityTypeManagerInterface) {
            return;
        }

        $dispatcher->addListener(
            EntityEvents::PRE_DELETE->value,
            new RelationshipDeleteGuardListener($entityTypeManager),
        );
    }
}

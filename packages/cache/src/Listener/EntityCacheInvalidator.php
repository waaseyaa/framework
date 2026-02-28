<?php

declare(strict_types=1);

namespace Aurora\Cache\Listener;

use Aurora\Cache\CacheTagsInvalidatorInterface;
use Aurora\Entity\Event\EntityEvent;

/**
 * Listens for entity save/delete events and invalidates related cache tags.
 *
 * Invalidates both the specific entity tag (entity:{type}:{id}) and the
 * entity type list tag (entity:{type}) to ensure both individual lookups
 * and list queries are properly cache-busted.
 */
final class EntityCacheInvalidator
{
    public function __construct(
        private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    ) {}

    /**
     * Handles entity post-save events.
     */
    public function onPostSave(EntityEvent $event): void
    {
        $this->invalidateEntity($event);
    }

    /**
     * Handles entity post-delete events.
     */
    public function onPostDelete(EntityEvent $event): void
    {
        $this->invalidateEntity($event);
    }

    private function invalidateEntity(EntityEvent $event): void
    {
        $entity = $event->entity;
        $entityTypeId = $entity->getEntityTypeId();
        $entityId = $entity->id();

        $tags = ["entity:{$entityTypeId}"];

        if ($entityId !== null) {
            $tags[] = "entity:{$entityTypeId}:{$entityId}";
        }

        $this->cacheTagsInvalidator->invalidateTags($tags);
    }
}

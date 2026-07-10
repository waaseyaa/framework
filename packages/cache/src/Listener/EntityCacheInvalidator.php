<?php

declare(strict_types=1);

namespace Waaseyaa\Cache\Listener;

use Waaseyaa\Cache\CacheTagsInvalidatorInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\EntityStorage\Event\RevisionPointerMovedEvent;

/**
 * Listens for entity save/delete events and invalidates related cache tags.
 *
 * Invalidates both the specific entity tag (entity:{type}:{id}) and the
 * entity type list tag (entity:{type}) to ensure both individual lookups
 * and list queries are properly cache-busted.
 *
 * CW-v1 WP-2 task 2.5 (#1920): before this, revision POINTER moves
 * (`setPublishedRevision()`, `setCurrentRevision()`, `rollback()`) invalidated
 * NO cache tags at all, so a published/reverted view could keep serving stale
 * cached content. {@see self::onPointerMoved()} and
 * {@see self::onRevisionReverted()} close that gap by reusing the same
 * blunt "this entity id changed" invalidation as save/delete — cache
 * invalidation does not need to distinguish which pointer-move operation
 * fired, only that the entity's cached representation is now stale.
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

    /**
     * Handles the typed post-write pointer-transition event — dispatched by
     * `EntityRepository::setPublishedRevision()` (`publish`) and
     * `setCurrentRevision()` (`revert`), after the pointer move commits.
     */
    public function onPointerMoved(RevisionPointerMovedEvent $event): void
    {
        $this->cacheTagsInvalidator->invalidateTags([
            "entity:{$event->entityTypeId}",
            "entity:{$event->entityTypeId}:{$event->entityId}",
        ]);
    }

    /**
     * Handles the legacy `EntityEvents::REVISION_REVERTED` event — the one
     * signal `EntityRepository::rollback()` shares with the other two pointer
     * paths (it does not dispatch {@see RevisionPointerMovedEvent}, since that
     * event is reserved for pointer moves WITHOUT a new revision).
     */
    public function onRevisionReverted(EntityEvent $event): void
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

<?php

declare(strict_types=1);

namespace Waaseyaa\Cache\Listener;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\EntityStorage\Event\RevisionPointerMovedEvent;

/**
 * Registers EntityCacheInvalidator on entity lifecycle events.
 *
 * Call ::register() during application boot to wire automatic cache
 * invalidation on entity save and delete.
 *
 * Usage:
 *     EntityCacheSubscriber::register($dispatcher, $invalidator);
 */
final class EntityCacheSubscriber
{
    /**
     * Register the invalidator as a listener on entity save, delete, and
     * revision pointer-move events.
     *
     * CW-v1 WP-2 task 2.5 (#1920): {@see RevisionPointerMovedEvent} (dispatched
     * by FQCN post-write from `setPublishedRevision()`/`setCurrentRevision()`)
     * and the legacy `EntityEvents::REVISION_REVERTED` (the signal
     * `rollback()` shares with those two — it never dispatches
     * `RevisionPointerMovedEvent`) are added so pointer moves invalidate cache
     * the same way ordinary saves/deletes already do.
     */
    public static function register(
        EventDispatcherInterface $dispatcher,
        EntityCacheInvalidator $invalidator,
    ): void {
        $dispatcher->addListener(
            EntityEvents::POST_SAVE->value,
            [$invalidator, 'onPostSave'],
        );

        $dispatcher->addListener(
            EntityEvents::POST_DELETE->value,
            [$invalidator, 'onPostDelete'],
        );

        $dispatcher->addListener(
            RevisionPointerMovedEvent::class,
            [$invalidator, 'onPointerMoved'],
        );

        $dispatcher->addListener(
            EntityEvents::REVISION_REVERTED->value,
            [$invalidator, 'onRevisionReverted'],
        );
    }
}

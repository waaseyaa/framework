<?php

declare(strict_types=1);

namespace Waaseyaa\Cache\Listener;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Entity\Event\EntityEvents;

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
     * Register the invalidator as a listener on entity save and delete events.
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
    }
}

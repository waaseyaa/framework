<?php

declare(strict_types=1);

namespace Waaseyaa\Cache\Tests\Unit\Listener;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Cache\CacheTagsInvalidatorInterface;
use Waaseyaa\Cache\Listener\EntityCacheInvalidator;
use Waaseyaa\Cache\Listener\EntityCacheSubscriber;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\EntityStorage\Event\RevisionPointerMovedEvent;

/**
 * Pointer moves invalidated NO cache tags before this wiring (fact stated in
 * CW-v1 WP-2 task 2.5, #1920) — {@see EntityCacheSubscriber::register()} now
 * also subscribes {@see RevisionPointerMovedEvent} and the legacy
 * `EntityEvents::REVISION_REVERTED` (which covers `rollback()`, the one
 * pointer-move path that does not dispatch `RevisionPointerMovedEvent`).
 */
#[CoversClass(EntityCacheSubscriber::class)]
final class EntityCacheSubscriberTest extends TestCase
{
    #[Test]
    public function pointer_moved_event_invalidates_the_right_tags(): void
    {
        $dispatcher = new EventDispatcher();
        $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
        $invalidator->expects($this->once())
            ->method('invalidateTags')
            ->with(['entity:node', 'entity:node:9']);

        EntityCacheSubscriber::register($dispatcher, new EntityCacheInvalidator($invalidator));

        $dispatcher->dispatch(new RevisionPointerMovedEvent(
            entityTypeId: 'node',
            entityId: '9',
            operation: 'publish',
            fromRevisionId: 1,
            toRevisionId: 2,
        ), RevisionPointerMovedEvent::class);
    }

    #[Test]
    public function revision_reverted_event_invalidates_the_right_tags(): void
    {
        $dispatcher = new EventDispatcher();
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('node');
        $entity->method('id')->willReturn(3);

        $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
        $invalidator->expects($this->once())
            ->method('invalidateTags')
            ->with(['entity:node', 'entity:node:3']);

        EntityCacheSubscriber::register($dispatcher, new EntityCacheInvalidator($invalidator));

        $dispatcher->dispatch(new EntityEvent($entity), EntityEvents::REVISION_REVERTED->value);
    }
}

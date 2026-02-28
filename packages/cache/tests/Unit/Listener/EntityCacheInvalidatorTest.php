<?php

declare(strict_types=1);

namespace Aurora\Cache\Tests\Unit\Listener;

use Aurora\Cache\CacheTagsInvalidatorInterface;
use Aurora\Cache\Listener\EntityCacheInvalidator;
use Aurora\Entity\EntityInterface;
use Aurora\Entity\Event\EntityEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityCacheInvalidator::class)]
final class EntityCacheInvalidatorTest extends TestCase
{
    #[Test]
    public function on_post_save_invalidates_entity_tags(): void
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('node');
        $entity->method('id')->willReturn(42);

        $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
        $invalidator->expects($this->once())
            ->method('invalidateTags')
            ->with(['entity:node', 'entity:node:42']);

        $listener = new EntityCacheInvalidator($invalidator);
        $listener->onPostSave(new EntityEvent($entity));
    }

    #[Test]
    public function on_post_delete_invalidates_entity_tags(): void
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('user');
        $entity->method('id')->willReturn(7);

        $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
        $invalidator->expects($this->once())
            ->method('invalidateTags')
            ->with(['entity:user', 'entity:user:7']);

        $listener = new EntityCacheInvalidator($invalidator);
        $listener->onPostDelete(new EntityEvent($entity));
    }

    #[Test]
    public function new_entity_without_id_invalidates_type_tag_only(): void
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('node');
        $entity->method('id')->willReturn(null);

        $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
        $invalidator->expects($this->once())
            ->method('invalidateTags')
            ->with(['entity:node']);

        $listener = new EntityCacheInvalidator($invalidator);
        $listener->onPostSave(new EntityEvent($entity));
    }

    #[Test]
    public function string_entity_id_is_supported(): void
    {
        $entity = $this->createMock(EntityInterface::class);
        $entity->method('getEntityTypeId')->willReturn('taxonomy_term');
        $entity->method('id')->willReturn('abc-123');

        $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
        $invalidator->expects($this->once())
            ->method('invalidateTags')
            ->with(['entity:taxonomy_term', 'entity:taxonomy_term:abc-123']);

        $listener = new EntityCacheInvalidator($invalidator);
        $listener->onPostSave(new EntityEvent($entity));
    }
}

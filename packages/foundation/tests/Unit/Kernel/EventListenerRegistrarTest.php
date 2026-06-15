<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Cache\CacheItem;
use Waaseyaa\Cache\TagAwareCacheInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Kernel\EventListenerRegistrar;

#[CoversClass(EventListenerRegistrar::class)]
final class EventListenerRegistrarTest extends TestCase
{
    #[Test]
    public function discovery_cache_listener_uses_tag_invalidation_when_available(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $registrar = new EventListenerRegistrar($dispatcher);

        $cache = new TestTagAwareCacheBackend();
        $registrar->registerDiscoveryCacheListeners($cache);

        $dispatcher->dispatch(
            new EntityEvent(new TestKernelEntity(9, 'node')),
            EntityEvents::POST_SAVE->value,
        );

        $this->assertSame(0, $cache->deleteAllCalls);
        $this->assertNotEmpty($cache->invalidatedTags);
        $this->assertContains('discovery', $cache->invalidatedTags);
        $this->assertContains('discovery:entity:node', $cache->invalidatedTags);
        $this->assertContains('discovery:entity:node:9', $cache->invalidatedTags);
    }

    #[Test]
    public function discovery_cache_listener_falls_back_to_delete_all_for_non_tag_backend(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $registrar = new EventListenerRegistrar($dispatcher);

        $cache = new TestNonTagCacheBackend();
        $registrar->registerDiscoveryCacheListeners($cache);

        $dispatcher->dispatch(
            new EntityEvent(new TestKernelEntity(5, 'node')),
            EntityEvents::POST_DELETE->value,
        );

        $this->assertSame(1, $cache->deleteAllCalls);
    }

    #[Test]
    public function mcp_read_cache_listener_uses_tag_invalidation_when_available(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $registrar = new EventListenerRegistrar($dispatcher);

        $cache = new TestTagAwareCacheBackend();
        $registrar->registerMcpReadCacheListeners($cache);

        $dispatcher->dispatch(
            new EntityEvent(new TestKernelEntity(11, 'node')),
            EntityEvents::POST_SAVE->value,
        );

        $this->assertSame(0, $cache->deleteAllCalls);
        $this->assertContains('mcp_read', $cache->invalidatedTags);
        $this->assertContains('mcp_read:entity:node', $cache->invalidatedTags);
        $this->assertContains('mcp_read:entity:node:11', $cache->invalidatedTags);
    }

    #[Test]
    public function mcp_read_cache_listener_falls_back_to_delete_all_for_non_tag_backend(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $registrar = new EventListenerRegistrar($dispatcher);

        $cache = new TestNonTagCacheBackend();
        $registrar->registerMcpReadCacheListeners($cache);

        $dispatcher->dispatch(
            new EntityEvent(new TestKernelEntity(12, 'node')),
            EntityEvents::POST_DELETE->value,
        );

        $this->assertSame(1, $cache->deleteAllCalls);
    }

    #[Test]
    public function discovery_cache_listener_includes_surface_tag_for_relationship_updates(): void
    {
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $registrar = new EventListenerRegistrar($dispatcher);

        $cache = new TestTagAwareCacheBackend();
        $registrar->registerDiscoveryCacheListeners($cache);

        $dispatcher->dispatch(
            new EntityEvent(new TestKernelEntity(1, 'relationship')),
            EntityEvents::POST_SAVE->value,
        );

        $this->assertContains('discovery:surface:discovery_api', $cache->invalidatedTags);
    }
}

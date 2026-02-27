<?php

declare(strict_types=1);

namespace Aurora\Cache\Tests\Unit;

use Aurora\Cache\Backend\MemoryBackend;
use Aurora\Cache\Backend\NullBackend;
use Aurora\Cache\CacheBackendInterface;
use Aurora\Cache\CacheItem;
use Aurora\Cache\CacheTagsInvalidator;
use Aurora\Cache\CacheTagsInvalidatorInterface;
use PHPUnit\Framework\TestCase;

final class CacheTagsInvalidatorTest extends TestCase
{
    public function testInvalidateTagsAcrossBins(): void
    {
        $bin1 = new MemoryBackend();
        $bin2 = new MemoryBackend();

        $bin1->set('node:1', 'node data', CacheBackendInterface::PERMANENT, ['node:1', 'node_list']);
        $bin1->set('user:1', 'user data', CacheBackendInterface::PERMANENT, ['user:1']);

        $bin2->set('render:node:1', 'rendered node', CacheBackendInterface::PERMANENT, ['node:1']);
        $bin2->set('render:user:1', 'rendered user', CacheBackendInterface::PERMANENT, ['user:1']);

        $invalidator = new CacheTagsInvalidator();
        $invalidator->registerBin('cache_default', $bin1);
        $invalidator->registerBin('cache_render', $bin2);

        $invalidator->invalidateTags(['node:1']);

        // Bin 1: node:1 item should be invalidated, user:1 should not.
        $node1 = $bin1->get('node:1');
        $this->assertInstanceOf(CacheItem::class, $node1);
        $this->assertFalse($node1->valid);

        $user1 = $bin1->get('user:1');
        $this->assertInstanceOf(CacheItem::class, $user1);
        $this->assertTrue($user1->valid);

        // Bin 2: render:node:1 should be invalidated, render:user:1 should not.
        $renderNode = $bin2->get('render:node:1');
        $this->assertInstanceOf(CacheItem::class, $renderNode);
        $this->assertFalse($renderNode->valid);

        $renderUser = $bin2->get('render:user:1');
        $this->assertInstanceOf(CacheItem::class, $renderUser);
        $this->assertTrue($renderUser->valid);
    }

    public function testInvalidateTagsSkipsNonTagAwareBins(): void
    {
        $nullBin = new NullBackend();

        $invalidator = new CacheTagsInvalidator();
        $invalidator->registerBin('null', $nullBin);

        // Should not throw even though NullBackend does not implement TagAwareCacheInterface.
        $invalidator->invalidateTags(['some:tag']);

        // NullBackend always returns false, confirming no error occurred.
        $this->assertFalse($nullBin->get('anything'));
    }

    public function testInvalidateMultipleTagsAtOnce(): void
    {
        $bin = new MemoryBackend();
        $bin->set('node:1', 'data1', CacheBackendInterface::PERMANENT, ['node:1']);
        $bin->set('user:1', 'data2', CacheBackendInterface::PERMANENT, ['user:1']);
        $bin->set('menu:main', 'data3', CacheBackendInterface::PERMANENT, ['menu:main']);

        $invalidator = new CacheTagsInvalidator();
        $invalidator->registerBin('default', $bin);

        $invalidator->invalidateTags(['node:1', 'user:1']);

        $this->assertFalse($bin->get('node:1')->valid);
        $this->assertFalse($bin->get('user:1')->valid);
        $this->assertTrue($bin->get('menu:main')->valid);
    }

    public function testImplementsCacheTagsInvalidatorInterface(): void
    {
        $invalidator = new CacheTagsInvalidator();

        $this->assertInstanceOf(CacheTagsInvalidatorInterface::class, $invalidator);
    }
}

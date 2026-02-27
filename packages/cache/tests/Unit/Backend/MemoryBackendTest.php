<?php

declare(strict_types=1);

namespace Aurora\Cache\Tests\Unit\Backend;

use Aurora\Cache\Backend\MemoryBackend;
use Aurora\Cache\CacheBackendInterface;
use Aurora\Cache\CacheItem;
use Aurora\Cache\TagAwareCacheInterface;
use PHPUnit\Framework\TestCase;

final class MemoryBackendTest extends TestCase
{
    private MemoryBackend $backend;

    protected function setUp(): void
    {
        $this->backend = new MemoryBackend();
    }

    public function testSetAndGet(): void
    {
        $this->backend->set('item:1', 'hello world');

        $item = $this->backend->get('item:1');

        $this->assertInstanceOf(CacheItem::class, $item);
        $this->assertSame('item:1', $item->cid);
        $this->assertSame('hello world', $item->data);
        $this->assertSame(CacheBackendInterface::PERMANENT, $item->expire);
        $this->assertSame([], $item->tags);
        $this->assertTrue($item->valid);
    }

    public function testGetMiss(): void
    {
        $result = $this->backend->get('nonexistent');

        $this->assertFalse($result);
    }

    public function testGetMultiple(): void
    {
        $this->backend->set('a', 'value_a');
        $this->backend->set('b', 'value_b');
        $this->backend->set('c', 'value_c');

        $cids = ['a', 'b', 'missing', 'c'];
        $items = $this->backend->getMultiple($cids);

        // Found items should be returned.
        $this->assertCount(3, $items);
        $this->assertArrayHasKey('a', $items);
        $this->assertArrayHasKey('b', $items);
        $this->assertArrayHasKey('c', $items);
        $this->assertSame('value_a', $items['a']->data);
        $this->assertSame('value_b', $items['b']->data);
        $this->assertSame('value_c', $items['c']->data);

        // The $cids array should only contain items that were NOT found.
        $this->assertSame(['missing'], $cids);
    }

    public function testExpiration(): void
    {
        // Set an item that already expired (1 second in the past).
        $this->backend->set('expired', 'old data', time() - 1);

        $result = $this->backend->get('expired');

        $this->assertFalse($result);
    }

    public function testPermanentNeverExpires(): void
    {
        $this->backend->set('permanent', 'forever', CacheBackendInterface::PERMANENT);

        $item = $this->backend->get('permanent');

        $this->assertInstanceOf(CacheItem::class, $item);
        $this->assertSame('forever', $item->data);
        $this->assertSame(CacheBackendInterface::PERMANENT, $item->expire);
    }

    public function testDelete(): void
    {
        $this->backend->set('delete_me', 'data');
        $this->assertInstanceOf(CacheItem::class, $this->backend->get('delete_me'));

        $this->backend->delete('delete_me');

        $this->assertFalse($this->backend->get('delete_me'));
    }

    public function testDeleteMultiple(): void
    {
        $this->backend->set('a', 1);
        $this->backend->set('b', 2);
        $this->backend->set('c', 3);

        $this->backend->deleteMultiple(['a', 'c']);

        $this->assertFalse($this->backend->get('a'));
        $this->assertInstanceOf(CacheItem::class, $this->backend->get('b'));
        $this->assertFalse($this->backend->get('c'));
    }

    public function testDeleteAll(): void
    {
        $this->backend->set('a', 1);
        $this->backend->set('b', 2);

        $this->backend->deleteAll();

        $this->assertFalse($this->backend->get('a'));
        $this->assertFalse($this->backend->get('b'));
    }

    public function testInvalidate(): void
    {
        $this->backend->set('item', 'data');

        $this->backend->invalidate('item');

        $item = $this->backend->get('item');
        $this->assertInstanceOf(CacheItem::class, $item);
        $this->assertSame('data', $item->data);
        $this->assertFalse($item->valid);
    }

    public function testInvalidateMultiple(): void
    {
        $this->backend->set('a', 1);
        $this->backend->set('b', 2);
        $this->backend->set('c', 3);

        $this->backend->invalidateMultiple(['a', 'c']);

        $itemA = $this->backend->get('a');
        $itemB = $this->backend->get('b');
        $itemC = $this->backend->get('c');

        $this->assertInstanceOf(CacheItem::class, $itemA);
        $this->assertFalse($itemA->valid);

        $this->assertInstanceOf(CacheItem::class, $itemB);
        $this->assertTrue($itemB->valid);

        $this->assertInstanceOf(CacheItem::class, $itemC);
        $this->assertFalse($itemC->valid);
    }

    public function testInvalidateAll(): void
    {
        $this->backend->set('a', 1);
        $this->backend->set('b', 2);

        $this->backend->invalidateAll();

        $itemA = $this->backend->get('a');
        $itemB = $this->backend->get('b');

        $this->assertInstanceOf(CacheItem::class, $itemA);
        $this->assertFalse($itemA->valid);

        $this->assertInstanceOf(CacheItem::class, $itemB);
        $this->assertFalse($itemB->valid);
    }

    public function testInvalidateByTags(): void
    {
        $this->backend->set('node:1', 'node 1 data', CacheBackendInterface::PERMANENT, ['node:1', 'node_list']);
        $this->backend->set('node:2', 'node 2 data', CacheBackendInterface::PERMANENT, ['node:2', 'node_list']);
        $this->backend->set('user:1', 'user 1 data', CacheBackendInterface::PERMANENT, ['user:1']);

        // Invalidate only items tagged with 'node:1'.
        $this->backend->invalidateByTags(['node:1']);

        $node1 = $this->backend->get('node:1');
        $node2 = $this->backend->get('node:2');
        $user1 = $this->backend->get('user:1');

        $this->assertInstanceOf(CacheItem::class, $node1);
        $this->assertFalse($node1->valid);

        $this->assertInstanceOf(CacheItem::class, $node2);
        $this->assertTrue($node2->valid);

        $this->assertInstanceOf(CacheItem::class, $user1);
        $this->assertTrue($user1->valid);
    }

    public function testInvalidateByTagsAffectsMultipleItems(): void
    {
        $this->backend->set('node:1', 'data1', CacheBackendInterface::PERMANENT, ['node:1', 'node_list']);
        $this->backend->set('node:2', 'data2', CacheBackendInterface::PERMANENT, ['node:2', 'node_list']);
        $this->backend->set('user:1', 'data3', CacheBackendInterface::PERMANENT, ['user:1']);

        // Invalidate the shared 'node_list' tag.
        $this->backend->invalidateByTags(['node_list']);

        $node1 = $this->backend->get('node:1');
        $node2 = $this->backend->get('node:2');
        $user1 = $this->backend->get('user:1');

        // Both node items should be invalidated.
        $this->assertFalse($node1->valid);
        $this->assertFalse($node2->valid);

        // User item should be unaffected.
        $this->assertTrue($user1->valid);
    }

    public function testRemoveBin(): void
    {
        $this->backend->set('a', 1);
        $this->backend->set('b', 2);

        $this->backend->removeBin();

        $this->assertFalse($this->backend->get('a'));
        $this->assertFalse($this->backend->get('b'));
    }

    public function testImplementsTagAwareCacheInterface(): void
    {
        $this->assertInstanceOf(TagAwareCacheInterface::class, $this->backend);
    }

    public function testSetOverwritesExistingItem(): void
    {
        $this->backend->set('item', 'original');
        $this->backend->set('item', 'updated');

        $item = $this->backend->get('item');
        $this->assertSame('updated', $item->data);
    }

    public function testSetWithComplexData(): void
    {
        $data = ['nested' => ['array' => [1, 2, 3]], 'key' => 'value'];
        $this->backend->set('complex', $data);

        $item = $this->backend->get('complex');
        $this->assertSame($data, $item->data);
    }

    public function testFutureExpirationIsValid(): void
    {
        $this->backend->set('future', 'data', time() + 3600);

        $item = $this->backend->get('future');
        $this->assertInstanceOf(CacheItem::class, $item);
        $this->assertSame('data', $item->data);
    }

    public function testDeleteNonexistentItemDoesNotError(): void
    {
        // Should not throw.
        $this->backend->delete('nonexistent');
        $this->assertFalse($this->backend->get('nonexistent'));
    }

    public function testInvalidateNonexistentItemDoesNotError(): void
    {
        // Should not throw.
        $this->backend->invalidate('nonexistent');
        $this->assertFalse($this->backend->get('nonexistent'));
    }
}

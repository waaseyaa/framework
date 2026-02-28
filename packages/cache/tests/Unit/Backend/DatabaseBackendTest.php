<?php

declare(strict_types=1);

namespace Aurora\Cache\Tests\Unit\Backend;

use Aurora\Cache\Backend\DatabaseBackend;
use Aurora\Cache\CacheBackendInterface;
use Aurora\Cache\CacheItem;
use Aurora\Cache\TagAwareCacheInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseBackend::class)]
final class DatabaseBackendTest extends TestCase
{
    private \PDO $pdo;
    private DatabaseBackend $backend;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->backend = new DatabaseBackend($this->pdo, 'cache_test');
    }

    #[Test]
    public function set_and_get(): void
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

    #[Test]
    public function get_miss(): void
    {
        $result = $this->backend->get('nonexistent');

        $this->assertFalse($result);
    }

    #[Test]
    public function set_with_tags(): void
    {
        $this->backend->set('node:1', 'data', CacheBackendInterface::PERMANENT, ['node:1', 'node_list']);

        $item = $this->backend->get('node:1');

        $this->assertSame(['node:1', 'node_list'], $item->tags);
    }

    #[Test]
    public function get_multiple(): void
    {
        $this->backend->set('a', 'value_a');
        $this->backend->set('b', 'value_b');
        $this->backend->set('c', 'value_c');

        $cids = ['a', 'b', 'missing', 'c'];
        $items = $this->backend->getMultiple($cids);

        $this->assertCount(3, $items);
        $this->assertArrayHasKey('a', $items);
        $this->assertArrayHasKey('b', $items);
        $this->assertArrayHasKey('c', $items);
        $this->assertSame('value_a', $items['a']->data);

        // Remaining cids should only contain misses.
        $this->assertSame(['missing'], $cids);
    }

    #[Test]
    public function expiration(): void
    {
        $this->backend->set('expired', 'old data', time() - 1);

        $result = $this->backend->get('expired');

        $this->assertFalse($result);
    }

    #[Test]
    public function permanent_never_expires(): void
    {
        $this->backend->set('permanent', 'forever', CacheBackendInterface::PERMANENT);

        $item = $this->backend->get('permanent');

        $this->assertInstanceOf(CacheItem::class, $item);
        $this->assertSame('forever', $item->data);
    }

    #[Test]
    public function future_expiration_is_valid(): void
    {
        $this->backend->set('future', 'data', time() + 3600);

        $item = $this->backend->get('future');

        $this->assertInstanceOf(CacheItem::class, $item);
        $this->assertSame('data', $item->data);
    }

    #[Test]
    public function delete(): void
    {
        $this->backend->set('delete_me', 'data');
        $this->assertInstanceOf(CacheItem::class, $this->backend->get('delete_me'));

        $this->backend->delete('delete_me');

        $this->assertFalse($this->backend->get('delete_me'));
    }

    #[Test]
    public function delete_multiple(): void
    {
        $this->backend->set('a', 1);
        $this->backend->set('b', 2);
        $this->backend->set('c', 3);

        $this->backend->deleteMultiple(['a', 'c']);

        $this->assertFalse($this->backend->get('a'));
        $this->assertInstanceOf(CacheItem::class, $this->backend->get('b'));
        $this->assertFalse($this->backend->get('c'));
    }

    #[Test]
    public function delete_all(): void
    {
        $this->backend->set('a', 1);
        $this->backend->set('b', 2);

        $this->backend->deleteAll();

        $this->assertFalse($this->backend->get('a'));
        $this->assertFalse($this->backend->get('b'));
    }

    #[Test]
    public function invalidate(): void
    {
        $this->backend->set('item', 'data');

        $this->backend->invalidate('item');

        $item = $this->backend->get('item');
        $this->assertInstanceOf(CacheItem::class, $item);
        $this->assertSame('data', $item->data);
        $this->assertFalse($item->valid);
    }

    #[Test]
    public function invalidate_multiple(): void
    {
        $this->backend->set('a', 1);
        $this->backend->set('b', 2);
        $this->backend->set('c', 3);

        $this->backend->invalidateMultiple(['a', 'c']);

        $this->assertFalse($this->backend->get('a')->valid);
        $this->assertTrue($this->backend->get('b')->valid);
        $this->assertFalse($this->backend->get('c')->valid);
    }

    #[Test]
    public function invalidate_all(): void
    {
        $this->backend->set('a', 1);
        $this->backend->set('b', 2);

        $this->backend->invalidateAll();

        $this->assertFalse($this->backend->get('a')->valid);
        $this->assertFalse($this->backend->get('b')->valid);
    }

    #[Test]
    public function invalidate_by_tags(): void
    {
        $this->backend->set('node:1', 'data1', CacheBackendInterface::PERMANENT, ['node:1', 'node_list']);
        $this->backend->set('node:2', 'data2', CacheBackendInterface::PERMANENT, ['node:2', 'node_list']);
        $this->backend->set('user:1', 'data3', CacheBackendInterface::PERMANENT, ['user:1']);

        $this->backend->invalidateByTags(['node:1']);

        $node1 = $this->backend->get('node:1');
        $node2 = $this->backend->get('node:2');
        $user1 = $this->backend->get('user:1');

        $this->assertFalse($node1->valid);
        $this->assertTrue($node2->valid);
        $this->assertTrue($user1->valid);
    }

    #[Test]
    public function invalidate_by_tags_shared_tag(): void
    {
        $this->backend->set('node:1', 'data1', CacheBackendInterface::PERMANENT, ['node:1', 'node_list']);
        $this->backend->set('node:2', 'data2', CacheBackendInterface::PERMANENT, ['node:2', 'node_list']);
        $this->backend->set('user:1', 'data3', CacheBackendInterface::PERMANENT, ['user:1']);

        $this->backend->invalidateByTags(['node_list']);

        $this->assertFalse($this->backend->get('node:1')->valid);
        $this->assertFalse($this->backend->get('node:2')->valid);
        $this->assertTrue($this->backend->get('user:1')->valid);
    }

    #[Test]
    public function remove_bin(): void
    {
        $this->backend->set('a', 1);

        $this->backend->removeBin();

        // After removeBin, the table is dropped. Subsequent get should recreate it and return false.
        $this->assertFalse($this->backend->get('a'));
    }

    #[Test]
    public function implements_tag_aware_cache_interface(): void
    {
        $this->assertInstanceOf(TagAwareCacheInterface::class, $this->backend);
    }

    #[Test]
    public function set_overwrites_existing_item(): void
    {
        $this->backend->set('item', 'original');
        $this->backend->set('item', 'updated');

        $item = $this->backend->get('item');
        $this->assertSame('updated', $item->data);
    }

    #[Test]
    public function set_with_complex_data(): void
    {
        $data = ['nested' => ['array' => [1, 2, 3]], 'key' => 'value'];
        $this->backend->set('complex', $data);

        $item = $this->backend->get('complex');
        $this->assertSame($data, $item->data);
    }

    #[Test]
    public function delete_nonexistent_does_not_error(): void
    {
        $this->backend->delete('nonexistent');
        $this->assertFalse($this->backend->get('nonexistent'));
    }

    #[Test]
    public function invalidate_nonexistent_does_not_error(): void
    {
        $this->backend->invalidate('nonexistent');
        $this->assertFalse($this->backend->get('nonexistent'));
    }
}

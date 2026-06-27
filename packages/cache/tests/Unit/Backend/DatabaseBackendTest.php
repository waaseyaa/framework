<?php

declare(strict_types=1);

namespace Waaseyaa\Cache\Tests\Unit\Backend;

use Waaseyaa\Cache\Backend\DatabaseBackend;
use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Cache\CacheItem;
use Waaseyaa\Cache\TagAwareCacheInterface;
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
    public function invalidate_by_tags_does_not_overmatch_underscore_wildcard(): void
    {
        // node_list contains an underscore, a LIKE wildcard. The over-match only
        // manifests when the colliding token is followed by a comma in a multi-tag
        // blob: an unescaped `node_list,%` pattern matches the stored blob
        // `nodeXlist,extra` because `_` matches the `X`. So the load-bearing
        // regression key (`nodexlist_multi_key`) carries a SECOND tag — on the
        // buggy (un-escaped) code it is wrongly invalidated; on the fixed
        // (`node\_list` + ESCAPE) code it survives. Single-tag variants are also
        // asserted to cover the literal acceptance ("a key tagged only nodeXlist").
        $this->backend->set('node_list_multi_key', 'data', CacheBackendInterface::PERMANENT, ['node_list', 'extra']);
        $this->backend->set('nodexlist_multi_key', 'data', CacheBackendInterface::PERMANENT, ['nodeXlist', 'extra']);
        $this->backend->set('nodexlist_key', 'data', CacheBackendInterface::PERMANENT, ['nodeXlist']);
        $this->backend->set('node_key', 'data', CacheBackendInterface::PERMANENT, ['node']);

        $this->backend->invalidateByTags(['node_list']);

        // The genuine node_list holder is invalidated.
        $this->assertFalse($this->backend->get('node_list_multi_key')->valid);
        // The underscore must NOT act as a wildcard against nodeXlist (multi-tag: the real bug).
        $this->assertTrue($this->backend->get('nodexlist_multi_key')->valid);
        // ...nor against single-tag siblings.
        $this->assertTrue($this->backend->get('nodexlist_key')->valid);
        $this->assertTrue($this->backend->get('node_key')->valid);
    }

    #[Test]
    public function invalidate_by_tags_does_not_overmatch_percent_wildcard(): void
    {
        // A percent sign in a tag is also a LIKE wildcard. Unescaped, invalidating
        // tag 'a%b' yields the pattern `a%b,%`, where the first `%` is a wildcard
        // that spans any text — so the multi-tag blob `axxb,extra` is wrongly
        // matched. The load-bearing key carries a second tag so the bug fires on
        // the un-escaped code; with `a\%b` + ESCAPE only the literal 'a%b' matches.
        $this->backend->set('ab_multi_key', 'data', CacheBackendInterface::PERMANENT, ['a%b', 'extra']);
        $this->backend->set('axxb_multi_key', 'data', CacheBackendInterface::PERMANENT, ['axxb', 'extra']);
        $this->backend->set('axxb_key', 'data', CacheBackendInterface::PERMANENT, ['axxb']);

        $this->backend->invalidateByTags(['a%b']);

        $this->assertFalse($this->backend->get('ab_multi_key')->valid);
        $this->assertTrue($this->backend->get('axxb_multi_key')->valid);
        $this->assertTrue($this->backend->get('axxb_key')->valid);
    }

    #[Test]
    public function invalidate_by_tags_matches_tag_at_all_blob_positions(): void
    {
        // The escaping must not break legitimate multi-tag comma-blob matching.
        // A tag containing underscores (which require escaping) must still be
        // found whether it appears at the start, end, or middle of the blob.
        $this->backend->set('start_key', 'data', CacheBackendInterface::PERMANENT, ['node_list', 'extra']);
        $this->backend->set('end_key', 'data', CacheBackendInterface::PERMANENT, ['extra', 'node_list']);
        $this->backend->set('mid_key', 'data', CacheBackendInterface::PERMANENT, ['x', 'node_list', 'y']);
        $this->backend->set('unrelated_key', 'data', CacheBackendInterface::PERMANENT, ['other']);

        $this->backend->invalidateByTags(['node_list']);

        $this->assertFalse($this->backend->get('start_key')->valid);
        $this->assertFalse($this->backend->get('end_key')->valid);
        $this->assertFalse($this->backend->get('mid_key')->valid);
        $this->assertTrue($this->backend->get('unrelated_key')->valid);
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

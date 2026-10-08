<?php

declare(strict_types=1);

namespace Waaseyaa\Listing\Tests\Fixtures;

use Waaseyaa\Cache\Backend\MemoryBackend;
use Waaseyaa\Cache\CacheItem;
use Waaseyaa\Cache\TaggedCacheInterface;

/** Delegates every terminal cache operation while counting listing lookup/store calls. */
final class CountingTaggedCache implements TaggedCacheInterface
{
    public int $gets = 0;
    public int $stores = 0;
    public function __construct(public readonly MemoryBackend $inner = new MemoryBackend()) {}
    public function get(string $cid): CacheItem|false
    {
        ++$this->gets;
        return $this->inner->get($cid);
    }
    public function getMultiple(array &$cids): array
    {
        return $this->inner->getMultiple($cids);
    }
    public function set(string $cid, mixed $data, int $expire = self::PERMANENT, array $tags = []): void
    {
        $this->inner->set($cid, $data, $expire, $tags);
    }
    public function setWithTags(string $key, mixed $value, array $tags, ?int $ttl = null): void
    {
        ++$this->stores;
        $this->inner->setWithTags($key, $value, $tags, $ttl);
    }
    public function invalidateByTag(string $tag): int
    {
        return $this->inner->invalidateByTag($tag);
    }
    public function getTagsFor(string $key): array
    {
        return $this->inner->getTagsFor($key);
    }
    public function delete(string $cid): void
    {
        $this->inner->delete($cid);
    }
    public function deleteMultiple(array $cids): void
    {
        $this->inner->deleteMultiple($cids);
    }
    public function deleteAll(): void
    {
        $this->inner->deleteAll();
    }
    public function invalidate(string $cid): void
    {
        $this->inner->invalidate($cid);
    }
    public function invalidateMultiple(array $cids): void
    {
        $this->inner->invalidateMultiple($cids);
    }
    public function invalidateAll(): void
    {
        $this->inner->invalidateAll();
    }
    public function removeBin(): void
    {
        $this->inner->removeBin();
    }
}

<?php

declare(strict_types=1);

namespace Aurora\Cache\Backend;

use Aurora\Cache\CacheBackendInterface;
use Aurora\Cache\CacheItem;
use Aurora\Cache\TagAwareCacheInterface;

final class MemoryBackend implements TagAwareCacheInterface
{
    /** @var array<string, CacheItem> */
    private array $cache = [];

    public function get(string $cid): CacheItem|false
    {
        if (!isset($this->cache[$cid])) {
            return false;
        }

        $item = $this->cache[$cid];

        // Check expiration: expired items are removed and return false.
        // PERMANENT items never expire.
        if ($item->expire !== CacheBackendInterface::PERMANENT && $item->expire < time()) {
            unset($this->cache[$cid]);
            return false;
        }

        return $item;
    }

    /** @return array<string, CacheItem> */
    public function getMultiple(array &$cids): array
    {
        $items = [];
        $remaining = [];

        foreach ($cids as $cid) {
            $item = $this->get($cid);
            if ($item !== false) {
                $items[$cid] = $item;
            } else {
                $remaining[] = $cid;
            }
        }

        $cids = $remaining;

        return $items;
    }

    public function set(string $cid, mixed $data, int $expire = self::PERMANENT, array $tags = []): void
    {
        $this->cache[$cid] = new CacheItem(
            cid: $cid,
            data: $data,
            created: time(),
            expire: $expire,
            tags: $tags,
            valid: true,
        );
    }

    public function delete(string $cid): void
    {
        unset($this->cache[$cid]);
    }

    public function deleteMultiple(array $cids): void
    {
        foreach ($cids as $cid) {
            unset($this->cache[$cid]);
        }
    }

    public function deleteAll(): void
    {
        $this->cache = [];
    }

    public function invalidate(string $cid): void
    {
        if (isset($this->cache[$cid])) {
            $old = $this->cache[$cid];
            $this->cache[$cid] = new CacheItem(
                cid: $old->cid,
                data: $old->data,
                created: $old->created,
                expire: $old->expire,
                tags: $old->tags,
                valid: false,
            );
        }
    }

    public function invalidateMultiple(array $cids): void
    {
        foreach ($cids as $cid) {
            $this->invalidate($cid);
        }
    }

    public function invalidateAll(): void
    {
        foreach (array_keys($this->cache) as $cid) {
            $this->invalidate($cid);
        }
    }

    public function removeBin(): void
    {
        $this->cache = [];
    }

    /** @param string[] $tags */
    public function invalidateByTags(array $tags): void
    {
        $tagsToInvalidate = array_flip($tags);

        foreach ($this->cache as $cid => $item) {
            foreach ($item->tags as $tag) {
                if (isset($tagsToInvalidate[$tag])) {
                    $this->invalidate($cid);
                    break;
                }
            }
        }
    }
}

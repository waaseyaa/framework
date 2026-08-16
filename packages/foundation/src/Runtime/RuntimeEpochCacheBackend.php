<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Runtime;

use Waaseyaa\Cache\CacheItem;
use Waaseyaa\Cache\TagAwareCacheInterface;

/** Cache namespace that makes entries unreadable across runtime-epoch changes. */
final class RuntimeEpochCacheBackend implements TagAwareCacheInterface
{
    private readonly string $prefix;
    private readonly string $rootTag;

    public function __construct(
        private readonly TagAwareCacheInterface $inner,
        string $epochFingerprint,
    ) {
        $identity = hash('sha256', $epochFingerprint);
        $this->prefix = 'epoch-' . $identity . ':';
        $this->rootTag = 'epoch-' . $identity;
    }

    public function get(string $cid): CacheItem|false
    {
        $item = $this->inner->get($this->key($cid));

        return $item === false ? false : $this->item($cid, $item);
    }

    public function getMultiple(array &$cids): array
    {
        $original = array_values($cids);
        $prefixed = array_map($this->key(...), $original);
        $items = $this->inner->getMultiple($prefixed);
        $result = [];
        $misses = [];
        foreach ($original as $index => $cid) {
            $item = $items[$this->key($cid)] ?? null;
            if ($item instanceof CacheItem) {
                $result[$cid] = $this->item($cid, $item);
            } else {
                $misses[] = $cid;
            }
        }
        $cids = $misses;

        return $result;
    }

    public function set(string $cid, mixed $data, int $expire = self::PERMANENT, array $tags = []): void
    {
        $this->inner->set($this->key($cid), $data, $expire, [
            $this->rootTag,
            ...array_map($this->tag(...), $tags),
        ]);
    }

    public function delete(string $cid): void
    {
        $this->inner->delete($this->key($cid));
    }
    public function deleteMultiple(array $cids): void
    {
        $this->inner->deleteMultiple(array_map($this->key(...), $cids));
    }
    public function invalidate(string $cid): void
    {
        $this->inner->invalidate($this->key($cid));
    }
    public function invalidateMultiple(array $cids): void
    {
        $this->inner->invalidateMultiple(array_map($this->key(...), $cids));
    }

    public function deleteAll(): void
    {
        $this->inner->invalidateByTags([$this->rootTag]);
    }
    public function invalidateAll(): void
    {
        $this->inner->invalidateByTags([$this->rootTag]);
    }
    public function removeBin(): void
    {
        $this->inner->invalidateByTags([$this->rootTag]);
    }

    public function invalidateByTags(array $tags): void
    {
        $this->inner->invalidateByTags(array_map($this->tag(...), $tags));
    }

    private function key(string $cid): string
    {
        return $this->prefix . $cid;
    }
    private function tag(string $tag): string
    {
        return $this->rootTag . ':' . $tag;
    }

    private function item(string $cid, CacheItem $item): CacheItem
    {
        return new CacheItem($cid, $item->data, $item->created, $item->expire, $item->tags, $item->valid);
    }
}

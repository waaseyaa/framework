<?php

declare(strict_types=1);

namespace Waaseyaa\Cache\Backend;

use Waaseyaa\Cache\CacheBackendInterface;
use Waaseyaa\Cache\CacheItem;
use Waaseyaa\Cache\Exception\InvalidCacheTagException;
use Waaseyaa\Cache\ProjectionDeprecationDiagnostic;
use Waaseyaa\Cache\TagAwareCacheInterface;
use Waaseyaa\Cache\TaggedCacheInterface;

/**
 * @api
 */
final class MemoryBackend implements TagAwareCacheInterface, TaggedCacheInterface
{
    public function __construct(private readonly ?ProjectionDeprecationDiagnostic $projectionDiagnostic = null) {}

    /** @var array<string, CacheItem> */
    private array $cache = [];

    /**
     * Reverse index: tag => set<key>.
     *
     * Maintained alongside `$cache` by the {@see TaggedCacheInterface} path
     * (FR-033). Independent from the legacy `CacheItem::$tags` field, which is
     * scanned linearly by {@see invalidateByTags()} for the existing
     * key→tags shape.
     *
     * @var array<string, array<string, true>>
     */
    private array $tagIndex = [];

    /**
     * Forward index: key => set<tag>.
     *
     * Mirror of {@see self::$tagIndex} so {@see getTagsFor()} and per-key
     * eviction during {@see invalidateByTag()} run in O(tags) rather than
     * scanning the full reverse index.
     *
     * @var array<string, array<string, true>>
     */
    private array $keyTags = [];

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
        $data = $this->projectionDiagnostic?->inspect($cid, $data) ?? $data;
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
        $this->forgetKeyTags($cid);
    }

    public function deleteMultiple(array $cids): void
    {
        foreach ($cids as $cid) {
            unset($this->cache[$cid]);
            $this->forgetKeyTags($cid);
        }
    }

    public function deleteAll(): void
    {
        $this->cache = [];
        $this->tagIndex = [];
        $this->keyTags = [];
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
        $this->tagIndex = [];
        $this->keyTags = [];
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

    /**
     * {@inheritDoc}
     *
     * Stores the value via the existing {@see set()} path (so legacy code that
     * reads via {@see get()} keeps working) and additionally maintains a
     * reverse tag index for {@see invalidateByTag()}.
     *
     * The strict {@see TaggedCacheInterface::TAG_REGEX} regex is enforced
     * here — invalid tags throw {@see InvalidCacheTagException} BEFORE the
     * value is stored, so a rejected call leaves the cache untouched.
     */
    public function setWithTags(string $key, mixed $value, array $tags, ?int $ttl = null): void
    {
        // Validate every tag up-front; any failure aborts the whole call.
        foreach ($tags as $tag) {
            $this->assertValidTag($tag);
        }

        // Translate ?int TTL into the CacheBackendInterface expire semantic:
        // null => PERMANENT; positive => absolute unix expiry; <= 0 => already
        // expired (rejected by the existing get() path on next read).
        $expire = $ttl === null
            ? CacheBackendInterface::PERMANENT
            : time() + $ttl;

        // Reuse existing set() so legacy CacheItem::$tags and ::$expire stay
        // populated for any subscriber that reads them.
        $this->set($key, $value, $expire, $tags);

        // Replace (not merge) the tag set for this key — overwrite semantics
        // match the existing set() behaviour and the contract test
        // `getTagsForReturnsLastSetWithTagsCall`.
        $this->forgetKeyTags($key);
        foreach ($tags as $tag) {
            $this->tagIndex[$tag][$key] = true;
            $this->keyTags[$key][$tag] = true;
        }
    }

    /**
     * {@inheritDoc}
     *
     * Evicts (deletes — not soft-invalidates) every key that was registered
     * under `$tag` via {@see setWithTags()}. Returns the count of evicted
     * keys; returns 0 if the tag was never seen.
     *
     * Eviction goes through {@see delete()} so the legacy `$cache` map is
     * cleared in addition to the reverse index.
     */
    public function invalidateByTag(string $tag): int
    {
        if (!isset($this->tagIndex[$tag])) {
            return 0;
        }

        $keys = array_keys($this->tagIndex[$tag]);
        $evicted = 0;

        foreach ($keys as $cid) {
            // Only count keys that are currently present — re-invalidating
            // a tag whose keys were already deleted should not over-count.
            if (isset($this->cache[$cid]) || isset($this->keyTags[$cid])) {
                ++$evicted;
            }
            unset($this->cache[$cid]);
            $this->forgetKeyTags($cid);
        }

        return $evicted;
    }

    /**
     * {@inheritDoc}
     *
     * Returns the sorted list of tags registered for `$key` via
     * {@see setWithTags()}. Returns an empty list when:
     *
     * - `$key` was never stored via {@see setWithTags()}
     * - `$key` was stored without tags
     * - `$key` was deleted (via {@see delete()}, {@see deleteAll()},
     *   {@see removeBin()}, or evicted by {@see invalidateByTag()})
     */
    public function getTagsFor(string $key): array
    {
        if (!isset($this->keyTags[$key])) {
            return [];
        }

        $tags = array_keys($this->keyTags[$key]);
        sort($tags);

        /** @var list<non-empty-string> $tags */
        return $tags;
    }

    /**
     * Validates a single tag against {@see TaggedCacheInterface::TAG_REGEX}.
     *
     * @throws InvalidCacheTagException
     */
    private function assertValidTag(string $tag): void
    {
        if ($tag === '' || preg_match(TaggedCacheInterface::TAG_REGEX, $tag) !== 1) {
            throw new InvalidCacheTagException($tag);
        }
    }

    /**
     * Drops every tag-index entry referencing `$key`, in both directions.
     */
    private function forgetKeyTags(string $key): void
    {
        if (!isset($this->keyTags[$key])) {
            return;
        }

        foreach (array_keys($this->keyTags[$key]) as $tag) {
            unset($this->tagIndex[$tag][$key]);
            if ($this->tagIndex[$tag] === []) {
                unset($this->tagIndex[$tag]);
            }
        }

        unset($this->keyTags[$key]);
    }
}

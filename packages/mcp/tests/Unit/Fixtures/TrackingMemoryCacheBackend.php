<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Fixtures;

use Waaseyaa\Cache\Backend\MemoryBackend;
use Waaseyaa\Cache\CacheItem;
use Waaseyaa\Cache\TagAwareCacheInterface;

final class TrackingMemoryCacheBackend implements TagAwareCacheInterface
{
    public int $getCalls = 0;
    public int $setCalls = 0;

    private MemoryBackend $inner;

    public function __construct()
    {
        $this->inner = new MemoryBackend();
    }

    public function get(string $cid): CacheItem|false
    {
        $this->getCalls++;
        return $this->inner->get($cid);
    }

    public function getMultiple(array &$cids): array
    {
        return $this->inner->getMultiple($cids);
    }

    public function set(string $cid, mixed $data, int $expire = self::PERMANENT, array $tags = []): void
    {
        $this->setCalls++;
        $this->inner->set($cid, $data, $expire, $tags);
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

    public function invalidateByTags(array $tags): void
    {
        $this->inner->invalidateByTags($tags);
    }
}

<?php

declare(strict_types=1);

namespace Aurora\Cache;

interface TagAwareCacheInterface extends CacheBackendInterface
{
    /** @param string[] $tags */
    public function invalidateByTags(array $tags): void;
}

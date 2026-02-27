<?php

declare(strict_types=1);

namespace Aurora\Cache;

final class CacheTagsInvalidator implements CacheTagsInvalidatorInterface
{
    /** @var CacheBackendInterface[] */
    private array $bins = [];

    public function registerBin(string $name, CacheBackendInterface $bin): void
    {
        $this->bins[$name] = $bin;
    }

    /** @param string[] $tags */
    public function invalidateTags(array $tags): void
    {
        foreach ($this->bins as $bin) {
            if ($bin instanceof TagAwareCacheInterface) {
                $bin->invalidateByTags($tags);
            }
        }
    }
}

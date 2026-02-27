<?php

declare(strict_types=1);

namespace Aurora\Cache;

use Aurora\Cache\Backend\MemoryBackend;

final class CacheFactory implements CacheFactoryInterface
{
    /** @var array<string, CacheBackendInterface> */
    private array $bins = [];

    public function __construct(
        private readonly string $defaultBackendClass = MemoryBackend::class,
    ) {}

    public function get(string $bin): CacheBackendInterface
    {
        if (!isset($this->bins[$bin])) {
            $class = $this->defaultBackendClass;
            $this->bins[$bin] = new $class();
        }

        return $this->bins[$bin];
    }
}

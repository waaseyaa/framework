<?php

declare(strict_types=1);

namespace Aurora\Cache;

use Aurora\Cache\Backend\MemoryBackend;

final class CacheFactory implements CacheFactoryInterface
{
    /** @var array<string, CacheBackendInterface> */
    private array $bins = [];

    private readonly CacheConfiguration $configuration;

    /**
     * @param string|CacheConfiguration $defaultBackendClass Backend class or CacheConfiguration instance
     */
    public function __construct(
        string|CacheConfiguration $defaultBackendClass = MemoryBackend::class,
    ) {
        if ($defaultBackendClass instanceof CacheConfiguration) {
            $this->configuration = $defaultBackendClass;
        } else {
            $this->configuration = new CacheConfiguration($defaultBackendClass);
        }
    }

    public function get(string $bin): CacheBackendInterface
    {
        if (!isset($this->bins[$bin])) {
            $class = $this->configuration->getBackendForBin($bin);
            $this->bins[$bin] = new $class();
        }

        return $this->bins[$bin];
    }
}

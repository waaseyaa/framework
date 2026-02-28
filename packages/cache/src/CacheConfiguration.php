<?php

declare(strict_types=1);

namespace Aurora\Cache;

/**
 * Maps cache bin names to backend class names.
 *
 * Allows fine-grained control over which cache backend handles each bin.
 * Bins not explicitly mapped use the default backend.
 */
final class CacheConfiguration
{
    /** @var array<string, string> bin name => backend class FQCN */
    private array $binMapping = [];

    /**
     * @param string $defaultBackend FQCN of the default backend class
     * @param array<string, string> $binMapping bin name => backend class FQCN
     */
    public function __construct(
        private readonly string $defaultBackend = Backend\MemoryBackend::class,
        array $binMapping = [],
    ) {
        foreach ($binMapping as $bin => $backendClass) {
            $this->setBackendForBin($bin, $backendClass);
        }
    }

    /**
     * Map a bin name to a specific backend class.
     */
    public function setBackendForBin(string $bin, string $backendClass): void
    {
        $this->binMapping[$bin] = $backendClass;
    }

    /**
     * Get the backend class for a given bin name.
     *
     * Returns the bin-specific backend if configured, otherwise the default.
     */
    public function getBackendForBin(string $bin): string
    {
        return $this->binMapping[$bin] ?? $this->defaultBackend;
    }

    /**
     * Get the default backend class.
     */
    public function getDefaultBackend(): string
    {
        return $this->defaultBackend;
    }

    /**
     * Get all bin-to-backend mappings.
     *
     * @return array<string, string>
     */
    public function getBinMapping(): array
    {
        return $this->binMapping;
    }
}

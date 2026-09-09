<?php

declare(strict_types=1);

namespace Waaseyaa\Cache;

use Waaseyaa\Cache\Backend\MemoryBackend;

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
        private readonly ?ProjectionDeprecationDiagnostic $projectionDiagnostic = null,
    ) {
        if ($defaultBackendClass instanceof CacheConfiguration) {
            $this->configuration = $defaultBackendClass;
        } else {
            $this->configuration = new CacheConfiguration($defaultBackendClass);
        }
    }

    /**
     * The configuration this factory resolves bins against.
     *
     * Lets a caller enumerate the bins actually registered
     * ({@see CacheConfiguration::getConfiguredBins()}) without maintaining a
     * second, independently-kept list of "the application's cache bins"
     * (#3025).
     */
    public function getConfiguration(): CacheConfiguration
    {
        return $this->configuration;
    }

    public function get(string $bin): CacheBackendInterface
    {
        if (!isset($this->bins[$bin])) {
            $factory = $this->configuration->getFactoryForBin($bin);

            if ($factory !== null) {
                $this->bins[$bin] = $factory();
            } else {
                $class = $this->configuration->getBackendForBin($bin);
                $this->bins[$bin] = $class === MemoryBackend::class
                    ? new MemoryBackend($this->projectionDiagnostic)
                    : new $class();
            }
        }

        return $this->bins[$bin];
    }
}

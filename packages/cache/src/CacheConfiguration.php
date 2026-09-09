<?php

declare(strict_types=1);

namespace Waaseyaa\Cache;

/**
 * Maps cache bin names to backend class names or factory callables.
 *
 * Allows fine-grained control over which cache backend handles each bin.
 * Bins not explicitly mapped use the default backend.
 *
 * For backends that require constructor arguments (e.g. DatabaseBackend
 * which needs a \PDO instance), register a factory callable instead of a
 * plain class name:
 *
 *   $config->setFactoryForBin('cache_db', fn() => new DatabaseBackend($pdo, 'cache_db'));
 * @api
 */
final class CacheConfiguration
{
    /** @var array<string, string> bin name => backend class FQCN */
    private array $binMapping = [];

    /** @var array<string, callable(): CacheBackendInterface> bin name => factory callable */
    private array $binFactories = [];

    /** @var (callable(): CacheBackendInterface)|null default factory callable */
    private mixed $defaultFactory = null;

    /**
     * @param string                                     $defaultBackend  FQCN of the default backend class
     * @param array<string, string>                      $binMapping      bin name => backend class FQCN
     * @param array<string, callable(): CacheBackendInterface> $binFactories bin name => factory callable
     * @param (callable(): CacheBackendInterface)|null   $defaultFactory  factory callable for the default backend
     */
    public function __construct(
        private readonly string $defaultBackend = Backend\MemoryBackend::class,
        array $binMapping = [],
        array $binFactories = [],
        ?callable $defaultFactory = null,
    ) {
        foreach ($binMapping as $bin => $backendClass) {
            $this->setBackendForBin($bin, $backendClass);
        }
        foreach ($binFactories as $bin => $factory) {
            $this->setFactoryForBin($bin, $factory);
        }
        $this->defaultFactory = $defaultFactory;
    }

    /**
     * Map a bin name to a specific backend class.
     */
    public function setBackendForBin(string $bin, string $backendClass): void
    {
        $this->binMapping[$bin] = $backendClass;
    }

    /**
     * Map a bin name to a factory callable that produces a CacheBackendInterface.
     *
     * Use this when the backend requires constructor arguments:
     *
     *   $config->setFactoryForBin('cache_db', fn() => new DatabaseBackend($pdo));
     *
     * A factory registered for a bin takes precedence over a class name for
     * the same bin.
     *
     * @param callable(): CacheBackendInterface $factory
     */
    public function setFactoryForBin(string $bin, callable $factory): void
    {
        $this->binFactories[$bin] = $factory;
    }

    /**
     * Set the default factory callable used when no bin-specific mapping exists.
     *
     * @param callable(): CacheBackendInterface $factory
     */
    public function setDefaultFactory(callable $factory): void
    {
        $this->defaultFactory = $factory;
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
     * Return the factory callable for a given bin, or null if none is registered.
     *
     * Bin-specific factories take precedence; the default factory is used as a
     * fallback when no bin-specific factory is found.
     *
     * @return (callable(): CacheBackendInterface)|null
     */
    public function getFactoryForBin(string $bin): ?callable
    {
        return $this->binFactories[$bin] ?? $this->defaultFactory;
    }

    /**
     * Get the default backend class.
     */
    public function getDefaultBackend(): string
    {
        return $this->defaultBackend;
    }

    /**
     * Get all bin-to-backend class mappings.
     *
     * @return array<string, string>
     */
    public function getBinMapping(): array
    {
        return $this->binMapping;
    }

    /**
     * The canonical enumeration of every bin this configuration explicitly
     * registers, whether by class mapping ({@see setBackendForBin}) or by
     * factory ({@see setFactoryForBin}).
     *
     * This is the single accessor callers should use to discover "the
     * application's configured bins" (e.g. `cache:clear`'s bin inventory,
     * #3025) instead of maintaining a separately hand-kept list: a bin
     * registered here can never be invisible to a caller that reads this
     * method, and a bin never registered here can never be falsely reported
     * as configured. Deterministically ordered (bin-mapping entries first in
     * registration order, then factory entries in registration order, with
     * duplicates collapsed) so output and iteration are reproducible.
     *
     * The default backend (used for any bin name that reaches
     * {@see getBackendForBin} or {@see getFactoryForBin} without an explicit
     * registration here, e.g. an ad hoc bin name a caller invents) is
     * intentionally excluded: it is not a bin the application configured,
     * it is CacheFactory's unconfigured fallback.
     *
     * @return list<string>
     */
    public function getConfiguredBins(): array
    {
        $bins = [];
        foreach (array_keys($this->binMapping) as $bin) {
            $bins[$bin] = true;
        }
        foreach (array_keys($this->binFactories) as $bin) {
            $bins[$bin] = true;
        }

        // PHP coerces a numeric-string array key to int, so array_keys() would
        // hand back int(123) for a bin registered as '123' — violating the
        // declared list<string> and making the consumer report a configured
        // bin as "not configured". Cast back to the registered string form.
        return array_map(strval(...), array_keys($bins));
    }
}

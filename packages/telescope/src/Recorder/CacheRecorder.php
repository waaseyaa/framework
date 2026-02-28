<?php

declare(strict_types=1);

namespace Aurora\Telescope\Recorder;

use Aurora\Telescope\Storage\TelescopeStoreInterface;

/**
 * Records cache operations (hit, miss, set, forget) with key, value, and duration.
 */
final class CacheRecorder
{
    public const string TYPE = 'cache';

    public function __construct(
        private readonly TelescopeStoreInterface $store,
    ) {}

    /**
     * Record a cache operation.
     *
     * @param string $operation One of: hit, miss, set, forget.
     * @param string $key The cache key.
     * @param mixed $value The cached value (optional, for set/hit).
     * @param float $duration Duration in milliseconds (optional).
     */
    public function record(
        string $operation,
        string $key,
        mixed $value = null,
        float $duration = 0.0,
    ): void {
        $data = [
            'operation' => $operation,
            'key' => $key,
            'duration' => $duration,
            'timestamp' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ];

        if ($value !== null) {
            $data['value'] = $this->serializeValue($value);
        }

        $this->store->store(self::TYPE, $data);
    }

    /**
     * Record a cache hit.
     */
    public function recordHit(string $key, mixed $value = null, float $duration = 0.0): void
    {
        $this->record('hit', $key, $value, $duration);
    }

    /**
     * Record a cache miss.
     */
    public function recordMiss(string $key, float $duration = 0.0): void
    {
        $this->record('miss', $key, duration: $duration);
    }

    /**
     * Record a cache set.
     */
    public function recordSet(string $key, mixed $value = null, float $duration = 0.0): void
    {
        $this->record('set', $key, $value, $duration);
    }

    /**
     * Record a cache forget/delete.
     */
    public function recordForget(string $key): void
    {
        $this->record('forget', $key);
    }

    private function serializeValue(mixed $value): mixed
    {
        if (is_scalar($value) || is_null($value)) {
            return $value;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return get_class($value) . '(...)';
        }

        return gettype($value);
    }
}

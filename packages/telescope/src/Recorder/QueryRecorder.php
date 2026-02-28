<?php

declare(strict_types=1);

namespace Aurora\Telescope\Recorder;

use Aurora\Telescope\Storage\TelescopeStoreInterface;

/**
 * Records database queries with SQL, bindings, duration, and connection name.
 */
final class QueryRecorder
{
    public const string TYPE = 'query';

    public function __construct(
        private readonly TelescopeStoreInterface $store,
        private readonly float $slowQueryThreshold = 100.0,
        private readonly bool $slowQueriesOnly = false,
    ) {}

    /**
     * Record a database query.
     *
     * @param string $sql The SQL statement.
     * @param array<int|string, mixed> $bindings Query parameter bindings.
     * @param float $duration Duration in milliseconds.
     * @param string $connection Connection name (e.g. "default", "replica").
     */
    public function record(
        string $sql,
        array $bindings,
        float $duration,
        string $connection = 'default',
    ): void {
        if ($this->slowQueriesOnly && $duration < $this->slowQueryThreshold) {
            return;
        }

        $this->store->store(self::TYPE, [
            'sql' => $sql,
            'bindings' => $bindings,
            'duration' => $duration,
            'connection' => $connection,
            'slow' => $duration >= $this->slowQueryThreshold,
            'timestamp' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function getSlowQueryThreshold(): float
    {
        return $this->slowQueryThreshold;
    }

    public function isSlowQueriesOnly(): bool
    {
        return $this->slowQueriesOnly;
    }
}

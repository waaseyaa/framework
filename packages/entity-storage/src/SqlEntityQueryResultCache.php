<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

/**
 * Request-scoped cache for {@see SqlEntityQuery} result ID lists / counts.
 *
 * A fresh instance starts a new request cache generation.
 */
final class SqlEntityQueryResultCache
{
    /** @var array<string, array<string, array<int|string>>> Fingerprint hash => result rows */
    private array $entriesByType = [];

    /**
     * @return array<int|string>|null
     */
    public function get(string $entityTypeId, string $fingerprint): ?array
    {
        return $this->entriesByType[$entityTypeId][$fingerprint] ?? null;
    }

    /**
     * @param array<int|string> $result
     */
    public function set(string $entityTypeId, string $fingerprint, array $result): void
    {
        $this->entriesByType[$entityTypeId][$fingerprint] = $result;
    }
}

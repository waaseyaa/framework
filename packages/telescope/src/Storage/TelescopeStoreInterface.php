<?php

declare(strict_types=1);

namespace Aurora\Telescope\Storage;

use Aurora\Telescope\TelescopeEntry;

/**
 * Interface for telescope entry storage backends.
 */
interface TelescopeStoreInterface
{
    /**
     * Store a telescope entry.
     *
     * @param array<string, mixed> $data
     */
    public function store(string $type, array $data): void;

    /**
     * Query stored entries by type.
     *
     * @return TelescopeEntry[]
     */
    public function query(string $type, int $limit = 50, int $offset = 0): array;

    /**
     * Prune entries older than the given date.
     *
     * @return int Number of entries pruned.
     */
    public function prune(\DateTimeInterface $before): int;

    /**
     * Clear all stored entries.
     */
    public function clear(): void;
}

<?php

declare(strict_types=1);

namespace Aurora\EntityStorage\Driver;

/**
 * Low-level persistence SPI for entity storage.
 *
 * Drivers handle raw I/O without entity hydration or event dispatch.
 * The EntityRepository layer handles those concerns on top.
 *
 * Implementations: SqlStorageDriver, InMemoryStorageDriver, etc.
 */
interface EntityStorageDriverInterface
{
    /**
     * Read a single row by entity type and ID.
     *
     * @param string $entityType The entity type machine name (table name).
     * @param string $id The entity ID.
     * @param string|null $langcode Optional language code to load a specific translation.
     * @return array<string, mixed>|null Raw values, or null if not found.
     */
    public function read(string $entityType, string $id, ?string $langcode = null): ?array;

    /**
     * Write (insert or update) a row.
     *
     * @param string $entityType The entity type machine name.
     * @param string $id The entity ID.
     * @param array<string, mixed> $values The raw values to persist.
     */
    public function write(string $entityType, string $id, array $values): void;

    /**
     * Remove a row by entity type and ID.
     *
     * @param string $entityType The entity type machine name.
     * @param string $id The entity ID.
     */
    public function remove(string $entityType, string $id): void;

    /**
     * Check if a row exists.
     *
     * @param string $entityType The entity type machine name.
     * @param string $id The entity ID.
     */
    public function exists(string $entityType, string $id): bool;

    /**
     * Count rows matching optional criteria.
     *
     * @param string $entityType The entity type machine name.
     * @param array<string, mixed> $criteria Field => value pairs to filter by.
     * @return int The count of matching rows.
     */
    public function count(string $entityType, array $criteria = []): int;

    /**
     * Find rows matching criteria.
     *
     * @param string $entityType The entity type machine name.
     * @param array<string, mixed> $criteria Field => value pairs to filter by.
     * @param array<string, string>|null $orderBy Field => direction ('ASC'/'DESC') pairs.
     * @param int|null $limit Maximum number of results.
     * @return array<int, array<string, mixed>> List of raw value arrays.
     */
    public function findBy(
        string $entityType,
        array $criteria = [],
        ?array $orderBy = null,
        ?int $limit = null,
    ): array;
}

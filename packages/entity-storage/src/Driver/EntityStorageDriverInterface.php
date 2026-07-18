<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Driver;

/**
 * Low-level persistence SPI for entity storage.
 *
 * Drivers handle raw I/O without entity hydration or event dispatch.
 * The EntityRepository layer handles those concerns on top.
 *
 * Implementations: SqlStorageDriver, InMemoryStorageDriver, etc.
 *
 * @api
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
     * Read multiple rows by ID in one round-trip.
     *
     * @param list<int|string> $ids Entity IDs (empty IDs are ignored, duplicates collapsed).
     * @param string|null $langcode Optional language (same semantics as {@see read()}).
     * @return array<string, array<string, mixed>> Rows keyed by string ID; missing IDs omitted.
     */
    public function readMultiple(string $entityType, array $ids, ?string $langcode = null): array;

    /**
     * Write (insert or update) a row.
     *
     * When $id is an empty string, the driver treats the call as an insert of a
     * new row and returns the id the storage backend assigned (for SQL-backed
     * drivers, the auto-increment primary key via lastInsertId). When $id is a
     * non-empty string, the driver returns that same id.
     *
     * @param string $entityType The entity type machine name.
     * @param string $id The entity ID, or empty string for an auto-assigned id.
     * @param array<string, mixed> $values The raw values to persist.
     * @return string The effective id of the persisted row.
     */
    public function write(string $entityType, string $id, array $values): string;

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

    /**
     * Load every stored translation row for a single entity in one query.
     *
     * Returns a `langcode => row` map. The default langcode (when known and
     * present) MUST be the first entry; remaining langcodes follow in ascending
     * lexicographic order. Returns an empty array when no translation storage
     * is available for the given entity type.
     *
     * Backend semantics:
     *   - `sql-blob`: a single SELECT against the primary table on entity id
     *     across the composite `(entity_id, langcode)` PK (WP04 layout).
     *   - `sql-column`: a single INNER JOIN of primary + `<table>__translation`
     *     keyed by the entity id (WP05 layout).
     *   - In-memory driver: walks its translation store.
     *
     * @param string      $entityType       Primary table / entity-type id.
     * @param string      $id               Entity id.
     * @param string|null $defaultLangcode  Default langcode of the entity, when known.
     *                                      Used to order the result so the default appears first.
     * @return array<string, array<string, mixed>> Translation rows keyed by langcode.
     */
    public function findTranslations(
        string $entityType,
        string $id,
        ?string $defaultLangcode = null,
    ): array;
}

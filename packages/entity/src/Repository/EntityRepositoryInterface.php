<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Repository;

use Waaseyaa\Entity\EntityInterface;

/**
 * High-level repository API for entity persistence.
 *
 * This is the public API developers use. It handles entity hydration,
 * language fallback, event dispatch, and delegates raw I/O to a
 * storage driver.
 */
interface EntityRepositoryInterface
{
    /**
     * Find an entity by ID.
     *
     * @param string $id The entity ID.
     * @param string|null $langcode Optional language code to load a specific translation.
     * @param bool $fallback Whether to apply language fallback chain.
     * @return EntityInterface|null The entity, or null if not found.
     */
    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface;

    /**
     * Find entities matching criteria.
     *
     * @param array<string, mixed> $criteria Field => value pairs to match.
     * @param array<string, string>|null $orderBy Field => direction pairs.
     * @param int|null $limit Maximum number of results.
     * @return EntityInterface[] Matching entities.
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array;

    /**
     * Save (insert or update) an entity.
     *
     * Dispatches pre/post save domain events.
     *
     * @param EntityInterface $entity The entity to save.
     * @return int SAVED_NEW (1) or SAVED_UPDATED (2).
     */
    public function save(EntityInterface $entity): int;

    /**
     * Delete an entity.
     *
     * Dispatches pre/post delete domain events.
     *
     * @param EntityInterface $entity The entity to delete.
     */
    public function delete(EntityInterface $entity): void;

    /**
     * Check if an entity with the given ID exists.
     *
     * @param string $id The entity ID.
     */
    public function exists(string $id): bool;

    /**
     * Count entities matching optional criteria.
     *
     * @param array<string, mixed> $criteria Field => value pairs to match.
     */
    public function count(array $criteria = []): int;

    /**
     * Load a specific revision of an entity.
     *
     * @param string $entityId The entity ID.
     * @param int $revisionId The revision ID.
     * @return EntityInterface|null The entity hydrated from the revision, or null.
     */
    public function loadRevision(string $entityId, int $revisionId): ?EntityInterface;

    /**
     * Rollback an entity to a previous revision (copy-forward).
     *
     * Creates a new revision with the target revision's field values
     * and auto-annotates the revision log.
     *
     * @param string $entityId The entity ID.
     * @param int $targetRevisionId The revision to copy values from.
     * @return EntityInterface The entity hydrated from the new revision.
     * @throws \InvalidArgumentException If the target revision does not exist.
     */
    public function rollback(string $entityId, int $targetRevisionId): EntityInterface;
}

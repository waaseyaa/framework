<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Repository;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;

/**
 * High-level repository API for entity persistence.
 *
 * This is the public API developers use. It handles entity hydration,
 * language fallback, event dispatch, and delegates raw I/O to a
 * storage driver.
 * @api
 */
interface EntityRepositoryInterface
{
    /**
     * Construct a new, unsaved entity of this repository's type.
     *
     * Applies registered field-definition defaults for any key absent from
     * $values, then hydrates the entity class and marks it new (isNew()). Pure
     * object construction — no I/O. Callers must still call save() to persist.
     *
     * @param array<string, mixed> $values
     */
    public function create(array $values = []): EntityInterface;

    /**
     * Find an entity by ID.
     *
     * @param string $id The entity ID.
     * @param string|null $langcode Optional language code to load a specific translation.
     * @param bool $fallback Whether to apply language fallback chain.
     * @return EntityInterface|null The entity, or null if not found.
     */
    #[\NoDiscard('lookup result must be checked for null')]
    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface;

    /**
     * Find many entities by ID in one driver round-trip (when not using language fallback).
     *
     * Preserves the order of the first occurrence of each non-empty ID in $ids; omits missing IDs.
     *
     * @param list<int|string> $ids
     * @return list<EntityInterface>
     */
    #[\NoDiscard('lookup result must be checked for null')]
    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array;

    /**
     * Find entities matching criteria.
     *
     * @param array<string, mixed> $criteria Field => value pairs to match.
     * @param array<string, string>|null $orderBy Field => direction pairs.
     * @param int|null $limit Maximum number of results.
     * @return EntityInterface[] Matching entities.
     */
    #[\NoDiscard('lookup result must be checked for null')]
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array;

    /**
     * Build an access-checked entity query.
     *
     * Returns the SAME access-checked query surface as
     * {@see \Waaseyaa\Entity\Storage\EntityStorageInterface::getQuery()}: the
     * returned query is fail-closed by default. Callers MUST bind the acting
     * account via `setAccount()` before `execute()`, or opt out explicitly with
     * `accessCheck(false)` for trusted system contexts. An account-bound query
     * runs the same per-row `EntityAccessHandler::check($entity, 'view', $account)`
     * filter, so an implementation wired with the same access handler as the
     * storage engine yields identical access-filtered results.
     *
     * Example:
     *   $ids = $repository->getQuery()->setAccount($account)
     *       ->condition($key, $value)->range(0, 1)->execute();
     */
    public function getQuery(): EntityQueryInterface;

    /**
     * Save (insert or update) an entity.
     *
     * Dispatches pre/post save domain events.
     *
     * @param EntityInterface $entity The entity to save.
     * @param bool $validate Whether to run pre-save validation.
     * @return int SAVED_NEW (1) or SAVED_UPDATED (2).
     * @throws \Waaseyaa\Entity\Validation\EntityValidationException If validation fails.
     * @throws \Doctrine\DBAL\Exception\UniqueConstraintViolationException If a unique constraint (e.g. duplicate id/uuid) is violated.
     */
    public function save(EntityInterface $entity, bool $validate = true): int;

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
    #[\NoDiscard('lookup result must be checked for null')]
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
    #[\NoDiscard('lookup result must be checked for null')]
    public function rollback(string $entityId, int $targetRevisionId): EntityInterface;

    /**
     * List an entity's revisions, newest first.
     *
     * Each entity is hydrated with revision metadata (revision id, created,
     * log, and the is_default_revision / is_latest_revision flags).
     *
     * @param string $entityId The entity ID.
     * @return list<EntityInterface> Revisions ordered newest-first (empty if none).
     */
    public function listRevisions(string $entityId): array;

    /**
     * Make an existing revision the current/default revision in place, without
     * creating a new revision (unlike rollback, which records a fresh revision).
     *
     * @param string $entityId The entity ID.
     * @param int $revisionId The existing revision to make current.
     * @return EntityInterface The entity hydrated from the now-current revision.
     * @throws \InvalidArgumentException If the revision does not exist.
     */
    #[\NoDiscard('lookup result must be checked for null')]
    public function setCurrentRevision(string $entityId, int $revisionId): EntityInterface;

    /**
     * Load the entity's PUBLISHED revision, or null when none is published yet.
     *
     * The published revision is tracked by a base-table pointer that is
     * SEPARATE from the current/latest revision, so the public view can render
     * a stable published revision while newer draft revisions exist. Returns
     * null when the entity has no published revision (the pointer is NULL) or
     * when the entity does not exist.
     *
     * @param string $entityId The entity ID.
     * @return EntityInterface|null The entity hydrated from the published revision, or null.
     */
    #[\NoDiscard('lookup result must be checked for null')]
    public function loadPublishedRevision(string $entityId): ?EntityInterface;

    /**
     * Promote an existing revision to be the PUBLISHED revision.
     *
     * Moves only the published-revision pointer; the current/latest revision
     * (the working draft) is left untouched, so the live view and the draft can
     * differ. Publishing an older revision is how rollback of the live view
     * works. This is the deliberate "go live" step, distinct from saving a draft.
     *
     * @param string $entityId The entity ID.
     * @param int $revisionId The existing revision to publish.
     * @return EntityInterface The entity hydrated from the now-published revision.
     * @throws \InvalidArgumentException If the revision does not exist.
     */
    #[\NoDiscard('lookup result must be checked for null')]
    public function setPublishedRevision(string $entityId, int $revisionId): EntityInterface;

    /**
     * Save multiple entities in a single transaction.
     *
     * Events are buffered during the transaction and dispatched after commit.
     * On failure, all changes are rolled back and no events are dispatched.
     *
     * @param EntityInterface[] $entities The entities to save.
     * @param bool $validate Whether to run pre-save validation (forward-looking hook for #569).
     * @return int[] Array of SAVED_NEW/SAVED_UPDATED per entity (same order as input).
     * @throws \LogicException If no database connection is available for transactions.
     */
    public function saveMany(array $entities, bool $validate = true): array;

    /**
     * Delete multiple entities in a single transaction.
     *
     * Events are buffered during the transaction and dispatched after commit.
     * On failure, all changes are rolled back and no events are dispatched.
     *
     * @param EntityInterface[] $entities The entities to delete.
     * @return int Number of entities deleted.
     * @throws \LogicException If no database connection is available for transactions.
     */
    public function deleteMany(array $entities): int;

    /**
     * Load all translations of a translatable entity in a single query (FR-040, NFR-005).
     *
     * Returns a `langcode => EntityInterface` map. The default langcode is the
     * first entry; remaining langcodes follow in ascending lexicographic order.
     *
     * Behaviour:
     *   - Non-translatable entity types → empty array (cheap exit).
     *   - Translatable entity with no stored translations → empty array.
     *   - Translatable entity with N translations → N instances; each instance's
     *     `activeLangcode()` matches its map key and its translation-data payload
     *     contains every loaded langcode (shared via PHP copy-on-write so the map
     *     is not duplicated per instance — NFR-003).
     *
     * Implementations MUST issue at most one driver-level query for the row
     * fetch (sql-blob: SELECT FROM the primary table; sql-column: INNER JOIN of
     * primary + translation sibling). Per-langcode round-trips are forbidden.
     *
     * @param EntityInterface $entity Source entity (only its id + entity-type id are read).
     * @return array<string, EntityInterface>
     */
    public function findTranslations(EntityInterface $entity): array;

    // ---------------------------------------------------------------------
    // Two-axis translation surface (revisionable × translatable). Each edits one
    // language as a true peer — its own (id, langcode) base row and its own
    // independent per-language revision sequence. Only valid on a two-axis entity
    // type; a single-axis implementation throws. Promoted from the concrete
    // EntityRepository so consumers depend on the interface rather than narrowing
    // with instanceof. Scoped to the methods consumers actually call through the
    // contract; the lower-level per-revision API (saveTranslationRevision(s),
    // loadTranslationRevision, loadTranslationTip, translationLangcodes) stays on
    // the concrete EntityRepository until a consumer needs it on the interface. (b1)
    // ---------------------------------------------------------------------

    /**
     * Unified two-axis save of one language's content: upsert the peer
     * (id, langcode) base row AND record a per-language revision in one
     * transaction. Returns the new per-language revision id.
     *
     * @param array<string, mixed> $values This language's field values.
     */
    public function saveTranslation(string $entityId, string $langcode, array $values, ?string $log = null): int;

    /**
     * Load the current value of one language from its peer (id, langcode) base
     * row, or null when that language has no row yet.
     */
    public function loadTranslation(string $entityId, string $langcode): ?EntityInterface;

    /**
     * One language's revisions, newest first.
     *
     * @return list<EntityInterface>
     */
    public function listTranslationRevisions(string $entityId, string $langcode): array;
}

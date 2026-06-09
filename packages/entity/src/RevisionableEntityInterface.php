<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/**
 * Marker interface for entities that participate in the revision system.
 *
 * Entities that implement this interface MUST be registered with an
 * `EntityType` that has `revisionable: true` and a non-empty
 * `entityKeys['revision']` key. The `RevisionableEntityTrait` provides
 * default property storage and implementations for the three methods below.
 *
 * This interface defines the entity-side contract only. The storage side lives
 * in `waaseyaa/entity-storage` (`EntityRepository` + `RevisionableStorageDriver`),
 * the single revision system, which also carries the optional translation axis
 * for revisionable + translatable types.
 *
 * @api
 */
interface RevisionableEntityInterface extends EntityInterface
{
    /**
     * The revision id (vid) of this entity instance.
     *
     * Returns `null` when the entity has never been persisted (i.e. it is new
     * and no revision row exists yet).
     *
     * @api
     */
    public function revisionId(): int|string|null;

    /**
     * Whether this entity instance represents the current (latest) revision.
     *
     * The storage layer sets this to `true` when loading via the normal
     * `find()` / `findBy()` path, and to `false` when loading via
     * `loadRevision()` for a historical revision.
     *
     * @api
     */
    public function isCurrentRevision(): bool;

    /**
     * Metadata associated with this revision (author, timestamp, log message).
     *
     * Returns `null` when the entity has not yet been persisted or when the
     * revision row was created before revision metadata was introduced.
     *
     * @api
     */
    public function revisionMetadata(): ?RevisionMetadata;
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/**
 * Default implementations for entity revision support.
 *
 * This trait satisfies two revision contracts:
 *
 * 1. The **pre-WP07 `RevisionableInterface`** (legacy): `getRevisionId()`,
 *    `isDefaultRevision()`, `isLatestRevision()`, `setNewRevision()`,
 *    `isNewRevision()`, `setRevisionLog()`, `getRevisionLog()`.
 *    These read/write values from the entity's `$values` array and are
 *    used by `EntityRepository` and `RevisionableStorageDriver`.
 *
 * 2. The **WP07 `RevisionableEntityInterface`** (new): `revisionId()`,
 *    `isCurrentRevision()`, `revisionMetadata()`, and corresponding setters.
 *    These are hydrated by the storage layer after persisting/loading a
 *    revision row. Application code uses the new interface for read access;
 *    the storage layer calls the `set*` helpers.
 *
 * ## Usage
 *
 * ```php
 * final class Teaching extends ContentEntityBase implements RevisionableEntityInterface
 * {
 *     use RevisionableEntityTrait;
 *
 *     public function __construct(array $values = [])
 *     {
 *         parent::__construct($values, 'teaching', [
 *             'id' => 'id', 'uuid' => 'uuid', 'revision' => 'revision_id',
 *         ]);
 *     }
 * }
 * ```
 *
 * @api
 */
trait RevisionableEntityTrait
{
    // -------------------------------------------------------------------------
    // WP07 fields (new RevisionableEntityInterface contract)
    // -------------------------------------------------------------------------

    /**
     * The revision id for this entity instance (WP07 contract).
     *
     * `null` until the entity has been persisted (INSERT) for the first time.
     * The storage layer sets this after writing the revision row.
     *
     * @api
     */
    private int|string|null $revisionId = null;

    /**
     * Whether this instance represents the current (latest) revision.
     *
     * Defaults to `true` for new (unsaved) entities. The storage layer
     * sets this to `false` when loading a historical revision via
     * `loadRevision()`.
     *
     * @api
     */
    private bool $isCurrentRevision = true;

    /**
     * Per-revision metadata (author, timestamp, log message).
     *
     * `null` until the first save, or when loaded from a pre-revision-aware row.
     *
     * @api
     */
    private ?RevisionMetadata $revisionMetadata = null;

    /**
     * @api
     */
    public function revisionId(): int|string|null
    {
        return $this->revisionId;
    }

    /**
     * @api
     */
    public function isCurrentRevision(): bool
    {
        return $this->isCurrentRevision;
    }

    /**
     * @api
     */
    public function revisionMetadata(): ?RevisionMetadata
    {
        return $this->revisionMetadata;
    }

    /**
     * Set the revision id.
     *
     * Called by the storage layer after persisting a new revision row.
     * Application code should not call this directly.
     *
     * @api
     */
    public function setRevisionId(int|string|null $revisionId): void
    {
        $this->revisionId = $revisionId;
    }

    /**
     * Set whether this instance is the current revision.
     *
     * Called by the storage layer. Application code should not call this directly.
     *
     * @api
     */
    public function setIsCurrentRevision(bool $isCurrentRevision): void
    {
        $this->isCurrentRevision = $isCurrentRevision;
    }

    /**
     * Set the revision metadata.
     *
     * Called by the storage layer after persisting or loading a revision row.
     * Application code should not call this directly.
     *
     * @api
     */
    public function setRevisionMetadata(?RevisionMetadata $metadata): void
    {
        $this->revisionMetadata = $metadata;
    }

    // -------------------------------------------------------------------------
    // Legacy RevisionableInterface methods (pre-WP07, preserved for compat)
    // -------------------------------------------------------------------------

    /** @var bool|null null = use entity type default */
    private ?bool $newRevision = null;

    /**
     * Default-revision discipline flag (CW-v1 option-1 forward-draft rebuild,
     * #1920 PR-1).
     *
     * Transient — never persisted, like {@see $newRevision} above. Set
     * unconditionally as a plain boolean on EVERY guarded PRE_SAVE by the
     * workflows save-path guard (`WorkflowStateGuard`, wired in the next PR
     * — this flag and {@see EntityRepository::doSave()}'s handling of it
     * ship dormant in PR-1): absent/false always means undisciplined, so a
     * stale `true` from a prior save of a long-lived entity object can never
     * leak into a later, unguarded save.
     *
     * `EntityRepository::doSave()` duck-checks
     * {@see isDefaultRevisionDisciplined()} (mirroring the `#1654`
     * `method_exists` pattern already used for `getRevisionId()`) and, when
     * true on an existing revisionable entity with a revision driver wired,
     * treats the save as revision-only (or published-pointer-scoped
     * in-place) instead of always advancing the base row — see
     * `docs/specs/revision-system-unified.md` "Default-revision discipline
     * (CW-v1 option-1)".
     *
     * @api
     */
    private bool $defaultRevisionDiscipline = false;

    /**
     * @api
     */
    public function setDefaultRevisionDiscipline(bool $value): void
    {
        $this->defaultRevisionDiscipline = $value;
    }

    /**
     * @api
     */
    public function isDefaultRevisionDisciplined(): bool
    {
        return $this->defaultRevisionDiscipline;
    }

    public function getRevisionId(): ?int
    {
        $revisionKey = $this->entityKeys['revision'] ?? 'revision_id';
        $value = $this->values[$revisionKey] ?? null;

        if ($value === null) {
            return null;
        }

        return (int) $value;
    }

    public function isDefaultRevision(): bool
    {
        return (bool) ($this->values['is_default_revision'] ?? true);
    }

    public function isLatestRevision(): bool
    {
        return (bool) ($this->values['is_latest_revision'] ?? true);
    }

    public function setNewRevision(bool $value): void
    {
        $this->newRevision = $value;
    }

    public function isNewRevision(): ?bool
    {
        return $this->newRevision;
    }

    public function setRevisionLog(?string $log): void
    {
        $this->values['revision_log'] = $log;
    }

    public function getRevisionLog(): ?string
    {
        return isset($this->values['revision_log'])
            ? (string) $this->values['revision_log']
            : null;
    }
}

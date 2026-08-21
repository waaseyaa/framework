<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

/**
 * @api
 *
 * Immutable value object carrying flags for a single save operation.
 *
 * Resolves open question Q6 (research §3): dedicated value object, not a flags
 * array on {@see EntityStorageCoordinator::write()}. Future flags (e.g.
 * withoutEvents, skipValidation) extend this object without changing call sites.
 *
 * ## Usage
 * ```php
 * $ctx = SaveContext::default();
 * $ctx = $ctx->withoutNewRevision(); // returns NEW instance; original unchanged
 * $ctx = $ctx->withLangcode('fr');   // pin save to a non-active translation
 * ```
 */
final class SaveContext
{
    /**
     * @param bool $withoutNewRevision Suppress revision creation on this save.
     * @param ?string $langcode Pin the save to a specific translation langcode (null = active langcode).
     * @param bool $isImport Signal that this save is part of a migration platform write
     *     (see {@see \Waaseyaa\Migration\Plugin\Destination\EntityDestination::write()},
     *     FR-022 of M-002). Subscribers to {@see \Waaseyaa\EntityStorage\Event\BeforeSaveEvent}
     *     / {@see \Waaseyaa\EntityStorage\Event\AfterSaveEvent} may read this flag and
     *     skip expensive non-essential work during bulk imports — e.g. cache
     *     invalidation, search-index refresh, downstream analytics. This is a
     *     passive signal: the coordinator does NOT alter its behaviour based on
     *     this flag; subscribers branch on it themselves.
     * @param ?list<non-empty-string> $translations Pin the save to a list of langcodes for
     *     atomic multi-language write (M-004 / WP03, FR-013). When non-null, the
     *     storage coordinator iterates the list inside a single transaction and
     *     rolls the whole set back with {@see \Waaseyaa\EntityStorage\Exception\PartialSaveException}
     *     on partial failure. Precedence: when both `$translations` and `$langcode`
     *     are set, `$translations` wins and `$langcode` is ignored for the save
     *     (per contracts/save-context-translations.md §3). Null = single-language
     *     save (M-006 unchanged path).
     * @param ?int $actorUid Explicit revision-author override for this save
     *     (mission revision-audit-provenance-01KTWY5V, FR-002). Meaningless
     *     unless `$actorOverridden` is true — read it through
     *     {@see actorUid()} / {@see actorOverridden()}.
     * @param bool $actorOverridden Whether {@see withActorUid()} was called on
     *     this chain. Distinguishes "no override" (defer to the ambient
     *     {@see \Waaseyaa\Access\Context\AccountContextInterface}) from an
     *     explicit `withActorUid(null)` (force a NULL author).
     * @param ?int $expectedRevisionId Optimistic-locking expectation
     *     (mission optimistic-locking-01KTXCHY, FR-001): the revision id the
     *     caller believes is current. Null = no expectation stated. Unlike
     *     the actor pair, no boolean pin is needed: a null expectation has no
     *     third meaning (there is no "I expect no revision" head state on a
     *     persisted revisionable row).
     * @param list<lowercase-string> $saveAdvisoryAcknowledgements Exact
     *     candidate-bound acknowledgement tokens carried by this save.
     */
    private function __construct(
        public readonly bool $withoutNewRevision = false,
        public readonly ?string $langcode = null,
        public readonly bool $isImport = false,
        public readonly ?array $translations = null,
        private readonly ?int $actorUid = null,
        private readonly bool $actorOverridden = false,
        private readonly ?int $expectedRevisionId = null,
        private readonly array $saveAdvisoryAcknowledgements = [],
    ) {}

    /**
     * Default context: create a new revision (standard behaviour).
     */
    public static function default(): self
    {
        return new self(withoutNewRevision: false, langcode: null, isImport: false, translations: null);
    }

    /**
     * Return a new instance with an explicit revision-author override
     * (mission revision-audit-provenance-01KTWY5V, FR-002, contract
     * revision-author.md clause 5).
     *
     * The override wins over the ambient acting-account context — including
     * `withActorUid(null)`, which forces a NULL author even inside an
     * authenticated request (system-attributed maintenance writes). The
     * `$actorUid` value is meaningless unless {@see actorOverridden()} is
     * true; resolution reads the pair, never the uid alone.
     *
     * @api
     */
    public function withActorUid(?int $uid): self
    {
        return new self(
            withoutNewRevision: $this->withoutNewRevision,
            langcode: $this->langcode,
            isImport: $this->isImport,
            translations: $this->translations,
            actorUid: $uid,
            actorOverridden: true,
            expectedRevisionId: $this->expectedRevisionId,
            saveAdvisoryAcknowledgements: $this->saveAdvisoryAcknowledgements,
        );
    }

    /**
     * Return a new instance stating an optimistic-locking expectation
     * (mission optimistic-locking-01KTXCHY, FR-001): the save is refused with
     * {@see \Waaseyaa\EntityStorage\Exception\RevisionConflictException} when
     * the entity's current revision differs from $revisionId at write time.
     * `null` is the explicit no-expectation pass-through (callers may thread
     * an optional value without branching). Honored only on revision-creating
     * saves of single-axis revisionable types — see contracts/conflict-detection.md §2.
     *
     * @throws \InvalidArgumentException When $revisionId < 1.
     * @api
     */
    public function withExpectedRevisionId(?int $revisionId): self
    {
        if ($revisionId !== null && $revisionId < 1) {
            throw new \InvalidArgumentException(
                'SaveContext::withExpectedRevisionId requires a positive revision id or null.',
            );
        }

        return new self(
            withoutNewRevision: $this->withoutNewRevision,
            langcode: $this->langcode,
            isImport: $this->isImport,
            translations: $this->translations,
            actorUid: $this->actorUid,
            actorOverridden: $this->actorOverridden,
            expectedRevisionId: $revisionId,
            saveAdvisoryAcknowledgements: $this->saveAdvisoryAcknowledgements,
        );
    }

    /**
     * The stated optimistic-locking expectation. Null = no expectation.
     *
     * @api
     */
    public function expectedRevisionId(): ?int
    {
        return $this->expectedRevisionId;
    }

    /**
     * The explicit author override. Meaningless unless {@see actorOverridden()}.
     *
     * @api
     */
    public function actorUid(): ?int
    {
        return $this->actorUid;
    }

    /**
     * Whether {@see withActorUid()} set an explicit author override on this
     * chain (true even for `withActorUid(null)` — the three-state pin).
     *
     * @api
     */
    public function actorOverridden(): bool
    {
        return $this->actorOverridden;
    }

    /**
     * Return a new instance with revision creation suppressed.
     *
     * Used for saves that update the current revision in place (e.g. auto-save,
     * status transitions) without cutting a new revision record.
     */
    public function withoutNewRevision(): self
    {
        return new self(
            withoutNewRevision: true,
            langcode: $this->langcode,
            isImport: $this->isImport,
            translations: $this->translations,
            actorUid: $this->actorUid,
            actorOverridden: $this->actorOverridden,
            expectedRevisionId: $this->expectedRevisionId,
            saveAdvisoryAcknowledgements: $this->saveAdvisoryAcknowledgements,
        );
    }

    /**
     * Return a new instance pinning the save to a specific translation langcode.
     *
     * When set, the storage coordinator writes the entity's data for this
     * langcode rather than {@see TranslatableInterface::activeLangcode()}.
     * Empty strings are rejected.
     */
    public function withLangcode(string $langcode): self
    {
        if ($langcode === '') {
            throw new \InvalidArgumentException('SaveContext::withLangcode requires a non-empty langcode.');
        }

        return new self(
            withoutNewRevision: $this->withoutNewRevision,
            langcode: $langcode,
            isImport: $this->isImport,
            translations: $this->translations,
            actorUid: $this->actorUid,
            actorOverridden: $this->actorOverridden,
            expectedRevisionId: $this->expectedRevisionId,
            saveAdvisoryAcknowledgements: $this->saveAdvisoryAcknowledgements,
        );
    }

    /**
     * Return a new instance marking the save as a migration platform import (FR-022).
     *
     * Set by {@see \Waaseyaa\Migration\Plugin\Destination\EntityDestination::write()}.
     * Event subscribers reading the resulting {@see BeforeSaveEvent} or
     * {@see AfterSaveEvent} may inspect `$saveContext->isImport` and branch.
     *
     * @api
     */
    public function asImport(): self
    {
        return new self(
            withoutNewRevision: $this->withoutNewRevision,
            langcode: $this->langcode,
            isImport: true,
            translations: $this->translations,
            actorUid: $this->actorUid,
            actorOverridden: $this->actorOverridden,
            expectedRevisionId: $this->expectedRevisionId,
            saveAdvisoryAcknowledgements: $this->saveAdvisoryAcknowledgements,
        );
    }

    /**
     * Pin the save to a list of langcodes for atomic multi-language write
     * (M-004 / WP03, FR-013).
     *
     * The storage coordinator opens a single transaction, iterates the list,
     * fires {@see \Waaseyaa\EntityStorage\Event\BeforeSaveEvent} per langcode,
     * applies each per-langcode write, and on commit fires a single
     * {@see \Waaseyaa\EntityStorage\Event\AfterSaveEvent} with
     * `affectedLangcodes()` carrying the full list. On any per-langcode failure
     * the whole transaction rolls back and {@see \Waaseyaa\EntityStorage\Exception\PartialSaveException}
     * is raised (no AfterSaveEvent).
     *
     * Precedence: when both `withTranslations()` and `withLangcode()` have been
     * set on the same chain, the multi-language save wins; `$langcode` is
     * preserved on the value object (callers may layer the builders fluently)
     * but ignored by the save coordinator. See
     * contracts/save-context-translations.md §3.
     *
     * Builder rejects:
     * - empty arrays (`InvalidArgumentException`);
     * - any non-string or empty-string element (`InvalidArgumentException`).
     *
     * Duplicate langcodes are accepted by the builder (PHP arrays are not sets);
     * deduplication is the coordinator's responsibility per
     * contracts/save-context-translations.md §5.
     *
     * @param array<int|string, mixed> $langcodes Expected shape:
     *     `list<non-empty-string>`. Runtime validators reject any deviation so
     *     non-typed call sites still hit the documented contract.
     *
     * @throws \InvalidArgumentException When `$langcodes` is empty or contains
     *                                   any non-string / empty-string element.
     *
     * @api
     */
    public function withTranslations(array $langcodes): self
    {
        if ($langcodes === []) {
            throw new \InvalidArgumentException(
                'SaveContext::withTranslations requires a non-empty list of langcodes.',
            );
        }

        $normalised = [];
        foreach ($langcodes as $candidate) {
            if (!\is_string($candidate) || $candidate === '') {
                throw new \InvalidArgumentException(
                    'SaveContext::withTranslations requires non-empty string langcodes.',
                );
            }
            $normalised[] = $candidate;
        }

        return new self(
            withoutNewRevision: $this->withoutNewRevision,
            langcode: $this->langcode,
            isImport: $this->isImport,
            translations: $normalised,
            actorUid: $this->actorUid,
            actorOverridden: $this->actorOverridden,
            expectedRevisionId: $this->expectedRevisionId,
            saveAdvisoryAcknowledgements: $this->saveAdvisoryAcknowledgements,
        );
    }

    /**
     * Return a new context carrying exact candidate-bound acknowledgement tokens.
     *
     * @param array<int|string, mixed> $tokens
     * @api
     */
    public function withSaveAdvisoryAcknowledgements(array $tokens): self
    {
        if (!array_is_list($tokens) || count($tokens) > 32) {
            throw new \InvalidArgumentException(
                'SaveContext advisory acknowledgements must be a list of at most 32 tokens.',
            );
        }

        $normalized = [];
        foreach ($tokens as $token) {
            if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
                throw new \InvalidArgumentException(
                    'SaveContext advisory acknowledgement tokens must be exact lowercase 64-character hex strings.',
                );
            }
            $normalized[$token] = true;
        }
        $normalized = array_keys($normalized);
        sort($normalized, \SORT_STRING);

        return new self(
            withoutNewRevision: $this->withoutNewRevision,
            langcode: $this->langcode,
            isImport: $this->isImport,
            translations: $this->translations,
            actorUid: $this->actorUid,
            actorOverridden: $this->actorOverridden,
            expectedRevisionId: $this->expectedRevisionId,
            saveAdvisoryAcknowledgements: $normalized,
        );
    }

    /** @return list<lowercase-string> */
    public function saveAdvisoryAcknowledgements(): array
    {
        return $this->saveAdvisoryAcknowledgements;
    }

    public function acknowledgesSaveAdvisory(string $token): bool
    {
        foreach ($this->saveAdvisoryAcknowledgements as $acknowledgement) {
            if (hash_equals($acknowledgement, $token)) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/**
 * Immutable value object carrying per-revision metadata.
 *
 * Stored in the live revision tables — `<entity>_revision` and, for two-axis
 * (revisionable + translatable) types, `<entity>__translation__revision` — as
 * the `revision_created`, `revision_author`, and `revision_log` columns,
 * hydrated by `EntityRepository::loadRevision()` and the translation-revision
 * loads (mission revision-audit-provenance-01KTWY5V, FR-009). Author and log
 * are nullable: `revision_author` is SQL NULL on every row created without an
 * acting context (including all pre-mission rows), and `0` if and only if the
 * anonymous account acted — null is never coerced to 0.
 *
 * @api
 */
final readonly class RevisionMetadata
{
    /**
     * @param \DateTimeImmutable $revisionCreatedAt  When this revision was created (ISO-8601 in storage).
     * @param int|null           $revisionAuthor     UID of the account that created the revision.
     *                                               Soft FK only — no ON DELETE cascade — so revision
     *                                               history survives user deletion.
     * @param string|null        $revisionLog        Optional human-readable log message for this revision.
     */
    public function __construct(
        public readonly \DateTimeImmutable $revisionCreatedAt,
        public readonly ?int $revisionAuthor = null,
        public readonly ?string $revisionLog = null,
    ) {}
}

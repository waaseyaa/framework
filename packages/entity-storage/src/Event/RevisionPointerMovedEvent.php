<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Event;

/**
 * @api
 *
 * Dispatched (by FQCN) when a revision POINTER moves without creating a new
 * revision — mission revision-audit-provenance-01KTWY5V (FR-006, research D4).
 *
 * Two dispatch sites, both AFTER the pointer transaction commits (a rolled-back
 * move produces no event, contract audit-attribution.md clause 14), alongside —
 * not replacing — the legacy {@see \Waaseyaa\Entity\Event\EntityEvents::REVISION_REVERTED}
 * dispatch:
 *
 *   - `EntityRepository::setPublishedRevision()` — operation `'publish'`;
 *     `$fromRevisionId` is the prior `published_revision_id` base-row pointer
 *     (null when previously unpublished).
 *   - `EntityRepository::setCurrentRevision()` — operation `'revert'`;
 *     `$fromRevisionId` is the prior base `revision_id` pointer.
 *
 * `EntityRepository::rollback()` dispatches NO pointer event: it creates a new
 * revision (authorship covered by `revision_author`) and flows through
 * `REVISION_CREATED` (contract clause 15).
 *
 * `$actorUid` is the actor resolved at dispatch time (override → ambient
 * account context → null); `0` means the anonymous account acted, `null` means
 * no acting context existed. Additive beyond the minimum contract payload so
 * listeners need not re-resolve.
 */
final class RevisionPointerMovedEvent
{
    /**
     * @param 'publish'|'revert' $operation
     */
    public function __construct(
        public readonly string $entityTypeId,
        public readonly string $entityId,
        public readonly string $operation,
        public readonly ?int $fromRevisionId,
        public readonly int $toRevisionId,
        public readonly ?int $actorUid = null,
    ) {}
}

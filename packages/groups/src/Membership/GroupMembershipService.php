<?php

declare(strict_types=1);

namespace Waaseyaa\Groups\Membership;

use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Groups\GroupRelationshipTypes;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Relationship\RelationshipMaintenanceReader;
use Waaseyaa\Relationship\RelationshipTopologyReader;

/**
 * Group (department) membership and content-group lookups and writes,
 * backed by `relationship` rows (CW-v1 WP-3 read side, WP-4 write side).
 * Mirrors {@see \Waaseyaa\Genealogy\Service\GenealogyFamilyService::memberPersonIds()}.
 *
 * Takes scalar identifiers (uid, entity id), not `AccountInterface`; the
 * service remains independent of the package's JSON:API access policy.
 *
 * **Only live rows count.** Every read query additionally filters on
 * `status = 1` (relationship liveness — an int column, schema default 1,
 * mirroring `RelationshipTraversalService`'s own `status` filtering): a
 * soft-revoked (`status = 0`) `group_membership` or `group_content` row is
 * never counted as membership or content-department assignment. Temporal
 * windows (`start_date`/`end_date` on the relationship row) are deliberately
 * NOT evaluated in v1 — that is out of scope here and left to a follow-up.
 *
 * **Writes are idempotent and soft-revoke-aware** (CW-v1 WP-4, design
 * decision 6): {@see self::addMember()}/{@see self::assignContent()}
 * reactivate an existing soft-revoked row instead of inserting a duplicate,
 * and are a no-op on an already-live row; {@see self::removeMember()}/
 * {@see self::unassignContent()} soft-revoke (`status = 0`) and never
 * delete. Both writer pairs validate the target group exists (loud failure
 * on a dangling edge) — the revoke pair does not, since revoking a reference
 * to an already-deleted group must still succeed.
 *
 * **Duplicate-row caveat**: the upsert path's find-then-create is NOT atomic
 * and there is no unique DB index on the (relationship_type, from, group)
 * triple, so it does not *guarantee* uniqueness — a race between concurrent
 * {@see self::addMember()}/{@see self::assignContent()} calls (or a
 * pre-existing hand-crafted row) can still leave more than one live row for
 * the same triple. The revoke path is written to tolerate that: it revokes
 * every matching live row, not just the first one found, so a duplicate
 * cannot leave the caller silently still-a-member after
 * {@see self::removeMember()}/{@see self::unassignContent()}.
 *
 * @api
 */
final class GroupMembershipService
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ?RelationshipTopologyReader $topologyReader = null,
        private readonly ?RelationshipMaintenanceReader $maintenanceReader = null,
    ) {}

    /**
     * @return list<string>
     */
    public function groupIdsForUser(int|string $uid): array
    {
        $repository = $this->relationshipRepository();
        $q = $repository->getQuery();
        // System-context membership lookup: caller passes a scalar uid, not
        // an AccountInterface, so there is no account to bind. Mirrors
        // GenealogyFamilyService's system-context branch.
        $q->accessCheck(false);
        $q->condition('relationship_type', GroupRelationshipTypes::MEMBERSHIP);
        $q->condition('from_entity_type', 'user');
        $q->condition('from_entity_id', (string) $uid);
        $q->condition('to_entity_type', 'group');
        // Only live rows count (see class docblock) — mirrors
        // RelationshipTraversalService's own 'status' filtering.
        $q->condition('status', 1);

        return $this->toGroupIds($repository, $q->execute());
    }

    /**
     * @return list<string>
     */
    public function groupIdsForContent(string $entityTypeId, int|string $entityId): array
    {
        $repository = $this->relationshipRepository();
        $q = $repository->getQuery();
        // System-context membership lookup: relationship topology only, no
        // account in scope. Mirrors GenealogyFamilyService's system-context
        // branch.
        $q->accessCheck(false);
        $q->condition('relationship_type', GroupRelationshipTypes::CONTENT);
        $q->condition('from_entity_type', $entityTypeId);
        $q->condition('from_entity_id', (string) $entityId);
        $q->condition('to_entity_type', 'group');
        // Only live rows count (see class docblock) — mirrors
        // RelationshipTraversalService's own 'status' filtering.
        $q->condition('status', 1);

        return $this->toGroupIds($repository, $q->execute());
    }

    /**
     * @param list<string> $groupIds
     */
    public function isMemberOfAny(int|string $uid, array $groupIds): bool
    {
        if ($groupIds === []) {
            return false;
        }

        return array_intersect($this->groupIdsForUser($uid), $groupIds) !== [];
    }

    /**
     * Add `$uid` as a member of `$groupId` (`user`/{uid} -> `group`/{gid},
     * `group_membership`). Idempotent and soft-revoke-aware: a live row is a
     * no-op, a soft-revoked row is reactivated in place, otherwise a new row
     * is created — never a duplicate. Throws when `$groupId` does not exist.
     *
     * @throws \InvalidArgumentException When the target group does not exist.
     */
    public function addMember(int|string $uid, string $groupId): void
    {
        $this->assertGroupExists($groupId);
        $this->upsertLiveRelationship(GroupRelationshipTypes::MEMBERSHIP, 'user', (string) $uid, $groupId);
    }

    /**
     * Soft-revoke `$uid`'s membership in `$groupId` (`status = 0`). Never
     * deletes the row. No-op when no live row exists.
     */
    public function removeMember(int|string $uid, string $groupId): void
    {
        $this->revokeRelationship(GroupRelationshipTypes::MEMBERSHIP, 'user', (string) $uid, $groupId);
    }

    /**
     * Assign content to `$groupId` (`{entityTypeId}`/{entityId} ->
     * `group`/{gid}, `group_content`). Same idempotent/soft-revoke-aware
     * shape as {@see self::addMember()}. Throws when `$groupId` does not
     * exist.
     *
     * @throws \InvalidArgumentException When the target group does not exist.
     */
    public function assignContent(string $entityTypeId, int|string $entityId, string $groupId): void
    {
        $this->assertGroupExists($groupId);
        $this->upsertLiveRelationship(GroupRelationshipTypes::CONTENT, $entityTypeId, (string) $entityId, $groupId);
    }

    /**
     * Soft-revoke content's assignment to `$groupId` (`status = 0`). Never
     * deletes the row. No-op when no live row exists.
     */
    public function unassignContent(string $entityTypeId, int|string $entityId, string $groupId): void
    {
        $this->revokeRelationship(GroupRelationshipTypes::CONTENT, $entityTypeId, (string) $entityId, $groupId);
    }

    private function relationshipRepository(): EntityRepositoryInterface
    {
        return $this->entityTypeManager->getRepository('relationship');
    }

    private function groupRepository(): EntityRepositoryInterface
    {
        return $this->entityTypeManager->getRepository('group');
    }

    private function assertGroupExists(string $groupId): void
    {
        if ($this->groupRepository()->find($groupId) === null) {
            throw new \InvalidArgumentException(sprintf('Group "%s" does not exist.', $groupId));
        }
    }

    /**
     * Find any existing relationship row for the exact
     * (relationshipType, fromType, fromId, group/$groupId) triple,
     * regardless of `status` — the caller decides what to do with a live vs.
     * soft-revoked row. Returns the row's `rid`, or null when none exists.
     */
    private function findExistingRelationshipId(
        string $relationshipType,
        string $fromEntityType,
        string $fromEntityId,
        string $groupId,
    ): ?string {
        $repository = $this->relationshipRepository();
        $q = $repository->getQuery();
        // System-context existence lookup for an idempotent write: no
        // account in scope (scalar ids only), and status is deliberately NOT
        // filtered here — the caller needs to see a soft-revoked row too, to
        // decide reactivate-vs-create.
        $q->accessCheck(false);
        $q->condition('relationship_type', $relationshipType);
        $q->condition('from_entity_type', $fromEntityType);
        $q->condition('from_entity_id', $fromEntityId);
        $q->condition('to_entity_type', 'group');
        $q->condition('to_entity_id', $groupId);
        $q->range(0, 1);
        $ids = $q->execute();

        return $ids === [] ? null : (string) $ids[0];
    }

    /**
     * Find ALL existing relationship rows for the exact
     * (relationshipType, fromType, fromId, group/$groupId) triple, regardless
     * of `status`. Unlike {@see self::findExistingRelationshipId()} this does
     * not cap the result to one row — the revoke path must be
     * duplicate-tolerant, because the find-then-create in
     * {@see self::upsertLiveRelationship()} is not atomic and there is no
     * unique DB index on this triple, so pre-existing hand-crafted rows or a
     * race between concurrent {@see self::addMember()} calls can leave more
     * than one live row behind.
     *
     * @return list<string>
     */
    private function findExistingRelationshipIds(
        string $relationshipType,
        string $fromEntityType,
        string $fromEntityId,
        string $groupId,
    ): array {
        $repository = $this->relationshipRepository();
        $q = $repository->getQuery();
        // System-context existence lookup: no account in scope (scalar ids
        // only), status deliberately NOT filtered — the revoke loop decides
        // per-row whether to flip status.
        $q->accessCheck(false);
        $q->condition('relationship_type', $relationshipType);
        $q->condition('from_entity_type', $fromEntityType);
        $q->condition('from_entity_id', $fromEntityId);
        $q->condition('to_entity_type', 'group');
        $q->condition('to_entity_id', $groupId);
        $ids = $q->execute();

        return array_values(array_map(static fn(mixed $id): string => (string) $id, $ids));
    }

    /**
     * Create-or-reactivate the relationship row for the given triple: a live
     * row is left alone, a soft-revoked row is flipped back to `status = 1`,
     * and a missing row is created live. This find-then-create is **not
     * atomic** and there is no unique DB index on this triple — a race
     * between concurrent calls can still produce a duplicate row (see
     * {@see self::revokeRelationship()}, which is duplicate-tolerant for
     * exactly this reason). Under single-threaded/typical usage this method
     * does not itself introduce a duplicate.
     */
    private function upsertLiveRelationship(
        string $relationshipType,
        string $fromEntityType,
        string $fromEntityId,
        string $groupId,
    ): void {
        $repository = $this->relationshipRepository();
        $existingId = $this->findExistingRelationshipId($relationshipType, $fromEntityType, $fromEntityId, $groupId);

        if ($existingId !== null) {
            $entity = $repository->find($existingId);
            if (!$entity instanceof Relationship) {
                return;
            }
            if ($this->maintenanceReader()->read($entity)->status === 1) {
                return;
            }
            $entity->set('status', 1);
            $repository->save($entity, validate: false);

            return;
        }

        $entity = $repository->create([
            'relationship_type' => $relationshipType,
            'from_entity_type' => $fromEntityType,
            'from_entity_id' => $fromEntityId,
            'to_entity_type' => 'group',
            'to_entity_id' => $groupId,
            'directionality' => 'directed',
            'status' => 1,
        ]);
        $repository->save($entity, validate: false);
    }

    /**
     * Soft-revoke (`status = 0`) EVERY live relationship row for the given
     * triple. No-op when no row exists. Never deletes any row.
     *
     * Duplicate-tolerant by design: unlike the upsert path, this does not
     * stop at the first matching row — it revokes ALL of them, so a
     * pre-existing duplicate (hand-crafted row, or a race between concurrent
     * {@see self::upsertLiveRelationship()} calls) cannot leave the caller
     * silently still-a-member/still-assigned via a second live row.
     */
    private function revokeRelationship(
        string $relationshipType,
        string $fromEntityType,
        string $fromEntityId,
        string $groupId,
    ): void {
        $repository = $this->relationshipRepository();
        $existingIds = $this->findExistingRelationshipIds($relationshipType, $fromEntityType, $fromEntityId, $groupId);

        foreach ($existingIds as $existingId) {
            $entity = $repository->find($existingId);
            if (!$entity instanceof Relationship) {
                continue;
            }
            if ($this->maintenanceReader()->read($entity)->status === 0) {
                continue;
            }

            $entity->set('status', 0);
            $repository->save($entity, validate: false);
        }
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private function toGroupIds(EntityRepositoryInterface $repository, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $groupIds = [];
        foreach ($repository->findMany($ids) as $entity) {
            if ($entity instanceof Relationship) {
                $groupIds[] = $this->topologyReader()->read($entity)->toId;
            }
        }

        return array_values(array_unique($groupIds));
    }

    private function topologyReader(): RelationshipTopologyReader
    {
        return $this->topologyReader ?? new RelationshipTopologyReader();
    }

    private function maintenanceReader(): RelationshipMaintenanceReader
    {
        return $this->maintenanceReader ?? new RelationshipMaintenanceReader();
    }
}

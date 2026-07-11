<?php

declare(strict_types=1);

namespace Waaseyaa\Groups\Membership;

use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Groups\GroupRelationshipTypes;
use Waaseyaa\Relationship\Relationship;

/**
 * Group (department) membership and content-group lookups, backed by
 * `relationship` rows (CW-v1 WP-3). Mirrors
 * {@see \Waaseyaa\Genealogy\Service\GenealogyFamilyService::memberPersonIds()}.
 *
 * Takes scalar identifiers (uid, entity id), not `AccountInterface`, so
 * `waaseyaa/groups` does not need to require `waaseyaa/access`.
 *
 * **Only live rows count.** Every query additionally filters on
 * `status = 1` (relationship liveness — an int column, schema default 1,
 * mirroring `RelationshipTraversalService`'s own `status` filtering): a
 * soft-revoked (`status = 0`) `group_membership` or `group_content` row is
 * never counted as membership or content-department assignment. Temporal
 * windows (`start_date`/`end_date` on the relationship row) are deliberately
 * NOT evaluated in v1 — that is out of scope here and left to a follow-up.
 *
 * @api
 */
final class GroupMembershipService
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
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

    private function relationshipRepository(): EntityRepositoryInterface
    {
        return $this->entityTypeManager->getRepository('relationship');
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
                $groupIds[] = (string) $entity->get('to_entity_id');
            }
        }

        return array_values(array_unique($groupIds));
    }
}

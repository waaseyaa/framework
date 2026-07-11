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

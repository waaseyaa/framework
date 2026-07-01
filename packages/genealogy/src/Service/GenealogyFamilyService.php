<?php

declare(strict_types=1);

namespace Waaseyaa\Genealogy\Service;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Genealogy\GenealogyRelationshipType;
use Waaseyaa\Relationship\Relationship;

/**
 * Household membership via `genealogy_member_of_family` edges.
 */
final class GenealogyFamilyService
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    /**
     * @return list<string>
     */
    public function memberPersonIds(string $familyId, ?AccountInterface $account = null): array
    {
        $storage = $this->relationshipStorage();
        // C-22 WP2: the account-filtered query surface now lives on the repository.
        $q = $this->relationshipRepository()->getQuery();
        if ($account !== null) {
            $q->setAccount($account);
        } else {
            // system context: caller did not thread an account; relationship topology only
            $q->accessCheck(false);
        }
        $q->condition('relationship_type', GenealogyRelationshipType::MEMBER_OF_FAMILY);
        $q->condition('to_entity_type', 'genealogy_family');
        $q->condition('to_entity_id', $familyId);
        $q->condition('from_entity_type', 'genealogy_person');

        $ids = $q->execute();
        if ($ids === []) {
            return [];
        }

        $people = [];
        foreach ($storage->loadMultiple($ids) as $entity) {
            if ($entity instanceof Relationship) {
                $people[] = (string) $entity->get('from_entity_id');
            }
        }

        return $this->uniqueSortedStringIds($people);
    }

    private function relationshipStorage(): EntityStorageInterface
    {
        return $this->entityTypeManager->getStorage('relationship');
    }

    private function relationshipRepository(): EntityRepositoryInterface
    {
        return $this->entityTypeManager->getRepository('relationship');
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private function uniqueSortedStringIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn(string $v): bool => $v !== '')));
        usort($ids, static function (string $a, string $b): int {
            if (is_numeric($a) && is_numeric($b)) {
                return (int) $a <=> (int) $b;
            }

            return strcmp($a, $b);
        });

        return $ids;
    }
}

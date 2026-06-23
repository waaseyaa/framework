<?php

declare(strict_types=1);

namespace Waaseyaa\Genealogy\Service;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Genealogy\Entity\GenealogyPerson;
use Waaseyaa\Genealogy\GenealogyLivingSemantics;
use Waaseyaa\Genealogy\GenealogyRelationshipType;
use Waaseyaa\Relationship\Relationship;

/**
 * Parent/child/spouse reads and simple ancestor layering over `relationship` rows.
 */
final class GenealogyPedigreeService
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    /**
     * @return list<string>
     */
    public function parentPersonIds(string $personId, ?AccountInterface $account = null): array
    {
        $storage = $this->relationshipStorage();
        $q = $storage->getQuery();
        if ($account !== null) {
            $q->setAccount($account);
        } else {
            // system context: caller did not thread an account; relationship topology only
            $q->accessCheck(false);
        }
        $q->condition('relationship_type', GenealogyRelationshipType::PARENT_OF);
        $q->condition('to_entity_type', 'genealogy_person');
        $q->condition('to_entity_id', $personId);
        $q->condition('from_entity_type', 'genealogy_person');

        return $this->sortedPersonIdsFromRelationships($storage, $q->execute(), static function (Relationship $r): string {
            return (string) $r->get('from_entity_id');
        });
    }

    /**
     * @return list<string>
     */
    public function childPersonIds(string $personId, ?AccountInterface $account = null): array
    {
        $storage = $this->relationshipStorage();
        $q = $storage->getQuery();
        if ($account !== null) {
            $q->setAccount($account);
        } else {
            // system context: caller did not thread an account; relationship topology only
            $q->accessCheck(false);
        }
        $q->condition('relationship_type', GenealogyRelationshipType::PARENT_OF);
        $q->condition('from_entity_type', 'genealogy_person');
        $q->condition('from_entity_id', $personId);
        $q->condition('to_entity_type', 'genealogy_person');

        return $this->sortedPersonIdsFromRelationships($storage, $q->execute(), static function (Relationship $r): string {
            return (string) $r->get('to_entity_id');
        });
    }

    /**
     * @return list<string>
     */
    public function spousePersonIds(string $personId, ?AccountInterface $account = null): array
    {
        $storage = $this->relationshipStorage();
        $ids = [];
        foreach ($this->edgesForSpouse($storage, $personId, $account) as $edge) {
            $other = $this->otherPersonId($edge, $personId);
            if ($other !== null) {
                $ids[] = $other;
            }
        }

        return $this->uniqueSortedStringIds($ids);
    }

    /**
     * Level 0 = subject; level N = ancestors N generations up (unordered within level).
     *
     * @return list<list<string>>
     */
    public function ancestorGenerations(string $personId, int $maxGenerations = 8, ?AccountInterface $account = null): array
    {
        $maxGenerations = max(1, $maxGenerations);
        $levels = [[$personId]];
        $seen = [$personId => true];

        for ($g = 1; $g <= $maxGenerations; ++$g) {
            $prev = $levels[$g - 1];
            $next = [];
            foreach ($prev as $pid) {
                foreach ($this->parentPersonIds($pid, $account) as $parentId) {
                    if (!isset($seen[$parentId])) {
                        $seen[$parentId] = true;
                        $next[] = $parentId;
                    }
                }
            }
            if ($next === []) {
                break;
            }
            $levels[] = $this->uniqueSortedStringIds($next);
        }

        return $levels;
    }

    /**
     * Neighbor list for SSR: never exposes numeric ids the viewer cannot load directly.
     *
     * @param list<string> $personIds
     * @return list<array{redacted: bool, label: string, id: ?string}>
     */
    public function neighborSlots(array $personIds, AccountInterface $account, GateInterface $gate): array
    {
        $slots = [];
        foreach ($personIds as $id) {
            $person = $this->loadPerson($id);
            if ($person === null) {
                continue;
            }
            if ($gate->allows('view', $person, $account)) {
                $slots[] = [
                    'redacted' => false,
                    'label' => $person->label(),
                    'id' => (string) $person->id(),
                ];

                continue;
            }

            $slots[] = [
                'redacted' => true,
                'label' => GenealogyLivingSemantics::effectiveIsLiving($person)
                    ? 'Private living relative'
                    : 'Private ancestor',
                'id' => null,
            ];
        }

        return $slots;
    }

    /**
     * @return list<list<array{redacted: bool, label: string, id: ?string}>>
     */
    public function ancestorGenerationsRedacted(
        string $personId,
        AccountInterface $account,
        GateInterface $gate,
        int $maxGenerations = 8,
    ): array {
        // Gather the ancestor TOPOLOGY in system context (null account ⇒
        // accessCheck(false)); concealment is applied per-person below via the
        // gate. Threading the account here would, under deny-by-default (audit
        // C-6) with the wired relationship policy, drop every edge touching a
        // non-viewable ancestor — collapsing the redacted-placeholder rows the
        // SSR chart is meant to show into nothing.
        $levels = $this->ancestorGenerations($personId, $maxGenerations, null);
        $out = [];
        foreach ($levels as $i => $idsAtLevel) {
            if ($i === 0) {
                $subject = $this->loadPerson($idsAtLevel[0] ?? $personId);
                if ($subject !== null && $gate->allows('view', $subject, $account)) {
                    $out[] = [[
                        'redacted' => false,
                        'label' => $subject->label(),
                        'id' => (string) $subject->id(),
                    ]];
                } else {
                    $out[] = [[
                        'redacted' => true,
                        'label' => 'Private profile',
                        'id' => null,
                    ]];
                }

                continue;
            }

            $out[] = $this->neighborSlots($idsAtLevel, $account, $gate);
        }

        return $out;
    }

    public function loadPerson(string $id): ?GenealogyPerson
    {
        $entity = $this->entityTypeManager->getStorage('genealogy_person')->load($id);

        return $entity instanceof GenealogyPerson ? $entity : null;
    }

    private function relationshipStorage(): EntityStorageInterface
    {
        return $this->entityTypeManager->getStorage('relationship');
    }

    /**
     * @param list<int|string> $relationshipIds
     * @param callable(Relationship): string $extractPersonId
     * @return list<string>
     */
    private function sortedPersonIdsFromRelationships(
        EntityStorageInterface $storage,
        array $relationshipIds,
        callable $extractPersonId,
    ): array {
        if ($relationshipIds === []) {
            return [];
        }

        $entities = $storage->loadMultiple($relationshipIds);
        $ids = [];
        foreach ($entities as $entity) {
            if ($entity instanceof Relationship) {
                $ids[] = $extractPersonId($entity);
            }
        }

        return $this->uniqueSortedStringIds($ids);
    }

    /**
     * @return iterable<Relationship>
     */
    private function edgesForSpouse(EntityStorageInterface $storage, string $personId, ?AccountInterface $account = null): iterable
    {
        foreach (['from_entity_id', 'to_entity_id'] as $field) {
            $q = $storage->getQuery();
            if ($account !== null) {
                $q->setAccount($account);
            } else {
                // system context: caller did not thread an account; relationship topology only
                $q->accessCheck(false);
            }
            $q->condition('relationship_type', GenealogyRelationshipType::SPOUSE_OF);
            $q->condition('from_entity_type', 'genealogy_person');
            $q->condition('to_entity_type', 'genealogy_person');
            $q->condition($field, $personId);
            $ids = $q->execute();
            if ($ids === []) {
                continue;
            }
            foreach ($storage->loadMultiple($ids) as $rel) {
                if ($rel instanceof Relationship) {
                    yield $rel;
                }
            }
        }
    }

    private function otherPersonId(Relationship $edge, string $personId): ?string
    {
        $from = (string) $edge->get('from_entity_id');
        $to = (string) $edge->get('to_entity_id');
        if ($from === $personId) {
            return $to;
        }
        if ($to === $personId) {
            return $from;
        }

        return null;
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

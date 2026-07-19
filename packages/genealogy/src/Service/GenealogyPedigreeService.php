<?php

declare(strict_types=1);

namespace Waaseyaa\Genealogy\Service;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Genealogy\Entity\GenealogyPerson;
use Waaseyaa\Genealogy\GenealogyRelationshipType;
use Waaseyaa\Relationship\Relationship;
use Waaseyaa\Relationship\RelationshipTopology;
use Waaseyaa\Relationship\RelationshipTopologyReader;

/**
 * Parent/child/spouse reads and simple ancestor layering over `relationship` rows.
 */
final class GenealogyPedigreeService
{
    /**
     * Uniform placeholder for a redacted neighbor slot (genealogy m-a, m2). One
     * label for both living and deceased concealed relatives, so the slot does
     * not leak the concealed person's living/deceased status.
     */
    private const string REDACTED_RELATIVE_LABEL = 'Private relative';

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        // Nullable/defensive (mirrors SsrPageHandler): when unwired, label
        // emission fails closed to the redacted placeholder rather than
        // falling back to the raw, field-access-unfiltered label.
        private readonly ?EntityAccessHandler $accessHandler = null,
        private readonly RelationshipTopologyReader $topologyReader = new RelationshipTopologyReader(),
    ) {}

    /**
     * @return list<string>
     */
    public function parentPersonIds(string $personId, ?AccountInterface $account = null): array
    {
        // C-22 WP2/WP3: both the query surface and the read path now live on the repository.
        $repository = $this->relationshipRepository();
        $q = $repository->getQuery();
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

        return $this->sortedPersonIdsFromRelationships($repository, $q->execute(), static function (RelationshipTopology $topology): string {
            return $topology->fromId;
        });
    }

    /**
     * @return list<string>
     */
    public function childPersonIds(string $personId, ?AccountInterface $account = null): array
    {
        // C-22 WP2/WP3: both the query surface and the read path now live on the repository.
        $repository = $this->relationshipRepository();
        $q = $repository->getQuery();
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

        return $this->sortedPersonIdsFromRelationships($repository, $q->execute(), static function (RelationshipTopology $topology): string {
            return $topology->toId;
        });
    }

    /**
     * @return list<string>
     */
    public function spousePersonIds(string $personId, ?AccountInterface $account = null): array
    {
        $repository = $this->relationshipRepository();
        $ids = [];
        foreach ($this->edgesForSpouse($repository, $personId, $account) as $edge) {
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
     * R8 WP3 (defense-in-depth, closes the R7 WP1 label channel here too): the
     * `label` emitted here (and in {@see ancestorGenerationsRedacted()}) on the
     * anonymous-reachable public pedigree pages is gated at the entity level
     * (`$gate->allows('view', …)`) AND, additionally, at the label field level
     * via {@see EntityAccessHandler::viewableLabel()} — a person who is
     * entity-viewable but whose label-key field is field-access-Forbidden (or
     * when no access handler is wired) is emitted as a redacted placeholder
     * slot, matching the existing entity-level-redaction shape, rather than
     * leaking the raw label. This was NOT live exploitable before this fix:
     * the wired `GenealogyContentAccessPolicy::fieldAccess()` always returns
     * Neutral, so there was no entity-viewable-but-label-restricted split to
     * exploit — see CHANGELOG R7 WP1 "Tracked-for-R8 residuals (R8-b)".
     *
     * @param list<string> $personIds
     * @param \Waaseyaa\Access\AuthorizationPrincipalInterface $account
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
                $label = $this->accessHandler?->viewableLabel($person, $account, $this->entityTypeManager);
                if ($label !== null && $label !== '') {
                    $slots[] = [
                        'redacted' => false,
                        'label' => $label,
                        'id' => (string) $person->id(),
                    ];

                    continue;
                }
                // Label field is Forbidden (or no access handler is wired):
                // fail closed to the same placeholder shape a fully-concealed
                // slot uses — never the raw label.
            }

            // genealogy m-a residual (m2): a single uniform placeholder for
            // every redacted slot. The prior 'Private living relative' vs
            // 'Private ancestor' split leaked the concealed person's
            // living/deceased status — the very axis the concealment protects —
            // as a one-bit side channel on an already-redacted slot.
            $slots[] = [
                'redacted' => true,
                'label' => self::REDACTED_RELATIVE_LABEL,
                'id' => null,
            ];
        }

        return $slots;
    }

    /**
     * @param \Waaseyaa\Access\AuthorizationPrincipalInterface $account
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
                $label = $subject !== null && $gate->allows('view', $subject, $account)
                    ? $this->accessHandler?->viewableLabel($subject, $account, $this->entityTypeManager)
                    : null;
                if ($subject !== null && $label !== null && $label !== '') {
                    $out[] = [[
                        'redacted' => false,
                        'label' => $label,
                        'id' => (string) $subject->id(),
                    ]];
                } else {
                    // Entity-level denied, OR entity-viewable but the label
                    // field is Forbidden (or no access handler is wired): fail
                    // closed to the same "Private profile" placeholder either way.
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
        // C-22 WP3: read path now goes through the canonical repository.
        $entity = $this->entityTypeManager->getRepository('genealogy_person')->find($id);

        return $entity instanceof GenealogyPerson ? $entity : null;
    }

    private function relationshipRepository(): EntityRepositoryInterface
    {
        return $this->entityTypeManager->getRepository('relationship');
    }

    /**
     * @param list<int|string> $relationshipIds
     * @param callable(RelationshipTopology): string $extractPersonId
     * @return list<string>
     */
    private function sortedPersonIdsFromRelationships(
        EntityRepositoryInterface $repository,
        array $relationshipIds,
        callable $extractPersonId,
    ): array {
        if ($relationshipIds === []) {
            return [];
        }

        $entities = $repository->findMany(array_map(strval(...), $relationshipIds));
        $ids = [];
        foreach ($entities as $entity) {
            if ($entity instanceof Relationship) {
                $topology = $this->topologyReader->read($entity);
                $ids[] = $extractPersonId($topology);
            }
        }

        return $this->uniqueSortedStringIds($ids);
    }

    /**
     * @return iterable<Relationship>
     */
    private function edgesForSpouse(EntityRepositoryInterface $repository, string $personId, ?AccountInterface $account = null): iterable
    {
        foreach (['from_entity_id', 'to_entity_id'] as $field) {
            // C-22 WP2: the account-filtered query surface now lives on the repository.
            $q = $repository->getQuery();
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
            foreach ($repository->findMany(array_map(strval(...), $ids)) as $rel) {
                if ($rel instanceof Relationship) {
                    yield $rel;
                }
            }
        }
    }

    private function otherPersonId(Relationship $edge, string $personId): ?string
    {
        $topology = $this->topologyReader->read($edge);
        $from = $topology->fromId;
        $to = $topology->toId;
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

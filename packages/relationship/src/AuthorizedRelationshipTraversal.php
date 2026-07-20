<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\ConsistentReadDatabaseInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Principal-scoped consumer facade over Protected relationship topology.
 *
 * Consumers pass domain traversal options, never field names or privileged
 * capability handles. The framework performs its fixed-shape topology query,
 * establishes the principal's ordinary field-read scope, and returns only
 * live edges whose source, relationship record, and related endpoint are all
 * viewable by that principal.
 *
 * @api
 */
final class AuthorizedRelationshipTraversal
{
    private const string MEMBERSHIP_TYPE = 'group_membership';

    /** @var \Closure(EntityBase): array{status?: mixed, name?: mixed} */
    private readonly \Closure $userDirectoryAuthority;

    /** @var (\Closure(): int)|null */
    private readonly ?\Closure $clock;

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly DatabaseInterface $database,
        private readonly EntityAccessHandler $accessHandler,
        private readonly AccountFieldReadScopeInterface $fieldReadScope,
        ?\Closure $clock = null,
    ) {
        $userAuthority = \Closure::bind(
            static fn(EntityBase $entity): array => $entity->valueContainer->rawProjection(['status', 'name']),
            null,
            EntityBase::class,
        );
        $this->userDirectoryAuthority = $userAuthority;
        $this->clock = $clock;
    }

    /**
     * Return the fixed directory projection for one exact group.
     *
     * Generic group view selects the existing broad authorization path. When
     * generic view is not allowed, the separate scoped path requires the exact
     * group's strict opt-in and a live direct membership row for the immutable
     * principal. No relationship options or endpoint fields are caller-selected.
     *
     * @return list<MemberDirectoryEntry>
     */
    public function memberDirectory(
        AuthorizationPrincipalInterface $principal,
        int|string $groupId,
    ): array {
        $groupId = (string) $groupId;
        if ($groupId === ''
            || !$this->database instanceof ConsistentReadDatabaseInterface
            || !$this->entityTypeManager->hasDefinition('group')
        ) {
            return [];
        }

        $evaluationTime = ($this->clock ?? static fn(): int => time())();
        $transaction = null;
        try {
            $transaction = $this->database->consistentReadTransaction('authorized-member-directory');
            $group = $this->entityTypeManager->getRepository('group')->find($groupId);
            if (!$group instanceof EntityBase) {
                $transaction->commit();

                return [];
            }

            $entries = $this->accessHandler->check($group, 'view', $principal)->isAllowed()
                ? $this->broadMemberDirectory($principal, $groupId, $evaluationTime)
                : $this->scopedMemberDirectory($principal, $groupId, $evaluationTime);
            $transaction->commit();

            return $entries;
        } catch (\Throwable) {
            if ($transaction !== null) {
                try {
                    $transaction->rollBack();
                } catch (\Throwable) {
                    // The read operation remains fail-closed if rollback is
                    // unavailable after an underlying transaction failure.
                }
            }

            return [];
        }
    }

    /**
     * @param array{
     *   direction?: 'outbound'|'inbound'|'both',
     *   relationship_types?: list<string>,
     *   at?: int|string|null,
     *   limit?: int|null
     * } $options
     * @return list<AuthorizedRelationshipEdge>
     */
    public function edges(
        AuthorizationPrincipalInterface $principal,
        string $sourceEntityType,
        int|string $sourceEntityId,
        array $options = [],
    ): array {
        return $this->fieldReadScope->run(
            $principal,
            fn(): array => $this->edgesInScope($principal, $sourceEntityType, (string) $sourceEntityId, $options),
        );
    }

    /**
     * @param array{
     *   direction?: 'outbound'|'inbound'|'both',
     *   relationship_types?: list<string>,
     *   at?: int|string|null,
     *   limit?: int|null
     * } $options
     * @return list<AuthorizedRelationshipEdge>
     */
    private function edgesInScope(
        AuthorizationPrincipalInterface $principal,
        string $sourceEntityType,
        string $sourceEntityId,
        array $options,
    ): array {
        if ($sourceEntityId === '' || !$this->entityTypeManager->hasDefinition($sourceEntityType)) {
            return [];
        }

        $source = $this->entityTypeManager->getRepository($sourceEntityType)->find($sourceEntityId);
        if ($source === null || !$this->accessHandler->check($source, 'view', $principal)->isAllowed()) {
            return [];
        }

        // `status: all` disables the publication-only visibility filter inside
        // the lower-level service; this facade replaces it with the stronger
        // per-principal endpoint gate wired below, then admits active edges
        // only. Callers cannot request the `all` bypass themselves.
        $browse = new RelationshipTraversalService(
            $this->entityTypeManager,
            $this->database,
            accessHandler: $this->accessHandler,
            account: $principal,
        )->browse($sourceEntityType, $sourceEntityId, [
            'relationship_types' => $options['relationship_types'] ?? [],
            'status' => 'all',
            'at' => $options['at'] ?? null,
            // Apply the consumer limit only after inactive and inaccessible
            // relationship records have been removed.
            'limit' => null,
        ]);

        $direction = $this->normalizeDirection($options['direction'] ?? 'both');
        $candidates = match ($direction) {
            'outbound' => $browse['outbound'],
            'inbound' => $browse['inbound'],
            default => [...$browse['outbound'], ...$browse['inbound']],
        };
        if ($candidates === []) {
            return [];
        }

        $relationshipIds = array_values(array_unique(array_map(
            static fn(array $edge): string => $edge['relationship_id'],
            $candidates,
        )));
        $relationships = [];
        foreach ($this->entityTypeManager->getRepository('relationship')->findMany($relationshipIds) as $relationship) {
            $relationships[(string) $relationship->id()] = $relationship;
        }

        $result = [];
        foreach ($candidates as $edge) {
            if ($edge['status'] !== 1) {
                continue;
            }

            $relationship = $relationships[$edge['relationship_id']] ?? null;
            if ($relationship === null || !$this->accessHandler->check($relationship, 'view', $principal)->isAllowed()) {
                continue;
            }

            $result[] = new AuthorizedRelationshipEdge(
                relationshipId: $edge['relationship_id'],
                relationshipType: $edge['relationship_type'],
                direction: $edge['direction'],
                inverse: $edge['inverse'],
                relatedEntityType: $edge['related_entity_type'],
                relatedEntityId: $edge['related_entity_id'],
                relatedEntityLabel: $edge['related_entity_label'],
                relatedEntityPath: $edge['related_entity_path'],
                directionality: $edge['directionality'],
                weight: is_float($edge['weight']) ? $edge['weight'] : null,
                confidence: is_float($edge['confidence']) ? $edge['confidence'] : null,
                startDate: is_int($edge['start_date']) ? $edge['start_date'] : null,
                endDate: is_int($edge['end_date']) ? $edge['end_date'] : null,
            );
        }

        $limit = $this->normalizeLimit($options['limit'] ?? null);

        return $limit === null ? $result : array_slice($result, 0, $limit);
    }

    /** @return 'outbound'|'inbound'|'both' */
    private function normalizeDirection(mixed $direction): string
    {
        return is_string($direction) && in_array($direction, ['outbound', 'inbound', 'both'], true)
            ? $direction
            : 'both';
    }

    private function normalizeLimit(mixed $limit): ?int
    {
        if (!is_int($limit) || $limit < 1) {
            return null;
        }

        return $limit;
    }

    /** @return list<MemberDirectoryEntry> */
    private function broadMemberDirectory(
        AuthorizationPrincipalInterface $principal,
        string $groupId,
        int $evaluationTime,
    ): array {
        $authority = $this->directoryAuthorityRowSet($groupId);
        $userIds = [];
        $relationshipRepository = $this->entityTypeManager->getRepository('relationship');
        $userRepository = $this->entityTypeManager->getRepository('user');
        foreach ($authority['memberships'] as $values) {
            if (!$this->isActiveMembership($values, $evaluationTime)) {
                continue;
            }
            $relationshipId = $values['relationship_id'];
            $userId = $values['from_entity_id'];
            if ((!is_int($relationshipId) && !is_string($relationshipId))
                || (!is_int($userId) && !is_string($userId))
            ) {
                continue;
            }
            $relationship = $relationshipRepository->find((string) $relationshipId);
            $user = $userRepository->find((string) $userId);
            if ($relationship === null
                || $user === null
                || !$this->accessHandler->check($relationship, 'view', $principal)->isAllowed()
                || !$this->accessHandler->check($user, 'view', $principal)->isAllowed()
            ) {
                continue;
            }
            $userIds[] = (string) $userId;
        }

        return $this->projectActiveUsers($userIds);
    }

    /** @return list<MemberDirectoryEntry> */
    private function scopedMemberDirectory(
        AuthorizationPrincipalInterface $principal,
        string $groupId,
        int $evaluationTime,
    ): array {
        if (!$principal->isAuthenticated()) {
            return [];
        }

        $authority = $this->directoryAuthorityRowSet($groupId);
        if (!$authority['optedIn'] || !$authority['groupActive']) {
            return [];
        }

        $activeMemberIds = [];
        $claimantId = (string) $principal->id();
        $claimantPresent = false;

        foreach ($authority['memberships'] as $values) {
            $fromType = $values['from_entity_type'] ?? null;
            $fromId = $values['from_entity_id'] ?? null;
            $toType = $values['to_entity_type'] ?? null;
            $toId = $values['to_entity_id'] ?? null;
            if ($fromType !== 'user'
                || (!is_int($fromId) && !is_string($fromId))
                || $toType !== 'group'
                || (!is_int($toId) && !is_string($toId))
                || (string) $toId !== $groupId
                || !$this->isActiveMembership($values, $evaluationTime)
            ) {
                continue;
            }

            $memberId = (string) $fromId;
            $activeMemberIds[$memberId] = true;
            if ($memberId === $claimantId) {
                $claimantPresent = true;
            }
        }

        if (!$claimantPresent) {
            return [];
        }

        return $this->projectActiveUsers(array_keys($activeMemberIds));
    }

    /**
     * Materialize the complete authority row set in one database statement.
     *
     * @return array{
     *   groupActive: bool,
     *   optedIn: bool,
     *   memberships: list<array{
     *     relationship_id: mixed,
     *     from_entity_type: mixed,
     *     from_entity_id: mixed,
     *     to_entity_type: mixed,
     *     to_entity_id: mixed,
     *     status: mixed,
     *     start_date: mixed,
     *     end_date: mixed
     *   }>
     * }
     */
    private function directoryAuthorityRowSet(string $groupId): array
    {
        $usesDataBlob = $this->database->schema()->fieldExists('relationship', '_data');
        $query = $this->database->select('group', 'g')
            ->addField('g', '_data', 'group_data')
            ->addField('r', 'rid', 'relationship_id')
            ->join('relationship', 'r', '1 = 1')
            ->condition('g.gid', $groupId)
            ->condition('r.relationship_type', self::MEMBERSHIP_TYPE);
        if ($usesDataBlob) {
            $query = $query->addField('r', '_data', 'relationship_data');
        } else {
            foreach ([
                'from_entity_type',
                'from_entity_id',
                'to_entity_type',
                'to_entity_id',
                'directionality',
                'status',
                'start_date',
                'end_date',
            ] as $field) {
                $query = $query->addField('r', $field, $field);
            }
        }
        $rows = $query->execute();

        $memberships = [];
        $groupActive = false;
        $optedIn = false;
        foreach ($rows as $row) {
            if (!is_array($row)
                || !isset($row['group_data'])
                || !is_string($row['group_data'])
            ) {
                continue;
            }
            try {
                $groupData = json_decode($row['group_data'], associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
                $data = $usesDataBlob && isset($row['relationship_data']) && is_string($row['relationship_data'])
                    ? json_decode($row['relationship_data'], associative: true, depth: 512, flags: JSON_THROW_ON_ERROR)
                    : $row;
            } catch (\JsonException) {
                continue;
            }
            if (is_array($groupData)) {
                $groupActive = $this->isActiveStatus($groupData['status'] ?? null);
                $optedIn = ($groupData['members_can_view_directory'] ?? null) === true;
            }
            if (!is_array($data)
                || ($data['from_entity_type'] ?? null) !== 'user'
                || ($data['to_entity_type'] ?? null) !== 'group'
                || ($data['directionality'] ?? null) !== 'directed'
                || (!is_int($data['to_entity_id'] ?? null) && !is_string($data['to_entity_id'] ?? null))
                || (string) $data['to_entity_id'] !== $groupId
            ) {
                continue;
            }

            $memberships[] = [
                'relationship_id' => $row['relationship_id'] ?? null,
                'from_entity_type' => $data['from_entity_type'],
                'from_entity_id' => $data['from_entity_id'] ?? null,
                'to_entity_type' => $data['to_entity_type'],
                'to_entity_id' => $data['to_entity_id'],
                'status' => $data['status'] ?? null,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
            ];
        }

        return [
            'groupActive' => $groupActive,
            'optedIn' => $optedIn,
            'memberships' => $memberships,
        ];
    }

    /**
     * @param array{status?: mixed, start_date?: mixed, end_date?: mixed} $values
     */
    private function isActiveMembership(array $values, int $evaluationTime): bool
    {
        if (!$this->isActiveStatus($values['status'] ?? null)) {
            return false;
        }

        $startValue = $values['start_date'] ?? null;
        $endValue = $values['end_date'] ?? null;
        if ((!is_int($startValue) && !is_string($startValue) && $startValue !== null)
            || (!is_int($endValue) && !is_string($endValue) && $endValue !== null)
        ) {
            return false;
        }
        $start = $this->canonicalTimestamp($startValue);
        $end = $this->canonicalTimestamp($endValue);
        if (($startValue !== null && $start === null) || ($endValue !== null && $end === null)) {
            return false;
        }

        return ($start === null || $start <= $evaluationTime)
            && ($end === null || $evaluationTime <= $end);
    }

    private function canonicalTimestamp(int|string|null $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if ($value === null || preg_match('/^(?:0|-?[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }

        $timestamp = filter_var($value, FILTER_VALIDATE_INT);

        return $timestamp === false ? null : $timestamp;
    }

    /** @param list<string> $userIds @return list<MemberDirectoryEntry> */
    private function projectActiveUsers(array $userIds): array
    {
        if ($userIds === [] || !$this->entityTypeManager->hasDefinition('user')) {
            return [];
        }

        $entries = [];
        foreach ($this->entityTypeManager->getRepository('user')->findMany($userIds) as $user) {
            if (!$user instanceof EntityBase || $user->getEntityTypeId() !== 'user') {
                continue;
            }
            $values = ($this->userDirectoryAuthority)($user);
            $displayName = $values['name'] ?? null;
            if (($values['status'] ?? null) !== true || !is_string($displayName) || trim($displayName) === '') {
                continue;
            }

            $entries[(string) $user->id()] = new MemberDirectoryEntry((string) $user->id(), $displayName);
        }
        ksort($entries, SORT_NATURAL);

        return array_values($entries);
    }

    private function isActiveStatus(mixed $status): bool
    {
        return $status === true || $status === 1 || $status === '1';
    }
}

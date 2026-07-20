<?php

declare(strict_types=1);

namespace Waaseyaa\Groups\StaffDirectory;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\ContextualProtectedEntityReadPolicyInterface;
use Waaseyaa\Access\ContextualProtectedReadEvaluation;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityStructure;

/** Fixed-shape roster-membership authority for capability-scoped User reads. */
final class CapabilityScopedStaffDirectoryReadPolicy implements ContextualProtectedEntityReadPolicyInterface
{
    private const string MEMBERSHIP_TYPE = 'group_membership';

    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly StaffDirectoryReadDeclaration $declaration,
    ) {}

    public function authorizationBoundary(): object
    {
        return $this->database;
    }

    public function contextKey(): string
    {
        return self::contextKeyFor($this->declaration);
    }

    public static function contextKeyFor(StaffDirectoryReadDeclaration $declaration): string
    {
        return 'groups.staff-directory.' . hash(
            'sha256',
            $declaration->capability . "\0" . $declaration->rosterGroupBundle,
        );
    }

    public function accessBatch(
        AuthorizationPrincipalInterface $principal,
        array $candidates,
        ContextualProtectedReadEvaluation $evaluation,
        string $operation,
    ): array {
        $results = [];
        if ($operation !== 'view' || !$principal->isAuthenticated()
            || !$principal->hasPermission($this->declaration->capability)
        ) {
            foreach ($candidates as $candidate) {
                $results[$candidate->key] = AccessResult::neutral('The declared staff-directory capability is absent.');
            }

            return $results;
        }
        if ($evaluation->authorizationBoundary !== $this->database) {
            foreach ($candidates as $candidate) {
                $results[$candidate->key] = AccessResult::forbidden('Staff-directory authority boundary mismatch.');
            }

            return $results;
        }

        $groupId = $this->resolveExactActiveRosterGroup();
        if ($groupId === null) {
            foreach ($candidates as $candidate) {
                $results[$candidate->key] = AccessResult::forbidden('The declared roster group is absent, inactive, or ambiguous.');
            }

            return $results;
        }
        $memberIds = $this->liveMemberIds($groupId, $evaluation->evaluatedAt);
        foreach ($candidates as $candidate) {
            $results[$candidate->key] = $candidate->structure->entityTypeId === 'user'
                && isset($memberIds[(string) $candidate->structure->id])
                ? AccessResult::allowed('The principal holds the declared staff-directory capability for this roster member.')
                : AccessResult::neutral('The User is not a live direct member of the declared roster.');
        }

        return $results;
    }

    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $operation,
    ): AccessResult {
        return AccessResult::neutral('Staff directory reads require consistent-snapshot batch evaluation.');
    }

    private function resolveExactActiveRosterGroup(): ?string
    {
        $query = $this->database->select('group', 'g')
            ->addField('g', 'gid', 'gid')
            ->addField('g', '_data', 'data')
            ->condition('g.type', $this->declaration->rosterGroupBundle);
        $matches = 0;
        $activeId = null;
        foreach ($query->execute() as $row) {
            ++$matches;
            if (!is_array($row) || !isset($row['gid'], $row['data']) || !is_string($row['data'])) {
                continue;
            }
            try {
                $data = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (is_array($data) && (($data['status'] ?? null) === 1 || ($data['status'] ?? null) === true)) {
                $activeId = (string) $row['gid'];
            }
        }

        return $matches === 1 ? $activeId : null;
    }

    /** @return array<string, true> */
    private function liveMemberIds(string $groupId, int $evaluatedAt): array
    {
        $usesDataBlob = $this->database->schema()->fieldExists('relationship', '_data');
        $query = $this->database->select('relationship', 'r')
            ->addField('r', 'rid', 'rid')
            ->condition('r.relationship_type', self::MEMBERSHIP_TYPE);
        if ($usesDataBlob) {
            $query = $query->addField('r', '_data', 'data');
        } else {
            foreach (['from_entity_type', 'from_entity_id', 'to_entity_type', 'to_entity_id', 'directionality', 'status', 'start_date', 'end_date'] as $field) {
                $query = $query->addField('r', $field, $field);
            }
        }

        $memberIds = [];
        foreach ($query->execute() as $row) {
            if (!is_array($row)) {
                continue;
            }
            try {
                $data = $usesDataBlob && isset($row['data']) && is_string($row['data'])
                    ? json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR)
                    : $row;
            } catch (\JsonException) {
                continue;
            }
            if (!is_array($data)
                || ($data['from_entity_type'] ?? null) !== 'user'
                || ($data['to_entity_type'] ?? null) !== 'group'
                || ($data['directionality'] ?? null) !== 'directed'
                || (string) ($data['to_entity_id'] ?? '') !== $groupId
                || !$this->isLive($data, $evaluatedAt)
            ) {
                continue;
            }
            $memberId = $data['from_entity_id'] ?? null;
            if (is_int($memberId) || (is_string($memberId) && $memberId !== '')) {
                $memberIds[(string) $memberId] = true;
            }
        }

        return $memberIds;
    }

    /** @param array<string, mixed> $values */
    private function isLive(array $values, int $evaluatedAt): bool
    {
        if (($values['status'] ?? null) !== 1 && ($values['status'] ?? null) !== true) {
            return false;
        }
        $start = $this->timestamp($values['start_date'] ?? null);
        $end = $this->timestamp($values['end_date'] ?? null);
        if ($start === false || $end === false) {
            return false;
        }

        return ($start === null || $start <= $evaluatedAt) && ($end === null || $end >= $evaluatedAt);
    }

    private function timestamp(mixed $value): int|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?(0|[1-9][0-9]*)$/D', $value) === 1) {
            return (int) $value;
        }

        return false;
    }
}

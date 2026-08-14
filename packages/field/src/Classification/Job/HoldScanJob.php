<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Classification\Job;

use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Field\Classification\ClassificationSubjectReader;
use Waaseyaa\Field\Entity\RetentionPolicy;
use Waaseyaa\Field\Entity\RetentionPolicyMaintenanceReader;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Scheduler\Execution\LeaseExecutionContext;
use Waaseyaa\Scheduler\Lease\LeaseLostException;

/**
 * Verification-only scheduled job that surfaces hold-vs-purge
 * misconfigurations (FR-012).
 *
 * Hold-flagged data is blocked at access time by
 * {@see \Waaseyaa\Field\Classification\Policy\ClassificationFieldAccessPolicy},
 * never deleted at storage time (C-004). A policy author could nonetheless
 * attach both a `hold-*` label to an entity AND match that same entity in a
 * `purge` policy — `hold` must win, but the conflicting configuration is
 * worth surfacing for admin review.
 *
 * For each entity matched by ANY `hold-flag` policy that is ALSO matched by
 * ANY `purge` policy, this job writes a `classification.change` audit event
 * carrying `attributes.conflict = 'hold_vs_purge'` and logs a `notice`. It
 * never deletes or mutates data.
 *
 * Best-effort (NFR-004): wrapped so a scan failure never disrupts the
 * scheduler tick.
 *
 * @api
 */
final class HoldScanJob
{
    /** Entity types that are part of the classification machinery itself; never scanned. */
    private const array SELF_TYPES = ['retention_policy', 'classification_label_definition'];

    private readonly LoggerInterface $logger;
    private readonly RetentionScanner $scanner;
    private readonly RetentionPolicyMaintenanceReader $policyReader;
    private readonly ClassificationSubjectReader $subjectReader;

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly AuditWriterInterface $auditWriter,
        ?LoggerInterface $logger = null,
        ?RetentionScanner $scanner = null,
        ?RetentionPolicyMaintenanceReader $policyReader = null,
        ?ClassificationSubjectReader $subjectReader = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->scanner = $scanner ?? new RetentionScanner($entityTypeManager);
        $this->policyReader = $policyReader ?? new RetentionPolicyMaintenanceReader();
        $this->subjectReader = $subjectReader ?? new ClassificationSubjectReader();
    }

    public function run(?LeaseExecutionContext $lease = null): void
    {
        try {
            $holdPolicies = $this->loadPolicies(RetentionPolicy::ACTION_HOLD_FLAG);
            $purgePolicies = $this->loadPolicies(RetentionPolicy::ACTION_PURGE);

            if ($holdPolicies === [] || $purgePolicies === []) {
                return; // No possible conflict without at least one of each.
            }

            $conflicts = $this->scanConflicts($holdPolicies, $purgePolicies, $lease);
            $this->logger->info('classification.retention.hold_scan_complete', [
                'conflicts' => $conflicts,
            ]);
        } catch (\Throwable $e) {
            if ($e instanceof LeaseLostException) {
                throw $e;
            }
            // NFR-004: a scan failure must not disrupt the scheduler tick.
            $this->logger->warning('classification.retention.hold_scan_failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param list<RetentionPolicy> $holdPolicies
     * @param list<RetentionPolicy> $purgePolicies
     */
    private function scanConflicts(array $holdPolicies, array $purgePolicies, ?LeaseExecutionContext $lease): int
    {
        $conflicts = 0;

        foreach ($this->entityTypeManager->getDefinitions() as $entityTypeId => $definition) {
            if (in_array($entityTypeId, self::SELF_TYPES, true)) {
                continue;
            }

            foreach ($this->scanner->scan($entityTypeId, null, null) as $entity) {
                $lease?->checkpoint();
                $labelId = $this->subjectReader->read($entity)->label ?? '';
                if ($labelId === '') {
                    continue;
                }

                $holdPolicy = $this->firstMatch($holdPolicies, $labelId);
                $purgePolicy = $this->firstMatch($purgePolicies, $labelId);
                if ($holdPolicy === null || $purgePolicy === null) {
                    continue;
                }

                $uuid = (string) ($entity->get('uuid') ?? '');
                $effect = fn() => $this->recordConflict($entityTypeId, $uuid, $labelId, $holdPolicy, $purgePolicy);
                $executed = $lease?->effect(
                    sprintf('retention-conflict:%s:%s', $entityTypeId, (string) $entity->id()),
                    sprintf('hold-scan:%s:%s', (string) $holdPolicy->id(), (string) $purgePolicy->id()),
                    $effect,
                ) ?? (function () use ($effect): bool {
                    $effect();
                    return true;
                })();
                if ($executed) {
                    ++$conflicts;
                }
            }
        }

        return $conflicts;
    }

    /**
     * @param list<RetentionPolicy> $policies
     */
    private function firstMatch(array $policies, string $labelId): ?RetentionPolicy
    {
        foreach ($policies as $policy) {
            if ($this->policyReader->read($policy)->matchesLabel($labelId)) {
                return $policy;
            }
        }

        return null;
    }

    /**
     * @return list<RetentionPolicy>
     */
    private function loadPolicies(string $action): array
    {
        // Verification-only scan; no user account in scope. accessCheck(false)
        // is the intentional opt-out (CLAUDE.md §"Unbound getQuery() gate").
        // C-22 WP2/WP3: both the query surface and the read path now live on the repository.
        $repository = $this->entityTypeManager->getRepository('retention_policy');
        $ids = $repository->getQuery()
            ->accessCheck(false)
            ->condition('action', $action)
            ->execute();

        if ($ids === []) {
            return [];
        }

        return array_values(array_filter(
            $repository->findMany($ids),
            static fn(EntityInterface $e): bool => $e instanceof RetentionPolicy,
        ));
    }

    private function recordConflict(
        string $entityTypeId,
        string $uuid,
        string $labelId,
        RetentionPolicy $holdPolicy,
        RetentionPolicy $purgePolicy,
    ): void {
        $this->logger->notice('classification.retention.hold_vs_purge_conflict', [
            'entity_type' => $entityTypeId,
            'entity_uuid' => $uuid,
            'label_id' => $labelId,
            'hold_policy_id' => $holdPolicy->id(),
            'purge_policy_id' => $purgePolicy->id(),
        ]);

        $this->auditWriter->record(new AuditEventDescriptor(
            kind: AuditEventKind::ClassificationChange,
            accountUid: 0,
            subjectUri: sprintf('entity://%s/%s', $entityTypeId, $uuid),
            outcome: 'denied',
            severity: 'warning',
            entityTypeId: $entityTypeId,
            entityUuid: $uuid,
            attributes: [
                'conflict' => 'hold_vs_purge',
                'label_id' => $labelId,
                'hold_policy_id' => $holdPolicy->id(),
                'purge_policy_id' => $purgePolicy->id(),
            ],
        ));
    }
}

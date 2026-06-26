<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Classification\Job;

use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Field\Entity\RetentionPolicy;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

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

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly AuditWriterInterface $auditWriter,
        ?LoggerInterface $logger = null,
        ?RetentionScanner $scanner = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->scanner = $scanner ?? new RetentionScanner($entityTypeManager);
    }

    public function run(): void
    {
        try {
            $holdPolicies = $this->loadPolicies(RetentionPolicy::ACTION_HOLD_FLAG);
            $purgePolicies = $this->loadPolicies(RetentionPolicy::ACTION_PURGE);

            if ($holdPolicies === [] || $purgePolicies === []) {
                return; // No possible conflict without at least one of each.
            }

            $conflicts = $this->scanConflicts($holdPolicies, $purgePolicies);
            $this->logger->info('classification.retention.hold_scan_complete', [
                'conflicts' => $conflicts,
            ]);
        } catch (\Throwable $e) {
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
    private function scanConflicts(array $holdPolicies, array $purgePolicies): int
    {
        $conflicts = 0;

        foreach ($this->entityTypeManager->getDefinitions() as $entityTypeId => $definition) {
            if (in_array($entityTypeId, self::SELF_TYPES, true)) {
                continue;
            }

            foreach ($this->scanner->scan($entityTypeId, null, null) as $entity) {
                $labelId = (string) ($entity->get('classification_label') ?? '');
                if ($labelId === '') {
                    continue;
                }

                $holdPolicy = $this->firstMatch($holdPolicies, $labelId);
                $purgePolicy = $this->firstMatch($purgePolicies, $labelId);
                if ($holdPolicy === null || $purgePolicy === null) {
                    continue;
                }

                $uuid = (string) ($entity->get('uuid') ?? '');
                $this->recordConflict($entityTypeId, $uuid, $labelId, $holdPolicy, $purgePolicy);
                ++$conflicts;
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
            if ($policy->matchesLabel($labelId)) {
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
        $storage = $this->entityTypeManager->getStorage('retention_policy');
        // Verification-only scan; no user account in scope. accessCheck(false)
        // is the intentional opt-out (CLAUDE.md §"Unbound getQuery() gate").
        $ids = $storage->getQuery()
            ->accessCheck(false)
            ->condition('action', $action)
            ->execute();

        if ($ids === []) {
            return [];
        }

        return array_values(array_filter(
            $storage->loadMultiple($ids),
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

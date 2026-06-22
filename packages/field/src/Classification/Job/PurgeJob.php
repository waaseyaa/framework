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
 * Scheduled job that purges age-eligible entities matched by `action=purge`,
 * `trigger_kind=age_based` retention policies.
 *
 * For each such policy the job computes a cutoff (`now − trigger_value`,
 * an ISO-8601 duration) and deletes every classification-labelled entity
 * older than the cutoff whose label matches the policy's `applies_to`
 * patterns and which is not on the policy's exemption list. Deletion goes
 * through the entity repository so the audit substrate's
 * `EntityLifecycleAuditListener` records the `entity.delete` event; this job
 * then writes a `retention.purge` event documenting the policy that drove
 * the deletion.
 *
 * Best-effort (NFR-004): each policy is wrapped in its own try-catch so a
 * single failing policy never aborts the rest of the sweep.
 *
 * Hold semantics (C-004) are enforced at access time by
 * {@see \Waaseyaa\Field\Classification\Policy\ClassificationFieldAccessPolicy},
 * not here; a `hold-*` label is never a `purge` target by construction (hold
 * policies use `action=hold-flag`). The {@see HoldScanJob} surfaces any
 * misconfiguration where a held entity is also matched by a purge policy.
 *
 * @api
 */
final class PurgeJob
{
    /** Entity types that are part of the classification machinery itself; never purged. */
    private const array SELF_TYPES = ['retention_policy', 'classification_label_definition'];

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly AuditWriterInterface $auditWriter,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function run(): void
    {
        foreach ($this->loadPurgePolicies() as $policy) {
            try {
                $purged = $this->applyPolicy($policy);
                $this->logger->info('classification.retention.purge_complete', [
                    'policy_id' => $policy->id(),
                    'purged' => $purged,
                ]);
            } catch (\Throwable $e) {
                // NFR-004: one bad policy must not abort the whole sweep.
                $this->logger->warning('classification.retention.purge_failed', [
                    'policy_id' => $policy->id(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return list<RetentionPolicy>
     */
    private function loadPurgePolicies(): array
    {
        $storage = $this->entityTypeManager->getStorage('retention_policy');
        // Reading policy rows for a system sweep; no user account in scope.
        // accessCheck(false) is the intentional opt-out (CLAUDE.md §"Unbound getQuery() gate").
        $ids = $storage->getQuery()
            ->accessCheck(false)
            ->condition('action', RetentionPolicy::ACTION_PURGE)
            ->condition('trigger_kind', RetentionPolicy::TRIGGER_AGE_BASED)
            ->execute();

        if ($ids === []) {
            return [];
        }

        return array_values(array_filter(
            $storage->loadMultiple($ids),
            static fn(EntityInterface $e): bool => $e instanceof RetentionPolicy,
        ));
    }

    /**
     * Apply a single purge policy. Returns the number of entities deleted.
     */
    private function applyPolicy(RetentionPolicy $policy): int
    {
        $cutoff = $this->computeCutoff($policy->getTriggerValue());
        if ($cutoff === null) {
            $this->logger->warning('classification.retention.purge_bad_trigger', [
                'policy_id' => $policy->id(),
                'trigger_value' => $policy->getTriggerValue(),
            ]);

            return 0;
        }

        $cutoffString = $cutoff->format('Y-m-d H:i:s');
        $deleted = 0;

        foreach ($this->entityTypeManager->getDefinitions() as $entityTypeId => $definition) {
            if (in_array($entityTypeId, self::SELF_TYPES, true)) {
                continue;
            }

            foreach ($this->findAgeEligible($entityTypeId, $cutoffString) as $entity) {
                $labelId = (string) ($entity->get('classification_label') ?? '');
                if ($labelId === '' || !$policy->matchesLabel($labelId)) {
                    continue;
                }

                // Legal-hold guard (C-004): a `hold-*` label is never purgeable,
                // regardless of policy match. The class doc claims this holds
                // "by construction" because purge policies use action=purge while
                // holds use action=hold-flag — but a misconfigured purge policy
                // whose `applies_to` glob matches `hold-*` (e.g. `hold-*` or `*`)
                // would otherwise delete legal-hold content. Enforce it here so
                // the invariant cannot be configured away.
                if (str_starts_with($labelId, 'hold-')) {
                    continue;
                }

                $uuid = (string) ($entity->get('uuid') ?? '');
                if ($uuid !== '' && $policy->isExempt($entityTypeId, $uuid)) {
                    continue;
                }

                // storage->delete() dispatches POST_DELETE, which the audit
                // substrate's EntityLifecycleAuditListener records as entity.delete.
                $this->entityTypeManager->getStorage($entityTypeId)->delete([$entity]);
                $this->recordPurge($policy, $entityTypeId, $uuid, $labelId);
                ++$deleted;
            }
        }

        return $deleted;
    }

    /**
     * Query a single entity type for classification-labelled entities older
     * than the cutoff. Returns an empty list (rather than throwing) for types
     * that do not carry the `classification_label` field.
     *
     * @return list<EntityInterface>
     */
    private function findAgeEligible(string $entityTypeId, string $cutoffString): array
    {
        try {
            $storage = $this->entityTypeManager->getStorage($entityTypeId);
            // System retention sweep: no user account in scope. accessCheck(false)
            // is the intentional opt-out (see CLAUDE.md §"Unbound getQuery() gate").
            $ids = $storage->getQuery()
                ->accessCheck(false)
                ->exists('classification_label')
                ->condition('created_at', $cutoffString, '<')
                ->execute();

            if ($ids === []) {
                return [];
            }

            return array_values($storage->loadMultiple($ids));
        } catch (\Throwable) {
            // Entity type lacks a classification_label / created_at column —
            // not a participant in classification. Skip silently.
            return [];
        }
    }

    private function recordPurge(RetentionPolicy $policy, string $entityTypeId, string $uuid, string $labelId): void
    {
        $this->auditWriter->record(new AuditEventDescriptor(
            kind: AuditEventKind::RetentionPurge,
            accountUid: 0,
            subjectUri: sprintf('entity://%s/%s', $entityTypeId, $uuid),
            outcome: 'allowed',
            severity: 'notice',
            entityTypeId: $entityTypeId,
            entityUuid: $uuid,
            attributes: [
                'policy_id' => $policy->id(),
                'label_id' => $labelId,
            ],
        ));
    }

    /**
     * Resolve an ISO-8601 duration (e.g. `P90D`) into an absolute cutoff
     * timestamp. Returns null when the duration string is invalid.
     */
    private function computeCutoff(string $duration): ?\DateTimeImmutable
    {
        if ($duration === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable('now')->sub(new \DateInterval($duration));
        } catch (\Throwable) {
            return null;
        }
    }
}

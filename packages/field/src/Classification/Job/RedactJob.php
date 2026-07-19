<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Classification\Job;

use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Field\Attribute\FieldTemplate;
use Waaseyaa\Field\Classification\ClassificationSubjectReader;
use Waaseyaa\Field\Entity\RetentionPolicy;
use Waaseyaa\Field\Entity\RetentionPolicyMaintenanceReader;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Scheduled job that redacts PII fields on entities matched by `action=redact`
 * retention policies, while preserving the entity, its structural fields, and
 * the audit trail (FR-011).
 *
 * "PII field" is declared via `#[FieldTemplate(..., pii: true)]` on the
 * entity's bundle/field surface. This job discovers those fields by reflecting
 * on the entity's concrete class (the same attribute the
 * {@see \Waaseyaa\Field\BundleTemplateCompiler} reads at compile time), nulls
 * each one through the entity repository's update path, and writes a
 * `retention.redact` audit event listing the redacted field keys.
 *
 * Redaction is non-destructive to identity: the entity row, its `id`/`uuid`,
 * the classification label, and the audit history all survive. Only the
 * marked PII values are blanked.
 *
 * Best-effort (NFR-004): each policy is wrapped in its own try-catch.
 *
 * @api
 */
final class RedactJob
{
    /** Entity types that are part of the classification machinery itself; never redacted. */
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

    public function run(): void
    {
        foreach ($this->loadRedactPolicies() as $policy) {
            try {
                $redacted = $this->applyPolicy($policy);
                $this->logger->info('classification.retention.redact_complete', [
                    'policy_id' => $policy->id(),
                    'redacted' => $redacted,
                ]);
            } catch (\Throwable $e) {
                // NFR-004: isolate per-policy failures.
                $this->logger->warning('classification.retention.redact_failed', [
                    'policy_id' => $policy->id(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return list<RetentionPolicy>
     */
    private function loadRedactPolicies(): array
    {
        // System sweep; no user account in scope. accessCheck(false) is the
        // intentional opt-out (CLAUDE.md §"Unbound getQuery() gate").
        // C-22 WP2/WP3: both the query surface and the read path now live on the repository.
        $repository = $this->entityTypeManager->getRepository('retention_policy');
        $ids = $repository->getQuery()
            ->accessCheck(false)
            ->condition('action', RetentionPolicy::ACTION_REDACT)
            ->execute();

        if ($ids === []) {
            return [];
        }

        return array_values(array_filter(
            $repository->findMany($ids),
            static fn(EntityInterface $e): bool => $e instanceof RetentionPolicy,
        ));
    }

    /**
     * Apply a single redact policy. Returns the number of entities redacted.
     */
    private function applyPolicy(RetentionPolicy $policy): int
    {
        $view = $this->policyReader->read($policy);
        $redacted = 0;

        foreach ($this->entityTypeManager->getDefinitions() as $entityTypeId => $definition) {
            if (in_array($entityTypeId, self::SELF_TYPES, true)) {
                continue;
            }

            foreach ($this->scanner->scan($entityTypeId, null, RetentionScanner::labelCondition($view)) as $entity) {
                $labelId = $this->subjectReader->read($entity)->label ?? '';
                if ($labelId === '' || !$view->matchesLabel($labelId)) {
                    continue;
                }

                $uuid = (string) ($entity->get('uuid') ?? '');
                if ($uuid !== '' && $view->isExempt($entityTypeId, $uuid)) {
                    continue;
                }

                $piiFields = $this->discoverPiiFields($entity);
                if ($piiFields === []) {
                    continue;
                }

                foreach ($piiFields as $field) {
                    $entity->set($field, null);
                }
                // repository->save() dispatches POST_SAVE; the entity, its id/uuid,
                // classification label, and audit trail are all preserved (FR-011).
                $this->entityTypeManager->getRepository($entityTypeId)->save($entity);

                $this->recordRedact($policy, $entityTypeId, $uuid, $labelId, $piiFields);
                ++$redacted;
            }
        }

        return $redacted;
    }

    /**
     * Discover the field keys marked `pii: true` via `#[FieldTemplate]` on the
     * entity's concrete class (properties and methods).
     *
     * @return list<string>
     */
    private function discoverPiiFields(EntityInterface $entity): array
    {
        $fields = [];
        $reflection = new \ReflectionClass($entity);

        $targets = [...$reflection->getProperties(), ...$reflection->getMethods()];
        foreach ($targets as $member) {
            foreach ($member->getAttributes(FieldTemplate::class) as $attr) {
                /** @var FieldTemplate $tpl */
                $tpl = $attr->newInstance();
                if ($tpl->pii && $tpl->key !== '') {
                    $fields[$tpl->key] = true;
                }
            }
        }

        return array_keys($fields);
    }

    /**
     * @param list<string> $redactedFields
     */
    private function recordRedact(
        RetentionPolicy $policy,
        string $entityTypeId,
        string $uuid,
        string $labelId,
        array $redactedFields,
    ): void {
        $this->auditWriter->record(new AuditEventDescriptor(
            kind: AuditEventKind::RetentionRedact,
            accountUid: 0,
            subjectUri: sprintf('entity://%s/%s', $entityTypeId, $uuid),
            outcome: 'allowed',
            severity: 'notice',
            entityTypeId: $entityTypeId,
            entityUuid: $uuid,
            attributes: [
                'policy_id' => $policy->id(),
                'label_id' => $labelId,
                'redacted_fields' => $redactedFields,
            ],
        ));
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Host;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityDeclaration;
use Waaseyaa\Access\Capability\CapabilityIssueContext;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\CapabilityRegistryInterface;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityValues;

/** Audited publication metadata read after admin row-view authorization. @api */
final readonly class AuditedAdminPublicationFieldReader implements BatchAdminPublicationFieldReaderInterface
{
    public const string ISSUER = 'admin-surface.publication-list';

    private const array FIELDS = ['workflow_state', 'status'];

    public function __construct(
        private AuditedFieldRead $reader,
        private CapabilityRegistryInterface $capabilities,
        private string $classificationGeneration = 'admin-publication-list-v1',
        private string $policyGeneration = 'admin-publication-list-v1',
    ) {
        $this->capabilities->register(new CapabilityDeclaration(
            issuer: self::ISSUER,
            reason: CapabilityReason::StrictAuditProjection,
            entityTypes: ['node'],
            // Node bundles are application-extensible. The typed reader and
            // fixed field set keep this projection bounded to publication
            // metadata while avoiding framework knowledge of app bundles.
            bundles: ['node'],
            fields: self::FIELDS,
            actorSemantics: [CapabilityActorSemantics::Account],
            maxTtlSeconds: 30,
            justification: 'Project node publication metadata only after an authenticated admin principal is authorized to view the list row.',
            wildcard: true,
            bindTenantFromContext: true,
            bindCommunityFromContext: true,
        ));
    }

    public function projects(EntityInterface $entity, string $field): bool
    {
        return $entity->getEntityTypeId() === 'node'
            && in_array($field, self::FIELDS, true)
            && in_array($field, EntityValues::fieldNames($entity), true);
    }

    public function read(EntityInterface $entity, AccountInterface $account): array
    {
        return $this->readMany([$entity], $account)[0];
    }

    public function readMany(array $entities, AccountInterface $account): array
    {
        if ($entities === []) {
            return [];
        }

        $results = array_fill(0, count($entities), []);
        $groups = [];
        foreach ($entities as $index => $entity) {
            $fields = array_values(array_filter(
                self::FIELDS,
                fn(string $field): bool => $this->projects($entity, $field),
            ));
            if ($fields === []) {
                continue;
            }
            $key = implode("\0", $fields);
            $groups[$key]['fields'] = $fields;
            $groups[$key]['indices'][] = $index;
            $groups[$key]['entities'][] = $entity;
        }

        foreach ($groups as $group) {
            $boundary = $this->capabilities->openBoundary('admin-publication-list:' . bin2hex(random_bytes(12)));
            try {
                $capability = $this->capabilities->issueValueRead(self::ISSUER, new CapabilityIssueContext(
                    executionBoundary: $boundary->correlationId,
                    actorSemantics: CapabilityActorSemantics::Account,
                    actorId: $account->id(),
                    tenantId: $account instanceof AuthorizationPrincipalInterface ? $account->tenantId() : null,
                    communityId: $account instanceof AuthorizationPrincipalInterface ? $account->communityId() : null,
                    expiresAt: new \DateTimeImmutable('+30 seconds'),
                    classificationGeneration: $this->classificationGeneration,
                    policyGeneration: $this->policyGeneration,
                ), $boundary);

                $values = $this->reader->readEntityMany(
                    $capability,
                    $boundary,
                    $group['entities'],
                    $group['fields'],
                    CapabilityReason::StrictAuditProjection,
                );
                foreach ($group['indices'] as $position => $index) {
                    $results[$index] = $values[$position];
                }
            } finally {
                $this->capabilities->revokeBoundary($boundary);
            }
        }

        return $results;
    }
}

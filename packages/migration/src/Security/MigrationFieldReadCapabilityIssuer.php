<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Security;

use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityDeclaration;
use Waaseyaa\Access\Capability\CapabilityExecutionBoundary;
use Waaseyaa\Access\Capability\CapabilityIssueContext;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\CapabilityRegistryInterface;
use Waaseyaa\Access\Capability\PrivilegedFieldReadCapability;

/** Kernel composition helper for exact migration-manifest authority. @api */
final readonly class MigrationFieldReadCapabilityIssuer
{
    public function __construct(
        private CapabilityRegistryInterface $registry,
        private string $classificationGeneration,
        private string $policyGeneration,
    ) {}

    public function register(MigrationFieldReadManifest $manifest): void
    {
        $this->registry->register(new CapabilityDeclaration(
            issuer: $manifest->issuer(),
            reason: CapabilityReason::MigrationImport,
            entityTypes: $manifest->entityTypes,
            bundles: $manifest->bundles,
            fields: $manifest->fields,
            tenantId: $manifest->tenantId,
            communityId: $manifest->communityId,
            actorSemantics: [CapabilityActorSemantics::NoActingContext],
            maxTtlSeconds: $manifest->maxTtlSeconds,
            justification: $manifest->justification,
        ));
    }

    public function issue(
        MigrationFieldReadManifest $manifest,
        CapabilityExecutionBoundary $boundary,
        \DateTimeImmutable $expiresAt,
    ): PrivilegedFieldReadCapability {
        return $this->registry->issueValueRead($manifest->issuer(), new CapabilityIssueContext(
            executionBoundary: $boundary->correlationId,
            actorSemantics: CapabilityActorSemantics::NoActingContext,
            actorId: null,
            tenantId: $manifest->tenantId,
            communityId: $manifest->communityId,
            expiresAt: $expiresAt,
            classificationGeneration: $this->classificationGeneration,
            policyGeneration: $this->policyGeneration,
        ), $boundary);
    }
}

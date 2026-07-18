<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Security;

use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityDeclaration;
use Waaseyaa\Access\Capability\CapabilityExecutionBoundary;
use Waaseyaa\Access\Capability\CapabilityIssueContext;
use Waaseyaa\Access\Capability\CapabilityRegistryInterface;
use Waaseyaa\Access\Capability\PrivilegedFieldReadCapability;

/** Kernel composition helper for explicitly declared maintenance commands. @api */
final readonly class CliFieldReadCapabilityIssuer
{
    public function __construct(
        private CapabilityRegistryInterface $registry,
        private string $classificationGeneration,
        private string $policyGeneration,
    ) {}

    public function register(CliFieldReadCapabilityDeclaration $declaration): void
    {
        $this->registry->register(new CapabilityDeclaration(
            issuer: $declaration->issuer(),
            reason: $declaration->reason,
            entityTypes: $declaration->entityTypes,
            bundles: $declaration->bundles,
            fields: $declaration->fields,
            tenantId: $declaration->tenantId,
            communityId: $declaration->communityId,
            actorSemantics: [CapabilityActorSemantics::NoActingContext],
            maxTtlSeconds: $declaration->maxTtlSeconds,
            justification: $declaration->justification,
        ));
    }

    public function issue(
        CliFieldReadCapabilityDeclaration $declaration,
        CapabilityExecutionBoundary $boundary,
        \DateTimeImmutable $expiresAt,
    ): PrivilegedFieldReadCapability {
        return $this->registry->issueValueRead($declaration->issuer(), new CapabilityIssueContext(
            executionBoundary: $boundary->correlationId,
            actorSemantics: CapabilityActorSemantics::NoActingContext,
            actorId: null,
            tenantId: $declaration->tenantId,
            communityId: $declaration->communityId,
            expiresAt: $expiresAt,
            classificationGeneration: $this->classificationGeneration,
            policyGeneration: $this->policyGeneration,
        ), $boundary);
    }
}

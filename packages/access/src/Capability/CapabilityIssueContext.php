<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Capability;

/** Exact runtime binding applied when the kernel issues a capability. @api */
final readonly class CapabilityIssueContext
{
    public function __construct(
        public string $executionBoundary,
        public CapabilityActorSemantics $actorSemantics,
        public int|string|null $actorId,
        public ?string $tenantId,
        public ?string $communityId,
        public \DateTimeImmutable $expiresAt,
        public string $classificationGeneration,
        public string $policyGeneration,
    ) {
        if ($executionBoundary === '' || $classificationGeneration === '' || $policyGeneration === '') {
            throw new \InvalidArgumentException('Capability issue context requires boundary and generation identities.');
        }
        if ($actorSemantics === CapabilityActorSemantics::Account && $actorId === null) {
            throw new \InvalidArgumentException('Account actor semantics require an actor id.');
        }
        if ($actorSemantics === CapabilityActorSemantics::Anonymous && $actorId !== 0) {
            throw new \InvalidArgumentException('Anonymous actor semantics require actor id 0.');
        }
        if ($actorSemantics === CapabilityActorSemantics::System && (!is_string($actorId) || $actorId === '')) {
            throw new \InvalidArgumentException('System actor semantics require a service identity.');
        }
        if ($actorSemantics === CapabilityActorSemantics::NoActingContext && $actorId !== null) {
            throw new \InvalidArgumentException('No-acting-context semantics require a null actor id.');
        }
    }
}

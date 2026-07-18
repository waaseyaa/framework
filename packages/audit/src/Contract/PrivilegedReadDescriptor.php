<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Contract;

use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\QueryFieldOperation;

/**
 * Metadata-only reservation descriptor. Field values have no representation
 * in this contract and therefore cannot be written accidentally.
 *
 * @api
 */
final readonly class PrivilegedReadDescriptor
{
    /**
     * @param list<string> $fields
     * @param list<string> $bundles
     * @param list<QueryFieldOperation> $queryOperations
     */
    public function __construct(
        public PrivilegedReadKind $kind,
        public CapabilityReason $reason,
        public string $issuer,
        public CapabilityActorSemantics $actorSemantics,
        public int|string|null $actorId,
        public string $entityTypeId,
        public int|string|null $entityId,
        public array $fields,
        public array $bundles,
        public ?string $tenantId,
        public ?string $communityId,
        public ?string $queryFingerprint,
        public array $queryOperations,
        public string $classificationGeneration,
        public string $policyGeneration,
        public string $correlationId,
        public string $callSite,
    ) {
        if ($issuer === '' || $entityTypeId === '' || $fields === [] || $bundles === [] || $classificationGeneration === '' || $policyGeneration === '' || $correlationId === '' || $callSite === '') {
            throw new \InvalidArgumentException('Privileged read descriptors require complete non-value metadata.');
        }
        if ($kind === PrivilegedReadKind::Value && ($queryFingerprint !== null || $queryOperations !== [])) {
            throw new \InvalidArgumentException('Value reservations cannot contain query metadata.');
        }
        if ($kind === PrivilegedReadKind::Query && (($queryFingerprint ?? '') === '' || $queryOperations === [])) {
            throw new \InvalidArgumentException('Query reservations require a fingerprint and operations.');
        }
        if ($actorSemantics === CapabilityActorSemantics::Account && $actorId === null) {
            throw new \InvalidArgumentException('Account reservations require an actor id.');
        }
        if ($actorSemantics === CapabilityActorSemantics::Anonymous && $actorId !== 0) {
            throw new \InvalidArgumentException('Anonymous reservations require actor id 0.');
        }
        if ($actorSemantics === CapabilityActorSemantics::System && (!is_string($actorId) || $actorId === '')) {
            throw new \InvalidArgumentException('System reservations require a service identity.');
        }
        if ($actorSemantics === CapabilityActorSemantics::NoActingContext && $actorId !== null) {
            throw new \InvalidArgumentException('No-acting-context reservations require a null actor id.');
        }
    }
}

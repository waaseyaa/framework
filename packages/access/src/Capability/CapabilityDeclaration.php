<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Capability;

/**
 * Boot-visible, exact capability request. Empty field sets grant nothing.
 *
 * @api
 */
final readonly class CapabilityDeclaration
{
    /**
     * @param list<string> $entityTypes
     * @param list<string> $bundles
     * @param list<string> $fields
     * @param list<string> $queryFields
     * @param list<QueryFieldOperation> $queryOperations
     * @param list<CapabilityActorSemantics> $actorSemantics
     */
    public function __construct(
        public string $issuer,
        public CapabilityReason $reason,
        public array $entityTypes,
        public array $bundles,
        public array $fields = [],
        public array $queryFields = [],
        public array $queryOperations = [],
        public ?string $tenantId = null,
        public ?string $communityId = null,
        public array $actorSemantics = [],
        public int $maxTtlSeconds = 300,
        public string $justification = '',
        public bool $wildcard = false,
        public bool $bindTenantFromContext = false,
        public bool $bindCommunityFromContext = false,
    ) {
        if ($issuer === '' || trim($justification) === '') {
            throw new \InvalidArgumentException('Capability declarations require an issuer and justification.');
        }
        if ($entityTypes === [] || $bundles === []) {
            throw new \InvalidArgumentException('Capability declarations require exact entity types and bundles.');
        }
        if ($queryFields !== [] && $queryOperations === []) {
            throw new \InvalidArgumentException('Query fields require explicit operations.');
        }
        if ($actorSemantics === [] || $maxTtlSeconds < 1) {
            throw new \InvalidArgumentException('Capability declarations require actor semantics and a positive maximum TTL.');
        }
        if (($bindTenantFromContext && $tenantId !== null) || ($bindCommunityFromContext && $communityId !== null)) {
            throw new \InvalidArgumentException('A capability scope is either fixed or explicitly context-bound, never both.');
        }
    }
}

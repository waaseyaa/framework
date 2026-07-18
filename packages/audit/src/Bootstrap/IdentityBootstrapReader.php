<?php

declare(strict_types=1);

namespace Waaseyaa\Audit\Bootstrap;

use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalBootstrapReaderInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityIssueContext;
use Waaseyaa\Access\Capability\CapabilityRegistryInterface;
use Waaseyaa\Entity\EntityInterface;

/** Produces the immutable policy principal from one strictly audited claim set. @api */
final readonly class IdentityBootstrapReader implements AuthorizationPrincipalBootstrapReaderInterface
{
    public function __construct(
        private SessionBootstrapReader $sessions,
        private CapabilityRegistryInterface $capabilities,
        private string $issuer,
        private string $classificationGeneration = 'wp2-dormant',
        private string $policyGeneration = 'wp2-dormant',
    ) {}

    public function fromEntity(EntityInterface $account, ?string $tenantId = null, ?string $communityId = null): AuthorizationPrincipalInterface
    {
        $boundary = $this->capabilities->openBoundary(bin2hex(random_bytes(16)));
        $capability = $this->capabilities->issueValueRead($this->issuer, new CapabilityIssueContext(
            executionBoundary: $boundary->correlationId,
            actorSemantics: CapabilityActorSemantics::NoActingContext,
            actorId: null,
            tenantId: $tenantId,
            communityId: $communityId,
            expiresAt: new \DateTimeImmutable('+30 seconds'),
            classificationGeneration: $this->classificationGeneration,
            policyGeneration: $this->policyGeneration,
        ), $boundary);
        try {
            $claims = $this->sessions->read($capability, $boundary, $account, ['roles', 'permissions', 'status']);
        } finally {
            $this->capabilities->revokeBoundary($boundary);
        }
        $roles = $this->strings($claims['roles'] ?? []);
        $permissions = $this->strings($claims['permissions'] ?? []);
        $active = (bool) ($claims['status'] ?? false);
        $id = $account->id() ?? 0;
        $authenticated = $active && $id !== 0 && !$account->isNew();
        $generation = hash('sha256', json_encode([$id, $authenticated, $roles, $permissions, $tenantId, $communityId], JSON_THROW_ON_ERROR));

        return new AuthorizationPrincipal($id, $authenticated, $roles, $permissions, $generation, $tenantId, $communityId);
    }

    /** @return list<string> */
    private function strings(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $result = array_values(array_unique(array_filter($values, 'is_string')));
        sort($result);
        return $result;
    }
}

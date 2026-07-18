<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Capability;

/**
 * Reference registry proving that authority resides in WeakMap membership.
 * Kernel bootstrap may replace this implementation without changing handles.
 *
 * @api
 */
final class InMemoryCapabilityRegistry implements CapabilityRegistryInterface
{
    /** @var array<string, CapabilityDeclaration> */
    private array $declarations = [];

    /** @var \WeakMap<object, CapabilityAuthorization> */
    private \WeakMap $authorizations;

    /** @var \WeakMap<object, CapabilityExecutionBoundary> */
    private \WeakMap $capabilityBoundaries;

    /** @var \WeakMap<CapabilityExecutionBoundary, true> */
    private \WeakMap $liveBoundaries;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param null|callable(): \DateTimeImmutable $clock */
    public function __construct(?callable $clock = null)
    {
        $this->authorizations = new \WeakMap();
        $this->capabilityBoundaries = new \WeakMap();
        $this->liveBoundaries = new \WeakMap();
        $this->clock = $clock === null
            ? static fn(): \DateTimeImmutable => new \DateTimeImmutable()
            : \Closure::fromCallable($clock);
    }

    public function register(CapabilityDeclaration $declaration): void
    {
        if (isset($this->declarations[$declaration->issuer])) {
            throw new \LogicException(sprintf('Capability issuer "%s" is already registered.', $declaration->issuer));
        }
        $this->declarations[$declaration->issuer] = $declaration;
    }

    public function openBoundary(string $correlationId): CapabilityExecutionBoundary
    {
        $boundary = new CapabilityExecutionBoundary($correlationId);
        $this->liveBoundaries[$boundary] = true;

        return $boundary;
    }

    public function issueValueRead(string $issuer, CapabilityIssueContext $context, CapabilityExecutionBoundary $boundary): PrivilegedFieldReadCapability
    {
        $this->assertLiveBoundary($boundary, $context);
        $declaration = $this->validatedDeclaration($issuer, $context);
        if ($declaration->fields === []) {
            throw new \LogicException('The declaration grants no value-read fields.');
        }
        $capability = new PrivilegedFieldReadCapability();
        $this->authorizations[$capability] = new CapabilityAuthorization($declaration, $context);
        $this->capabilityBoundaries[$capability] = $boundary;

        return $capability;
    }

    public function issueQueryRead(string $issuer, CapabilityIssueContext $context, CapabilityExecutionBoundary $boundary): QueryFieldReadCapability
    {
        $this->assertLiveBoundary($boundary, $context);
        $declaration = $this->validatedDeclaration($issuer, $context);
        if ($declaration->queryFields === [] || $declaration->queryOperations === []) {
            throw new \LogicException('The declaration grants no query-field operations.');
        }
        $capability = new QueryFieldReadCapability();
        $this->authorizations[$capability] = new CapabilityAuthorization($declaration, $context);
        $this->capabilityBoundaries[$capability] = $boundary;

        return $capability;
    }

    public function authorizationFor(PrivilegedFieldReadCapability|QueryFieldReadCapability $capability, CapabilityExecutionBoundary $boundary): ?CapabilityAuthorization
    {
        if (!isset($this->liveBoundaries[$boundary]) || ($this->capabilityBoundaries[$capability] ?? null) !== $boundary) {
            return null;
        }
        $authorization = $this->authorizations[$capability] ?? null;
        if ($authorization !== null && $authorization->context->expiresAt <= ($this->clock)()) {
            unset($this->authorizations[$capability]);
            unset($this->capabilityBoundaries[$capability]);

            return null;
        }

        return $authorization;
    }

    public function revokeBoundary(CapabilityExecutionBoundary $boundary): void
    {
        unset($this->liveBoundaries[$boundary]);
        $revoke = [];
        foreach ($this->capabilityBoundaries as $capability => $capabilityBoundary) {
            if ($capabilityBoundary === $boundary) {
                $revoke[] = $capability;
            }
        }
        foreach ($revoke as $capability) {
            unset($this->authorizations[$capability]);
            unset($this->capabilityBoundaries[$capability]);
        }
    }

    private function assertLiveBoundary(CapabilityExecutionBoundary $boundary, CapabilityIssueContext $context): void
    {
        if (!isset($this->liveBoundaries[$boundary]) || $boundary->correlationId !== $context->executionBoundary) {
            throw new \LogicException('Capability issuance requires the matching live execution boundary.');
        }
    }

    private function validatedDeclaration(string $issuer, CapabilityIssueContext $context): CapabilityDeclaration
    {
        $declaration = $this->declarations[$issuer] ?? throw new \LogicException(sprintf('Unknown capability issuer "%s".', $issuer));
        if ((!$declaration->bindTenantFromContext && $declaration->tenantId !== $context->tenantId)
            || (!$declaration->bindCommunityFromContext && $declaration->communityId !== $context->communityId)
        ) {
            throw new \LogicException('Capability scope does not match the declared tenant/community.');
        }
        if (!in_array($context->actorSemantics, $declaration->actorSemantics, true)) {
            throw new \LogicException('Capability actor semantics are not declared.');
        }
        $now = ($this->clock)();
        if ($context->expiresAt <= $now || $context->expiresAt->getTimestamp() - $now->getTimestamp() > $declaration->maxTtlSeconds) {
            throw new \LogicException('Capability expiry exceeds the declaration TTL or is already expired.');
        }

        return $declaration;
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Capability;

/**
 * Kernel-owned registry for reviewed declarations and one-boundary handles.
 *
 * @api
 */
interface CapabilityRegistryInterface
{
    public function register(CapabilityDeclaration $declaration): void;

    public function openBoundary(string $correlationId): CapabilityExecutionBoundary;

    public function issueValueRead(string $issuer, CapabilityIssueContext $context, CapabilityExecutionBoundary $boundary): PrivilegedFieldReadCapability;

    public function issueQueryRead(string $issuer, CapabilityIssueContext $context, CapabilityExecutionBoundary $boundary): QueryFieldReadCapability;

    public function authorizationFor(PrivilegedFieldReadCapability|QueryFieldReadCapability $capability, CapabilityExecutionBoundary $boundary): ?CapabilityAuthorization;

    public function revokeBoundary(CapabilityExecutionBoundary $boundary): void;
}

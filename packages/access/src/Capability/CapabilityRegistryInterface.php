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

    public function issueValueRead(string $issuer, CapabilityIssueContext $context): PrivilegedFieldReadCapability;

    public function issueQueryRead(string $issuer, CapabilityIssueContext $context): QueryFieldReadCapability;

    public function authorizationFor(PrivilegedFieldReadCapability|QueryFieldReadCapability $capability): ?CapabilityAuthorization;

    public function revokeBoundary(string $executionBoundary): void;
}

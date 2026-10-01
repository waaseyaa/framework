<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Management;

/**
 * Product-owned read-only projection of actual registered, activated operations.
 * Never implement by copying the authored management manifest. Resolve schemas,
 * scopes and effects from the real adapters. Verification results must come from
 * executed checks, not a list of test names. No credential or user data belongs here.
 * @api
 */
interface ManagementInventoryInterface
{
    /** Exact ProjectSourceDiscovery identity of the code exercised by this inventory. */
    /** Complete management input identity, not the architecture-scanning digest. */
    public function sourceDigest(): string;

    /** Exact site.yaml semantic identity whose activation/policy the checks exercised. */
    public function siteManifestDigest(): string;

    /** @return iterable<ManagementOperation> */
    public function operations(): iterable;

    /** @return iterable<ManagementVerificationResult> */
    public function verificationResults(): iterable;
}

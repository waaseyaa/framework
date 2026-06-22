<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Gate;

/**
 * Optional gate capability: report whether the policy bound to an entity type
 * has opted into the listing access fast-path (FR-032 of the listing-pipeline
 * spec).
 *
 * The listing resolver probes for this capability with an `instanceof` check.
 * A gate that does not implement it leaves the fast-path OFF, so the resolver
 * runs the always-correct per-row access loop — the safe default. This keeps
 * the security-sensitive fast-path opt-in narrow and additive: it does not
 * touch {@see GateInterface}, which every gate consumer depends on.
 *
 * @api
 */
interface ListingFastPathProbeInterface
{
    /**
     * Name of the opt-in constant a policy class declares to enable the
     * listing access fast-path. Default-absent means "not opted in".
     */
    public const string FAST_PATH_CONST = 'SUPPORTS_LISTING_FAST_PATH';

    /**
     * Whether the policy resolved for `$entityTypeId` declares
     * `public const bool SUPPORTS_LISTING_FAST_PATH = true`.
     *
     * MUST return `false` when no policy is bound, when the constant is absent,
     * or when it is not exactly boolean `true`. The fast-path is a deliberate
     * per-policy opt-in: any ambiguity resolves to the safe (per-row-checked)
     * path.
     *
     * @param non-empty-string $entityTypeId
     */
    public function policyAllowsListingFastPath(string $entityTypeId): bool;
}

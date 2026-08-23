<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\Discovery;

/**
 * Application-owned restriction of which ENTITY TYPES may appear in the
 * crawler-facing inventories (`sitemap.xml`, `llms.txt`).
 *
 * This is deliberately the only question on this contract. The other two axes
 * already have owners and must not be duplicated here:
 *
 *  - Per-entity VISIBILITY is owned by the access-policy pass. Enumeration runs
 *    as an explicit anonymous principal with `setAccount()`, so every registered
 *    `AccessPolicyInterface` (published state, member-only sections, privacy
 *    holds) has already excluded the row before this contract is consulted.
 *  - Per-entity URL ELIGIBILITY is owned by
 *    {@see PublicUrlPolicyInterface::canonicalPath()} returning null.
 *
 * Restriction only. This narrows the type set; it can never widen it. The
 * framework's {@see NonPublicEntityTypes} floor is applied first and
 * unconditionally, so returning true for `user` (or any other floor entry) does
 * not re-enable that type.
 *
 * Bind an implementation on this interface's FQCN in a service provider's
 * `register()`. Binding nothing means every non-floor type is eligible, which is
 * the framework's existing behaviour.
 *
 * @api
 */
interface CrawlEligibilityPolicyInterface
{
    /**
     * May this entity type appear in the public crawler inventories at all?
     *
     * Consulted once per entity type, after the framework's non-public floor.
     */
    public function allowsEntityType(string $entityTypeId): bool;
}

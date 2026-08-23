<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\Discovery;

/**
 * Application-contributed sitemap URLs that do not correspond to an entity.
 *
 * Listing pages, curated landing routes, and similar application-owned URLs have
 * no entity to enumerate, so no {@see PublicUrlPolicyInterface} call can produce
 * them. This contract exists so an application can add them WITHOUT replacing
 * `SeoPublicController` or re-registering the `/sitemap.xml` route, which is the
 * fork this whole seam is meant to make unnecessary.
 *
 * Ordering is the application's: the returned iterable's order is preserved
 * verbatim, and contributed URLs are appended after the enumerated entity URLs.
 * Duplicate final URLs are suppressed, first occurrence winning, so a contributed
 * path that an entity already produced does not appear twice. Both rules are
 * deterministic and pinned by test.
 *
 * Contributed paths are NOT access-checked, because they are not entities and the
 * framework has nothing to check them against. Contribute only URLs that are
 * unconditionally public.
 *
 * Bind ONE implementation on this interface's FQCN in a service provider's
 * `register()`; compose several sources behind it rather than expecting multiple
 * bindings, so the order stays the application's to decide.
 *
 * @api
 */
interface SitemapContributorInterface
{
    /**
     * @return iterable<SitemapPath> In the order they should be emitted.
     */
    public function contributedPaths(): iterable;
}

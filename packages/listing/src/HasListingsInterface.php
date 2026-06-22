<?php

declare(strict_types=1);

namespace Waaseyaa\Listing;

/**
 * Provider capability: exposes declarative listing definitions to the
 * {@see ListingResolver}.
 *
 * Implement this interface on a {@code ServiceProvider} to register one or
 * more {@see ListingDefinition} instances with the framework. Definitions
 * are discovered at manifest compile time by {@see ListingDiscoverer} and
 * exposed through {@see ListingDefinitionRegistry} for id-keyed lookup.
 *
 * Mirrors the declarative provider-capability pattern (FR-015): a single
 * declarative method called once per process boot. Implementations SHOULD be
 * pure (no side effects, idempotent).
 *
 * Layer placement: Listing (L3). Consumed by {@see ListingDiscoverer} (also
 * L3) and integrated by the {@code PackageManifestCompiler} (L0) via
 * {@code instanceof} in WP11.
 *
 * @api
 */
interface HasListingsInterface
{
    /**
     * Yield the listing definitions provided by this service provider.
     *
     * Called exactly once per process boot during registry construction.
     * The returned array MUST be a list whose entries are
     * {@see ListingDefinition} instances; {@see ListingDiscoverer} enforces
     * this defensively at discovery time so misconfigured extensions fail
     * loudly rather than silently corrupting the registry.
     *
     * @return list<ListingDefinition>
     */
    public function listings(): array;
}

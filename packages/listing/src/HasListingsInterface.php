<?php

declare(strict_types=1);

namespace Waaseyaa\Listing;

/**
 * Provider capability: exposes declarative listing definitions to the
 * {@see ListingResolver}.
 *
 * Implement this interface on a {@code ServiceProvider} to register one or
 * more {@see ListingDefinition} instances with the framework. Definitions
 * are discovered during provider registry construction by {@see ListingDiscoverer} and
 * exposed through {@see ListingDefinitionRegistry} for id-keyed lookup.
 *
 * Mirrors the declarative provider-capability pattern (FR-015): a single
 * declarative method called during registry construction. Implementations SHOULD be
 * pure (no side effects, idempotent).
 *
 * Layer placement: Listing (L3). Consumed by {@see ListingDiscoverer} (also
 * L3). The listing provider obtains current capabilities from foundation
 * and validates definitions after all ordinary provider boots.
 *
 * @api
 */
interface HasListingsInterface
{
    /**
     * Yield the listing definitions provided by this service provider.
     *
     * Called when the listing registry is constructed. Implementations return
     * a list of {@see ListingDefinition} instances. The discoverer rejects
     * duplicate IDs; producers own the declared list and element contract.
     *
     * @return list<ListingDefinition>
     */
    public function listings(): array;
}

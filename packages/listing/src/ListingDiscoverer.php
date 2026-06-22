<?php

declare(strict_types=1);

namespace Waaseyaa\Listing;

use LogicException;

/**
 * Discovers {@see ListingDefinition} instances from service providers that
 * implement {@see HasListingsInterface} (FR-016).
 *
 * Mirrors the provider-capability discovery pattern: iterate registered service providers,
 * filter by {@code instanceof}, and flatten each provider's contribution into
 * a single list.
 *
 * Duplicate listing ids across providers are treated as a hard error — the
 * discoverer throws a {@see LogicException} naming both providers and the
 * conflicting id. There is no precedence rule; duplicate ids indicate a
 * misconfigured composition and must be resolved by the application.
 *
 * Discovery is deterministic given a stable provider order. The future
 * {@code PackageManifestCompiler} integration (WP11) will memoize the
 * flattened list into {@code var/manifest.php} so this loop runs only at
 * manifest-build time.
 *
 * @api
 */
final class ListingDiscoverer
{
    /**
     * @param iterable<object> $providers Service-provider instances; only
     *                                    those implementing
     *                                    {@see HasListingsInterface} are
     *                                    consulted.
     */
    public function __construct(private readonly iterable $providers) {}

    /**
     * Flatten all listings contributed by {@see HasListingsInterface}
     * providers into a single list.
     *
     * @return list<ListingDefinition>
     *
     * @throws LogicException If two providers declare a listing with the
     *                        same id.
     */
    public function discover(): array
    {
        /** @var list<ListingDefinition> $definitions */
        $definitions = [];
        /** @var array<string, class-string> $seenBy */
        $seenBy = [];

        foreach ($this->providers as $provider) {
            if (!$provider instanceof HasListingsInterface) {
                continue;
            }

            foreach ($provider->listings() as $definition) {
                $id = $definition->id;
                if (isset($seenBy[$id])) {
                    throw new LogicException(sprintf(
                        'Duplicate listing id "%s" declared by providers "%s" and "%s".',
                        $id,
                        $seenBy[$id],
                        $provider::class,
                    ));
                }

                $seenBy[$id] = $provider::class;
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }
}

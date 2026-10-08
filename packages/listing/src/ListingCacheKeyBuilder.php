<?php

declare(strict_types=1);

namespace Waaseyaa\Listing;

/**
 * Builds deterministic cache keys for listing results.
 *
 * FR-037 key format: `listing:<def-hash>:<exposed-hash>:<ctx-hash>` where
 * each hash is a 16-hex-char SHA-256 prefix over canonical JSON.
 *
 * Internal identity composition; the definition, exposed values and context
 * map share listing's canonical hash policy.
 *
 * Cross-worker determinism: this class is process-pure (no time, no
 * random, no filesystem access). Two PHP workers with the same inputs
 * MUST produce the same key.
 *
 * @internal
 */
final class ListingCacheKeyBuilder
{
    /**
     * Build a deterministic cache key.
     *
     * @param array<string, string> $contextValues Resolved context-name => canonical-value pairs from {@see \Waaseyaa\Cache\ContextResolver::resolve()}.
     *
     * @return non-empty-string
     */
    public function build(
        ListingDefinition $def,
        ExposedFilterValues $exposed,
        array $contextValues,
    ): string {
        return sprintf(
            'listing:%s:%s:%s',
            $def->cacheKeyHash(),
            $exposed->cacheKeyHash(),
            ListingHash::of($contextValues),
        );
    }

}

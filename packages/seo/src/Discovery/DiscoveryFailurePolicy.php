<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\Discovery;

use InvalidArgumentException;

/**
 * How a crawler-surface failure is expressed to the caller.
 *
 * `sitemap.xml` and `llms.txt` have historically swallowed every `Throwable` and
 * served a valid-but-EMPTY 200 document. That is safe in the visibility sense
 * (nothing leaks) but indistinguishable from a site with no content, which is
 * precisely how a real downstream outage stayed invisible: an empty `urlset`
 * looked like a correctly working sitemap for a site nobody had published to.
 *
 * Sites differ on whether that is acceptable, so the behaviour is now an explicit
 * choice rather than an implicit one. Set it with the `seo.failure_policy`
 * configuration key.
 *
 * Two rules hold under BOTH cases and are not configurable, because they are
 * about visibility rather than reporting:
 *
 *  - a malformed (non-throwing) policy return always drops just that entity, and
 *    never falls back to the framework's built-in URL model;
 *  - a missing or invalid trusted origin in canonical mode never degrades to a
 *    relative or request-derived URL.
 *
 * @api
 */
enum DiscoveryFailurePolicy: string
{
    /**
     * Degrade to a valid but empty document with HTTP 200. The framework's
     * historical behaviour and the default, so existing consumers are unaffected.
     */
    case EmptyDocument = 'empty_document';

    /**
     * Let the failure propagate so the error handler reports it. Choose this when
     * an unbuildable crawler surface is an operational failure that must be seen.
     */
    case Propagate = 'propagate';

    /**
     * Resolve from application configuration (`seo.failure_policy`).
     *
     * A missing key keeps the backward-compatible default. An unrecognised value
     * is a configuration error and throws rather than silently resolving to the
     * more permissive case: a typo must not quietly choose failure semantics.
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        $seo = $config['seo'] ?? null;
        $configured = is_array($seo) ? $seo['failure_policy'] ?? null : null;

        if ($configured === null || $configured === '') {
            return self::EmptyDocument;
        }

        if (!is_string($configured) || self::tryFrom($configured) === null) {
            throw new InvalidArgumentException(sprintf(
                'seo.failure_policy must be one of: %s.',
                implode(', ', array_column(self::cases(), 'value')),
            ));
        }

        return self::from($configured);
    }
}

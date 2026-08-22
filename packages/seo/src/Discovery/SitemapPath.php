<?php

declare(strict_types=1);

namespace Waaseyaa\Seo\Discovery;

use InvalidArgumentException;

/**
 * One application-contributed sitemap entry, addressed by ROOT-RELATIVE path.
 *
 * Public API. This is the value type {@see SitemapContributorInterface} returns,
 * so applications construct it directly and its constructor signature and
 * validation rules are part of the framework's stability contract. It is listed
 * in `docs/public-surface-map.php` for that reason, even though the parity gate's
 * automatic discovery only walks interfaces, abstract classes, traits, and enums:
 * an `@api` interface's return type is public surface regardless of its shape.
 * {@see \Waaseyaa\Seo\Tests\Unit\Discovery\SitemapPathTest} contract-tests it as
 * such.
 *
 * The distinction from {@see \Waaseyaa\Seo\SitemapUrl} is the whole point: that
 * type carries a finished absolute-or-relative `loc` and is what the generator
 * renders; this type carries a path an application supplied and the framework has
 * not yet joined to its trusted origin. Keeping them separate is what stops an
 * application from supplying an off-site authority.
 *
 * @api
 */
final readonly class SitemapPath
{
    private const array VALID_CHANGEFREQ = [
        'always',
        'hourly',
        'daily',
        'weekly',
        'monthly',
        'yearly',
        'never',
    ];

    public function __construct(
        public string $path,
        public ?string $lastmod = null,
        public ?string $changefreq = null,
        public ?float $priority = null,
    ) {
        if (!DiscoveryPath::acceptsPath($this->path)) {
            throw new InvalidArgumentException(
                'SitemapPath path must be a root-relative path with no query, fragment, authority, traversal, control character, backslash, or malformed percent-encoding.',
            );
        }

        if ($this->changefreq !== null && !\in_array($this->changefreq, self::VALID_CHANGEFREQ, true)) {
            throw new InvalidArgumentException(
                'Invalid changefreq; expected one of: ' . implode(', ', self::VALID_CHANGEFREQ),
            );
        }

        if ($this->priority !== null && ($this->priority < 0.0 || $this->priority > 1.0)) {
            throw new InvalidArgumentException('SitemapPath priority must be between 0.0 and 1.0.');
        }
    }
}

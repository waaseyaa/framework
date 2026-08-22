<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Http;

use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountPrincipalFactoryInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Seo\Discovery\CrawlEligibilityPolicyInterface;
use Waaseyaa\Seo\Discovery\DiscoveryFailurePolicy;
use Waaseyaa\Seo\Discovery\DiscoveryPath;
use Waaseyaa\Seo\Discovery\Exception\DiscoveryConfigurationException;
use Waaseyaa\Seo\Discovery\NonPublicEntityTypes;
use Waaseyaa\Seo\Discovery\PublicUrlPolicyInterface;
use Waaseyaa\Seo\Discovery\SitemapContributorInterface;
use Waaseyaa\Seo\Discovery\SitemapPath;
use Waaseyaa\Seo\Llms\LlmsTxtGenerator;
use Waaseyaa\Seo\RobotsTxtGenerator;
use Waaseyaa\Seo\SitemapGenerator;
use Waaseyaa\Seo\SitemapUrl;
use Waaseyaa\User\AnonymousUser;

/**
 * Serves the public, crawler-facing SEO/agent artifacts on stable URLs:
 * `/robots.txt`, `/sitemap.xml`, and `/llms.txt`.
 *
 * Route wiring lives in {@see \Waaseyaa\SSR\SsrServiceProvider::routes()} (L6),
 * not in the L3 `seo` package, which owns only the generators and the discovery
 * contracts — `seo` must not depend on routing (L4). Enumeration is a public
 * inventory scoped to what an ANONYMOUS caller may view: the generators are
 * called with an explicit {@see AnonymousUser} (R6/M3, closes the audit-M3 leak
 * where a published-but-access-restricted entity — a classification hold, a
 * genealogy privacy rule, a member-only section — was enumerated regardless of
 * who could actually view it) rather than the requesting session's own account,
 * so the sitemap/llms.txt always reflects the public, unauthenticated surface no
 * matter who (or what bot) requests these routes.
 *
 * `/robots.txt` advertises the sitemap with an absolute URL taken from
 * {@see CanonicalPublicOrigin} (trusted `APP_URL` / `api_catalog.base_url` /
 * `app.url`). The incoming request Host is never used. Missing or invalid
 * configuration omits the `Sitemap:` line rather than emitting a relative
 * or attacker-controlled URL.
 *
 * ## Two URL modes
 *
 * **Zero-config (nothing bound).** The topic/URL model uses system paths
 * (`/{type}/{id}`) and the same-URL Markdown representation (`?format=md`), with
 * relative locs. This is the historical behaviour and is unchanged.
 *
 * **Canonical (a {@see PublicUrlPolicyInterface} is bound).** The application
 * owns the URL of every entity; the framework owns the origin. Policies return
 * ROOT-RELATIVE paths, validated by {@see DiscoveryPath}, and the framework joins
 * them to {@see CanonicalPublicOrigin}. An application therefore cannot cause an
 * off-site or request-derived URL to be emitted, and it never has to fork this
 * controller or re-register the route to get its own URLs.
 *
 * ## What fails closed, and how
 *
 * Two rules are not configurable, because they govern visibility rather than
 * reporting. A policy that returns a malformed path drops THAT ENTITY and never
 * falls back to the `/{type}/{id}` default, which would advertise a URL the
 * application declined to authorise. And canonical mode without a valid trusted
 * origin raises {@see DiscoveryConfigurationException} rather than degrading to
 * relative or request-derived URLs.
 *
 * What IS configurable is whether a failure is visible:
 * {@see DiscoveryFailurePolicy} selects between the historical empty-document
 * degradation and propagation to the error handler. Under either policy, a
 * failure can only ever narrow what is published.
 *
 * @api
 */
final class SeoPublicController
{
    private readonly DiscoveryFailurePolicy $failurePolicy;

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly ?AccountFieldReadScopeInterface $fieldReadScope = null,
        private readonly ?AccountPrincipalFactoryInterface $principalFactory = null,
        private readonly ?CanonicalPublicOrigin $canonicalOrigin = null,
        private readonly ?PublicUrlPolicyInterface $urlPolicy = null,
        private readonly ?CrawlEligibilityPolicyInterface $crawlEligibility = null,
        private readonly ?SitemapContributorInterface $sitemapContributor = null,
        ?DiscoveryFailurePolicy $failurePolicy = null,
    ) {
        $this->failurePolicy = $failurePolicy ?? DiscoveryFailurePolicy::EmptyDocument;
    }

    public function robotsTxt(): Response
    {
        $body = new RobotsTxtGenerator()->toText(
            sitemapUrl: $this->canonicalOrigin?->sitemapUrl(),
            disallowPaths: [],
        );

        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemapXml(): Response
    {
        $generator = new SitemapGenerator();

        try {
            $urls = $this->collectSitemapUrls($generator);
        } catch (\Throwable $error) {
            if ($this->failurePolicy === DiscoveryFailurePolicy::Propagate) {
                throw $error;
            }
            $urls = [];
        }

        return new Response($generator->toXml($urls), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function llmsTxt(): Response
    {
        $generator = new LlmsTxtGenerator();

        try {
            $topics = $this->runAsAnonymous(
                fn(AnonymousUser $anonymous): array => $generator->collectTopics(
                    $this->entityTypeManager,
                    fn(string $type): ?array => $this->isPublicType($type)
                        ? ['title' => $this->humanize($type), 'summary' => sprintf('%s content as Markdown.', $this->humanize($type))]
                        : null,
                    fn(string $type, int|string $id, string $label): ?array => $this->llmsLink($type, $id),
                    account: $anonymous,
                ),
            );
        } catch (\Throwable $error) {
            if ($this->failurePolicy === DiscoveryFailurePolicy::Propagate) {
                throw $error;
            }
            $topics = [];
        }

        $body = $generator->generate(
            'Waaseyaa',
            'Machine-readable index of site content for AI agents. Each linked URL returns clean Markdown.',
            $topics,
        );

        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * @return list<SitemapUrl>
     */
    private function collectSitemapUrls(SitemapGenerator $generator): array
    {
        $origin = $this->canonicalOriginForUrlMode();

        $urls = $this->runAsAnonymous(
            fn(AnonymousUser $anonymous): array => $generator->collectFromEntityTypes(
                $this->entityTypeManager,
                fn(string $type, int|string $id): ?string => $this->sitemapLoc($type, $id, $origin),
                account: $anonymous,
            ),
        );

        foreach ($this->contributedSitemapUrls($origin) as $contributed) {
            $urls[] = $contributed;
        }

        return $this->withoutDuplicateLocations($urls);
    }

    /**
     * The origin sitemap locs are built from, or null in zero-config mode.
     *
     * Binding a URL policy is an application declaring that its canonical URLs
     * are absolute; without a trusted origin there is no honest way to build one,
     * and the historical relative fallback would silently publish a different URL
     * shape than the one that was asked for.
     */
    private function canonicalOriginForUrlMode(): ?CanonicalPublicOrigin
    {
        if ($this->urlPolicy === null) {
            return $this->canonicalOrigin;
        }

        return $this->canonicalOrigin ?? throw new DiscoveryConfigurationException(
            'A public URL policy is bound but no trusted canonical origin is configured; '
            . 'set APP_URL, api_catalog.base_url, or app.url to a canonical HTTPS origin. '
            . 'Sitemap URLs are never derived from the request.',
        );
    }

    private function sitemapLoc(string $type, int|string $id, ?CanonicalPublicOrigin $origin): ?string
    {
        if (!$this->isPublicType($type)) {
            return null;
        }

        if ($this->urlPolicy === null) {
            return sprintf('/%s/%s', $type, rawurlencode((string) $id));
        }

        $entity = $this->loadEntity($type, $id);
        if ($entity === null) {
            return null;
        }

        $urlPolicy = $this->urlPolicy;
        $path = $this->policyPath(
            static fn(): ?string => $urlPolicy->canonicalPath($entity),
            allowQuery: false,
        );

        // $origin is non-null whenever $urlPolicy is: canonicalOriginForUrlMode()
        // has already refused the alternative.
        return $path === null || $origin === null ? null : $origin->absoluteUrl($path);
    }

    /**
     * @return array{title: string, url: string}|null
     */
    private function llmsLink(string $type, int|string $id): ?array
    {
        if ($this->urlPolicy === null) {
            return [
                'title' => sprintf('%s %s', $type, $id),
                // Same-URL Markdown representation (Accept negotiation / ?format=md).
                'url' => sprintf('/%s/%s?format=md', $type, rawurlencode((string) $id)),
            ];
        }

        $entity = $this->loadEntity($type, $id);
        if ($entity === null) {
            return null;
        }

        $urlPolicy = $this->urlPolicy;
        $path = $this->policyPath(
            static fn(): ?string => $urlPolicy->markdownPath($entity),
            allowQuery: true,
        );

        // Deliberately left ROOT-RELATIVE. llms.txt permits relative links, the
        // existing document shape is pinned by SeoPublicRoutesTest, and nothing
        // in the convention calls for absolute URLs the way sitemap.xml does.
        return $path === null ? null : ['title' => sprintf('%s %s', $type, $id), 'url' => $path];
    }

    /**
     * Invoke one application URL policy call under the failure policy.
     *
     * A THROWING policy is a failure and follows {@see DiscoveryFailurePolicy}. A
     * policy that RETURNS a malformed path is not a failure, it is an answer the
     * framework refuses to publish: the entity is dropped under every policy, and
     * never falls back to the built-in URL model.
     */
    private function policyPath(callable $resolve, bool $allowQuery): ?string
    {
        try {
            $path = $resolve();
        } catch (\Throwable $error) {
            if ($this->failurePolicy === DiscoveryFailurePolicy::Propagate) {
                throw $error;
            }

            return null;
        }

        if ($path === null) {
            return null;
        }

        $accepted = $allowQuery
            ? DiscoveryPath::acceptsPathWithQuery($path)
            : DiscoveryPath::acceptsPath($path);

        return $accepted ? $path : null;
    }

    private function loadEntity(string $type, int|string $id): ?EntityInterface
    {
        try {
            return $this->entityTypeManager->getRepository($type)->find((string) $id);
        } catch (\Throwable $error) {
            if ($this->failurePolicy === DiscoveryFailurePolicy::Propagate) {
                throw $error;
            }

            return null;
        }
    }

    /**
     * @return list<SitemapUrl>
     */
    private function contributedSitemapUrls(?CanonicalPublicOrigin $origin): array
    {
        if ($this->sitemapContributor === null) {
            return [];
        }

        // No defensive type guard: `contributedPaths()` declares
        // iterable<SitemapPath>, so yielding anything else is a contract
        // violation rather than an input to validate. It surfaces as a TypeError
        // through the configured DiscoveryFailurePolicy, like any other failure.
        $urls = [];
        foreach ($this->sitemapContributor->contributedPaths() as $contributed) {
            $urls[] = new SitemapUrl(
                loc: $origin?->absoluteUrl($contributed->path) ?? $contributed->path,
                lastmod: $contributed->lastmod,
                changefreq: $contributed->changefreq,
                priority: $contributed->priority,
            );
        }

        return $urls;
    }

    /**
     * Suppress duplicate locations, first occurrence winning.
     *
     * Entity URLs are enumerated before contributed ones, so an application that
     * contributes a listing URL an entity also produces gets the entity's entry
     * and its metadata, deterministically, rather than two `<url>` elements for
     * one address.
     *
     * @param list<SitemapUrl> $urls
     * @return list<SitemapUrl>
     */
    private function withoutDuplicateLocations(array $urls): array
    {
        $seen = [];
        $unique = [];
        foreach ($urls as $url) {
            if (isset($seen[$url->loc])) {
                continue;
            }
            $seen[$url->loc] = true;
            $unique[] = $url;
        }

        return $unique;
    }

    /**
     * The framework floor is unconditional; the application policy may only
     * narrow further. An application cannot re-enable `user` or the genealogy
     * types by returning true.
     */
    private function isPublicType(string $entityTypeId): bool
    {
        if (NonPublicEntityTypes::excludes($entityTypeId)) {
            return false;
        }

        if ($this->crawlEligibility === null) {
            return true;
        }

        $eligibility = $this->crawlEligibility;

        try {
            return $eligibility->allowsEntityType($entityTypeId);
        } catch (\Throwable $error) {
            if ($this->failurePolicy === DiscoveryFailurePolicy::Propagate) {
                throw $error;
            }

            // Fail closed: an eligibility policy that cannot answer must not be
            // read as permission.
            return false;
        }
    }

    /** Run crawler enumeration under its declared anonymous principal. */
    private function runAsAnonymous(callable $callback): array
    {
        $anonymous = new AnonymousUser();
        if ($this->fieldReadScope === null || $this->principalFactory === null) {
            return $callback($anonymous);
        }

        return $this->fieldReadScope->run(
            $this->principalFactory->fromAccount($anonymous),
            fn(): array => $callback($anonymous),
        );
    }

    private function humanize(string $entityTypeId): string
    {
        return ucwords(str_replace('_', ' ', $entityTypeId));
    }
}

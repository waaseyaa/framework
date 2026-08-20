<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Http;

use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountPrincipalFactoryInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Seo\Llms\LlmsTxtGenerator;
use Waaseyaa\Seo\RobotsTxtGenerator;
use Waaseyaa\Seo\SitemapGenerator;
use Waaseyaa\User\AnonymousUser;

/**
 * Serves the public, crawler-facing SEO/agent artifacts on stable URLs:
 * `/robots.txt`, `/sitemap.xml`, and `/llms.txt`.
 *
 * Route wiring lives in {@see \Waaseyaa\SSR\SsrServiceProvider::routes()} (L6),
 * not in the L3 `seo` package, which owns only the generators — `seo` must not
 * depend on routing (L4). Enumeration is a public inventory scoped to what an
 * ANONYMOUS caller may view: the generators are called with an explicit
 * {@see AnonymousUser} (R6/M3, closes the audit-M3 leak where a
 * published-but-access-restricted entity — a classification hold, a
 * genealogy privacy rule, etc. — was enumerated regardless of who could
 * actually view it) rather than the requesting session's own account, so the
 * sitemap/llms.txt always reflects the public, unauthenticated surface no
 * matter who (or what bot) requests these routes. Best-effort: any failure
 * degrades to a valid-but-empty document rather than a 500.
 *
 * The default topic/URL model uses system paths (`/{type}/{id}`) and the
 * same-URL Markdown representation (`?format=md`). Apps with path aliases or a
 * curated `llms.topics` config produce nicer URLs; this is the zero-config
 * default (A-001).
 *
 * `/robots.txt` advertises the sitemap with an absolute URL taken from
 * {@see CanonicalPublicOrigin} (trusted `APP_URL` / `api_catalog.base_url` /
 * `app.url`). The incoming request Host is never used. Missing or invalid
 * configuration omits the `Sitemap:` line rather than emitting a relative
 * or attacker-controlled URL. `/sitemap.xml` loc generation is unchanged.
 *
 * @api
 */
final class SeoPublicController
{
    /**
     * Entity types that are not part of the public agent-readable surface.
     */
    private const array NON_PUBLIC_TYPES = [
        'user',
        'path_alias',
        'relationship',
        'file',
        'menu_link_content',
        'menu',
        'config',
        'crop',
        'workflow',
        // genealogy m-a (security): genealogy_family / genealogy_event carry a
        // REQUIRED free-text display_name that in practice names living people,
        // with no living/deceased axis to gate per row (unlike genealogy_person,
        // whose living rows the access-aware enumeration already drops). Exclude
        // both from the crawler-facing inventory wholesale — an independent gate
        // alongside GenealogyContentAccessPolicy failing their view closed for
        // non-owners (which the access-aware enumerator already honours; this is
        // defense in depth, and skips the type without a per-row access probe).
        'genealogy_family',
        'genealogy_event',
    ];

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly ?AccountFieldReadScopeInterface $fieldReadScope = null,
        private readonly ?AccountPrincipalFactoryInterface $principalFactory = null,
        private readonly ?CanonicalPublicOrigin $canonicalOrigin = null,
    ) {}

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
            $urls = $this->runAsAnonymous(
                fn(AnonymousUser $anonymous): array => $generator->collectFromEntityTypes(
                    $this->entityTypeManager,
                    fn(string $type, int|string $id): ?string => $this->isPublicType($type)
                        ? sprintf('/%s/%s', $type, rawurlencode((string) $id))
                        : null,
                    account: $anonymous,
                ),
            );
        } catch (\Throwable) {
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
                    static fn(string $type, int|string $id, string $label): array => [
                        'title' => sprintf('%s %s', $type, $id),
                        // Same-URL Markdown representation (Accept negotiation / ?format=md).
                        'url' => sprintf('/%s/%s?format=md', $type, rawurlencode((string) $id)),
                    ],
                    account: $anonymous,
                ),
            );
        } catch (\Throwable) {
            $topics = [];
        }

        $body = $generator->generate(
            'Waaseyaa',
            'Machine-readable index of site content for AI agents. Each linked URL returns clean Markdown.',
            $topics,
        );

        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function isPublicType(string $entityTypeId): bool
    {
        return !\in_array($entityTypeId, self::NON_PUBLIC_TYPES, true);
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

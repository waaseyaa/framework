<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Http;

use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Seo\Llms\LlmsTxtGenerator;
use Waaseyaa\Seo\RobotsTxtGenerator;
use Waaseyaa\Seo\SitemapGenerator;

/**
 * Serves the public, crawler-facing SEO/agent artifacts on stable URLs:
 * `/robots.txt`, `/sitemap.xml`, and `/llms.txt`.
 *
 * Route wiring lives in {@see \Waaseyaa\SSR\SsrServiceProvider::routes()} (L6),
 * not in the L3 `seo` package, which owns only the generators — `seo` must not
 * depend on routing (L4). Enumeration is a public inventory: it uses
 * `accessCheck(false)` (inside the generators) and is best-effort — any failure
 * degrades to a valid-but-empty document rather than a 500.
 *
 * The default topic/URL model uses system paths (`/{type}/{id}`) and the
 * same-URL Markdown representation (`?format=md`). Apps with path aliases or a
 * curated `llms.topics` config produce nicer URLs; this is the zero-config
 * default (A-001).
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
    ];

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    public function robotsTxt(): Response
    {
        $body = new RobotsTxtGenerator()->toText(
            sitemapUrl: '/sitemap.xml',
            disallowPaths: [],
        );

        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemapXml(): Response
    {
        $generator = new SitemapGenerator();

        try {
            $urls = $generator->collectFromEntityTypes(
                $this->entityTypeManager,
                fn(string $type, int|string $id): ?string => $this->isPublicType($type)
                    ? sprintf('/%s/%s', $type, rawurlencode((string) $id))
                    : null,
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
            $topics = $generator->collectTopics(
                $this->entityTypeManager,
                fn(string $type): ?array => $this->isPublicType($type)
                    ? ['title' => $this->humanize($type), 'summary' => sprintf('%s content as Markdown.', $this->humanize($type))]
                    : null,
                static fn(string $type, int|string $id, string $label): array => [
                    'title' => sprintf('%s %s', $type, $id),
                    // Same-URL Markdown representation (Accept negotiation / ?format=md).
                    'url' => sprintf('/%s/%s?format=md', $type, rawurlencode((string) $id)),
                ],
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

    private function humanize(string $entityTypeId): string
    {
        return ucwords(str_replace('_', ' ', $entityTypeId));
    }
}

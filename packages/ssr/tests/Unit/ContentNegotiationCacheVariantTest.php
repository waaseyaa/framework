<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Routing\CacheConfigResolver;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\ContentNegotiation\MediaTypeAcceptNegotiator;
use Waaseyaa\SSR\SsrPageHandler;

/**
 * WP03 correctness gate: the negotiated media type MUST be part of the SSR cache
 * variant so HTML and Markdown can never cross-contaminate the render cache, and
 * the `?raw` toggle MUST resolve to the same representation as `Accept`.
 */
#[CoversClass(SsrPageHandler::class)]
final class ContentNegotiationCacheVariantTest extends TestCase
{
    private const HTML = MediaTypeAcceptNegotiator::HTML;
    private const MD = MediaTypeAcceptNegotiator::MARKDOWN;

    private function handler(): SsrPageHandler
    {
        $etm = new EntityTypeManager(new EventDispatcher());
        $db = DBALDatabase::createSqlite();

        return new SsrPageHandler(
            entityTypeManager: $etm,
            database: $db,
            renderCache: null,
            cacheConfigResolver: new CacheConfigResolver([]),
            discoveryHandler: new DiscoveryApiHandler($etm, $db),
            projectRoot: '/tmp/test-project',
            config: [],
        );
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return [
            'workflow_visibility' => ['state' => 'published'],
            'relationship_navigation' => ['entity' => ['counts' => ['outbound' => 1]]],
        ];
    }

    #[Test]
    public function html_and_markdown_resolve_to_distinct_cache_variants(): void
    {
        $handler = $this->handler();
        $ctx = $this->context();

        $html = $handler->buildSsrCacheVariantLangcode('en', 'full', false, $ctx, self::HTML);
        $markdown = $handler->buildSsrCacheVariantLangcode('en', 'full', false, $ctx, self::MD);

        self::assertNotSame($html, $markdown, 'HTML and Markdown must not share a cache key.');
        self::assertStringEndsWith(':html', $html);
        self::assertStringEndsWith(':md', $markdown);
    }

    #[Test]
    public function cache_variant_is_deterministic_per_media_type(): void
    {
        $handler = $this->handler();
        $ctx = $this->context();

        self::assertSame(
            $handler->buildSsrCacheVariantLangcode('en', 'full', false, $ctx, self::MD),
            $handler->buildSsrCacheVariantLangcode('en', 'full', false, $ctx, self::MD),
        );
    }

    #[Test]
    public function variant_preserves_legacy_prefix_for_default_html(): void
    {
        // The historical prefix must remain stable (existing CDN keys / tests).
        $variant = $this->handler()->buildSsrCacheVariantLangcode('en', 'full', false, $this->context());
        self::assertStringStartsWith('v2:en:full:public:published:', $variant);
        self::assertStringEndsWith(':html', $variant);
    }

    #[Test]
    public function surrogate_headers_carry_the_media_dimension(): void
    {
        $handler = $this->handler();

        $md = $handler->buildRenderSurrogateHeaders('node', '42', 'full', 'en', 'v2:x', $this->context(), self::MD);
        self::assertStringContainsString('waaseyaa:ssr:media:md', $md['Surrogate-Key']);

        $html = $handler->buildRenderSurrogateHeaders('node', '42', 'full', 'en', 'v2:x', $this->context());
        self::assertStringContainsString('waaseyaa:ssr:media:html', $html['Surrogate-Key']);
    }

    #[Test]
    public function accept_header_markdown_negotiates_markdown(): void
    {
        $request = HttpRequest::create('/x', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/markdown']);
        self::assertSame(self::MD, $this->handler()->negotiateMediaType($request));
    }

    #[Test]
    public function browser_accept_negotiates_html(): void
    {
        $request = HttpRequest::create('/x', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml,*/*;q=0.8']);
        self::assertSame(self::HTML, $this->handler()->negotiateMediaType($request));
    }

    #[Test]
    public function no_accept_header_defaults_to_html(): void
    {
        self::assertSame(self::HTML, $this->handler()->negotiateMediaType(HttpRequest::create('/x')));
    }

    #[Test]
    public function raw_toggle_and_format_override_resolve_to_markdown_like_accept(): void
    {
        // ?raw and ?format=md must resolve to the SAME representation as
        // `Accept: text/markdown`, guaranteeing the toggle returns identical
        // bytes (one presenter path).
        $accept = $this->handler()->negotiateMediaType(
            HttpRequest::create('/x', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/markdown']),
        );
        $raw = $this->handler()->negotiateMediaType(HttpRequest::create('/x?raw=1'));
        $format = $this->handler()->negotiateMediaType(HttpRequest::create('/x?format=md'));

        self::assertSame(self::MD, $accept);
        self::assertSame($accept, $raw);
        self::assertSame($accept, $format);
    }

    #[Test]
    public function format_html_override_beats_accept_markdown(): void
    {
        $request = HttpRequest::create('/x?format=html', 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/markdown']);
        self::assertSame(self::HTML, $this->handler()->negotiateMediaType($request));
    }
}

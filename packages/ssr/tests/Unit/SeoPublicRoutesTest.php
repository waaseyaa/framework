<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\Routing\Route;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\HttpServiceResolverInterface;
use Waaseyaa\Routing\CacheConfigResolver;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\SSR\Http\CanonicalPublicOrigin;
use Waaseyaa\SSR\Http\SeoPublicController;
use Waaseyaa\SSR\SsrPageHandler;
use Waaseyaa\SSR\SsrServiceProvider;
use Waaseyaa\User\AnonymousUser;

#[CoversClass(SeoPublicController::class)]
#[CoversClass(CanonicalPublicOrigin::class)]
#[CoversClass(SsrServiceProvider::class)]
final class SeoPublicRoutesTest extends TestCase
{
    private function controller(?CanonicalPublicOrigin $origin = null): SeoPublicController
    {
        return new SeoPublicController(
            new EntityTypeManager(new EventDispatcher()),
            canonicalOrigin: $origin,
        );
    }

    private function origin(string $value): CanonicalPublicOrigin
    {
        $origin = CanonicalPublicOrigin::tryFrom($value);
        self::assertNotNull($origin);

        return $origin;
    }

    #[Test]
    public function provider_registers_the_three_public_routes_with_priority(): void
    {
        $router = new WaaseyaaRouter();
        new SsrServiceProvider()->routes($router, new EntityTypeManager(new EventDispatcher()));

        $routes = $router->getRouteCollection();
        foreach (['seo.robots_txt' => '/robots.txt', 'seo.sitemap_xml' => '/sitemap.xml', 'seo.llms_txt' => '/llms.txt'] as $name => $path) {
            $route = $routes->get($name);
            self::assertNotNull($route, "Route {$name} must be registered.");
            self::assertSame($path, $route->getPath());
            self::assertSame(10, $route->getOption('_waaseyaa_priority'));
        }
    }

    #[Test]
    public function robots_txt_emits_an_absolute_canonical_sitemap_url(): void
    {
        $response = $this->controller($this->origin('https://example.com'))->robotsTxt();
        $body = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
        self::assertSame("User-agent: *\n\nSitemap: https://example.com/sitemap.xml\n", $body);
        self::assertStringNotContainsString('Sitemap: /sitemap.xml', $body);
    }

    #[Test]
    #[DataProvider('trailingSlashOrigins')]
    public function robots_txt_normalizes_trailing_slashes_on_the_canonical_origin(
        string $configured,
        string $expectedSitemap,
    ): void {
        $body = (string) $this->controller($this->origin($configured))->robotsTxt()->getContent();

        self::assertStringContainsString('Sitemap: ' . $expectedSitemap, $body);
        self::assertSame(1, preg_match_all('/^Sitemap:/m', $body));
    }

    /** @return iterable<string, array{string, string}> */
    public static function trailingSlashOrigins(): iterable
    {
        yield 'apex' => ['https://example.com', 'https://example.com/sitemap.xml'];
        yield 'apex trailing slash' => ['https://example.com/', 'https://example.com/sitemap.xml'];
        yield 'base path' => ['https://example.com/blog', 'https://example.com/blog/sitemap.xml'];
        yield 'base path trailing slash' => ['https://example.com/blog/', 'https://example.com/blog/sitemap.xml'];
    }

    #[Test]
    public function robots_txt_omits_sitemap_when_canonical_origin_is_missing(): void
    {
        $body = (string) $this->controller()->robotsTxt()->getContent();

        self::assertStringContainsString('User-agent: *', $body);
        self::assertStringNotContainsString('Sitemap:', $body);
        self::assertStringNotContainsString('/sitemap.xml', $body);
    }

    #[Test]
    public function robots_txt_omits_sitemap_when_canonical_origin_is_invalid(): void
    {
        $body = (string) $this->controller(CanonicalPublicOrigin::tryFrom('/sitemap.xml'))->robotsTxt()->getContent();

        self::assertStringContainsString('User-agent: *', $body);
        self::assertStringNotContainsString('Sitemap:', $body);
        self::assertStringNotContainsString('/sitemap.xml', $body);
    }

    #[Test]
    public function robots_txt_sitemap_origin_is_not_taken_from_request_host_headers(): void
    {
        $origin = $this->origin('https://example.com');
        $resolver = new class ($origin) implements HttpServiceResolverInterface {
            public function __construct(private readonly CanonicalPublicOrigin $origin) {}

            public function resolve(string $className): ?object
            {
                return $className === CanonicalPublicOrigin::class ? $this->origin : null;
            }
        };

        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $database = $this->createStub(DatabaseInterface::class);
        $handler = new SsrPageHandler(
            entityTypeManager: $entityTypeManager,
            database: $database,
            renderCache: null,
            cacheConfigResolver: new CacheConfigResolver(),
            discoveryHandler: new DiscoveryApiHandler($entityTypeManager, $database),
            projectRoot: sys_get_temp_dir() . '/waaseyaa_seo_host_' . uniqid(),
            config: [],
            serviceResolver: $resolver,
        );

        $request = HttpRequest::create(
            'https://evil.example/robots.txt',
            'GET',
            server: [
                'HTTP_HOST' => 'evil.example',
                'HTTP_X_FORWARDED_HOST' => 'attacker.example',
                'HTTP_FORWARDED' => 'host=attacker.example',
            ],
        );
        $request->attributes->set('_controller', SeoPublicController::class . '::robotsTxt');
        $request->attributes->set('_route', 'seo.robots_txt');
        $request->attributes->set('_route_object', new Route('/robots.txt'));

        $response = $handler->dispatchAppController(
            SeoPublicController::class . '::robotsTxt',
            new AnonymousUser(),
            $request,
        );

        self::assertInstanceOf(\Symfony\Component\HttpFoundation\Response::class, $response);
        $body = (string) $response->getContent();
        self::assertStringContainsString('Sitemap: https://example.com/sitemap.xml', $body);
        self::assertStringNotContainsString('evil.example', $body);
        self::assertStringNotContainsString('attacker.example', $body);
        self::assertSame('evil.example', $request->getHost());
        self::assertSame('attacker.example', $request->headers->get('X-Forwarded-Host'));
    }

    #[Test]
    public function sitemap_xml_is_well_formed_even_when_empty(): void
    {
        $response = $this->controller()->sitemapXml();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/xml', (string) $response->headers->get('Content-Type'));
        $body = (string) $response->getContent();
        self::assertStringContainsString('<?xml version="1.0"', $body);
        self::assertStringContainsString('<urlset', $body);
        // Valid XML.
        self::assertNotFalse(simplexml_load_string($body));
    }

    #[Test]
    public function sitemap_xml_is_unchanged_when_a_canonical_origin_is_configured(): void
    {
        $withoutOrigin = (string) $this->controller()->sitemapXml()->getContent();
        $withOrigin = (string) $this->controller($this->origin('https://example.com'))->sitemapXml()->getContent();

        self::assertSame($withoutOrigin, $withOrigin);
        self::assertStringNotContainsString('https://example.com', $withOrigin);
    }

    #[Test]
    public function llms_txt_is_an_index_document(): void
    {
        $response = $this->controller()->llmsTxt();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
        $body = (string) $response->getContent();
        self::assertStringContainsString('# Waaseyaa', $body);
        // It is an index, never an inlined corpus.
        self::assertStringNotContainsString('llms-full', $body);
    }

    #[Test]
    public function llms_txt_is_unchanged_when_a_canonical_origin_is_configured(): void
    {
        $withoutOrigin = (string) $this->controller()->llmsTxt()->getContent();
        $withOrigin = (string) $this->controller($this->origin('https://example.com'))->llmsTxt()->getContent();

        self::assertSame($withoutOrigin, $withOrigin);
    }

    #[Test]
    public function provider_binds_canonical_origin_from_trusted_catalog_config(): void
    {
        $this->withClearedAppUrl(function (): void {
            $provider = new SsrServiceProvider();
            $provider->setKernelContext('/tmp', [
                'api_catalog' => ['base_url' => 'https://example.com/base/'],
            ], []);
            $provider->register();

            $origin = $provider->resolve(CanonicalPublicOrigin::class);
            self::assertInstanceOf(CanonicalPublicOrigin::class, $origin);
            self::assertSame('https://example.com/base/sitemap.xml', $origin->sitemapUrl());
        });
    }

    #[Test]
    public function provider_does_not_bind_an_invalid_configured_origin(): void
    {
        $this->withClearedAppUrl(function (): void {
            $provider = new SsrServiceProvider();
            $provider->setKernelContext('/tmp', [
                'api_catalog' => ['base_url' => 'http://example.com'],
            ], []);
            $provider->register();

            self::assertArrayNotHasKey(CanonicalPublicOrigin::class, $provider->getBindings());
        });
    }

    private function withClearedAppUrl(callable $callback): void
    {
        $previous = getenv('APP_URL');
        putenv('APP_URL');
        unset($_ENV['APP_URL'], $_SERVER['APP_URL']);
        try {
            $callback();
        } finally {
            if ($previous === false) {
                putenv('APP_URL');
                unset($_ENV['APP_URL'], $_SERVER['APP_URL']);
            } else {
                putenv('APP_URL=' . $previous);
                $_ENV['APP_URL'] = $_SERVER['APP_URL'] = $previous;
            }
        }
    }
}

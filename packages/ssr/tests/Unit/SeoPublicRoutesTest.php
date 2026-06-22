<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\SSR\Http\SeoPublicController;
use Waaseyaa\SSR\SsrServiceProvider;

#[CoversClass(SeoPublicController::class)]
#[CoversClass(SsrServiceProvider::class)]
final class SeoPublicRoutesTest extends TestCase
{
    private function controller(): SeoPublicController
    {
        return new SeoPublicController(new EntityTypeManager(new EventDispatcher()));
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
    public function robots_txt_points_at_the_sitemap(): void
    {
        $response = $this->controller()->robotsTxt();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('Sitemap: /sitemap.xml', (string) $response->getContent());
        self::assertStringContainsString('User-agent: *', (string) $response->getContent());
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
}

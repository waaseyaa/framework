<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\Controller\AiCatalogController;
use Waaseyaa\Api\Discovery\AiCatalog;
use Waaseyaa\Foundation\Discovery\AiCatalog\AiCatalogEntry;
use Waaseyaa\Foundation\Http\Request;

final class AiCatalogControllerTest extends TestCase
{
    #[Test]
    public function public_catalog_has_exact_type_cors_cache_and_canonical_links(): void
    {
        $controller = new AiCatalogController(new AiCatalog('https://canonical.example', [
            new AiCatalogEntry('mcp:public', 'Public MCP', 'application/json', '/.well-known/mcp.json'),
        ]));
        $request = Request::create('https://attacker.example/.well-known/ai-catalog.json', 'GET');
        $request->headers->set('X-Forwarded-Host', 'also-attacker.example');

        $response = $controller->serve($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(AiCatalog::MEDIA_TYPE, $response->headers->get('Content-Type'));
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
        self::assertSame('max-age=300, public', $response->headers->get('Cache-Control'));
        self::assertNotNull($response->headers->get('ETag'));
        self::assertStringContainsString('rel="ai-catalog"', (string) $response->headers->get('Link'));
        self::assertStringNotContainsString('attacker.example', (string) $response->getContent());
    }

    #[Test]
    public function head_and_conditional_get_are_bodyless(): void
    {
        $controller = new AiCatalogController(new AiCatalog('https://canonical.example', [
            new AiCatalogEntry('api:catalog', 'Public APIs', 'application/linkset+json', '/.well-known/api-catalog'),
        ]));
        $get = $controller->serve(Request::create(AiCatalog::PATH, 'GET'));
        $head = $controller->serve(Request::create(AiCatalog::PATH, 'HEAD'));
        $conditional = Request::create(AiCatalog::PATH, 'GET');
        $conditional->headers->set('If-None-Match', (string) $get->headers->get('ETag'));

        self::assertSame('', $head->getContent());
        self::assertSame(304, $controller->serve($conditional)->getStatusCode());
        self::assertSame('', $controller->serve($conditional)->getContent());
    }

    #[Test]
    public function explicit_rejection_of_catalog_type_beats_a_permissive_wildcard(): void
    {
        $controller = new AiCatalogController(new AiCatalog('https://canonical.example', [
            new AiCatalogEntry('api:catalog', 'Public APIs', 'application/linkset+json', '/.well-known/api-catalog'),
        ]));
        $request = Request::create(AiCatalog::PATH, 'GET');
        $request->headers->set('Accept', 'application/ai-catalog+json;q=0, */*;q=1');

        $response = $controller->serve($request);
        self::assertSame(406, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\Controller\ApiCatalogController;
use Waaseyaa\Api\Discovery\ApiCatalog;
use Waaseyaa\Foundation\Discovery\ApiCatalog\ApiCatalogEntry;
use Waaseyaa\Foundation\Discovery\ApiCatalog\ApiCatalogTarget;
use Waaseyaa\Foundation\Http\Request;

#[CoversClass(ApiCatalogController::class)]
final class ApiCatalogControllerTest extends TestCase
{
    private function controller(): ApiCatalogController
    {
        return new ApiCatalogController(new ApiCatalog('https://canonical.example', [
            new ApiCatalogEntry(new ApiCatalogTarget('/mcp', 'application/json', 'MCP')),
        ]));
    }

    #[Test]
    public function get_returns_the_profiled_json_linkset_and_never_reflects_host_headers(): void
    {
        $request = Request::create('https://attacker.example/.well-known/api-catalog', 'GET');
        $request->headers->set('X-Forwarded-Host', 'also-attacker.example');

        $response = $this->controller()->serve($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'application/linkset+json; profile="https://www.rfc-editor.org/info/rfc9727"',
            $response->headers->get('Content-Type'),
        );
        self::assertSame('max-age=300, public', $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('rel="api-catalog"', (string) $response->headers->get('Link'));
        self::assertStringNotContainsString('attacker.example', (string) $response->getContent());
        self::assertSame(
            ['linkset'],
            array_keys(json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR)),
        );
    }

    #[Test]
    public function head_has_no_body_and_mirrors_get_representation_headers(): void
    {
        $controller = $this->controller();
        $get = $controller->serve(Request::create('/.well-known/api-catalog', 'GET'));
        $head = $controller->serve(Request::create('/.well-known/api-catalog', 'HEAD'));

        self::assertSame('', $head->getContent());
        foreach (['Content-Type', 'Content-Length', 'Cache-Control', 'ETag', 'Link', 'X-Content-Type-Options'] as $header) {
            self::assertSame($get->headers->get($header), $head->headers->get($header), $header);
        }
    }

    #[Test]
    public function unsupported_representation_is_rejected_without_content_sniffing(): void
    {
        $request = Request::create('/.well-known/api-catalog', 'GET');
        $request->headers->set('Accept', 'text/html');

        $response = $this->controller()->serve($request);

        self::assertSame(406, $response->getStatusCode());
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    #[Test]
    public function explicit_rejection_of_linkset_beats_a_permissive_wildcard(): void
    {
        $request = Request::create('/.well-known/api-catalog', 'GET');
        $request->headers->set('Accept', 'application/linkset+json;q=0, */*;q=1');

        self::assertSame(406, $this->controller()->serve($request)->getStatusCode());
    }

    #[Test]
    public function matching_if_none_match_returns_a_bodyless_304_with_cache_metadata(): void
    {
        $controller = $this->controller();
        $etag = $controller->serve(Request::create('/.well-known/api-catalog'))->headers->get('ETag');
        self::assertNotNull($etag);
        $request = Request::create('/.well-known/api-catalog');
        $request->headers->set('If-None-Match', 'W/' . $etag);

        $response = $controller->serve($request);

        self::assertSame(304, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertSame($etag, $response->headers->get('ETag'));
        self::assertNull($response->headers->get('Content-Length'));
    }
}

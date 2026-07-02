<?php

declare(strict_types=1);

namespace Waaseyaa\Routing\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Routing\Exception\RouteNotFoundException;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class WaaseyaaRouterTest extends TestCase
{
    #[Test]
    public function duplicate_route_name_throws(): void
    {
        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        $route = RouteBuilder::create('/a')->controller('x')->methods('GET')->build();
        $router->addRoute('dup', $route);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate route name registered: dup');
        $router->addRoute('dup', $route);
    }

    #[Test]
    public function sortRoutesByPriority_orders_higher_first(): void
    {
        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        $router->addRoute('low', RouteBuilder::create('/conflict')->priority(0)->controller('low')->methods('GET')->build());
        $router->addRoute('high', RouteBuilder::create('/conflict')->priority(10)->controller('high')->methods('GET')->build());
        $router->sortRoutesByPriority();
        $params = $router->match('/conflict');
        $this->assertSame('high', $params['_route']);
    }

    #[Test]
    public function priority_static_route_beats_earlier_dynamic_catchall(): void
    {
        // Regression for framework#1532: `/api/user/me` was treated as a literal
        // entity id by `/api/user/{id}` because JsonApiRouteProvider registered
        // the dynamic route first. Bumping the static route's priority must
        // make it match before the catch-all regardless of registration order.
        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        $router->addRoute(
            'api.user.show',
            RouteBuilder::create('/api/user/{id}')->controller('Show')->methods('GET')->build(),
        );
        $router->addRoute(
            'api.user.me',
            RouteBuilder::create('/api/user/me')->priority(10)->controller('Me')->methods('GET')->build(),
        );
        $router->sortRoutesByPriority();

        $params = $router->match('/api/user/me');
        $this->assertSame('api.user.me', $params['_route']);

        $params = $router->match('/api/user/42');
        $this->assertSame('api.user.show', $params['_route']);
    }

    #[Test]
    public function matches_percent_encoded_unicode_slug_and_decodes_param(): void
    {
        // Unicode slugs (Indigenous orthography — syllabics, long-vowel
        // diacritics) arrive percent-encoded on the wire; the matcher must
        // decode them back to the stored slug so alias/entity lookups match.
        $slug = 'ᐊᓂᔑᓈᐯᒧᐎᓐ-ākí';
        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        $router->addRoute(
            'content.view',
            RouteBuilder::create('/content/{slug}')->controller('X')->methods('GET')->build(),
        );

        $params = $router->match('/content/' . rawurlencode($slug));
        $this->assertSame('content.view', $params['_route']);
        $this->assertSame($slug, $params['slug']);
    }

    #[Test]
    public function generates_percent_encoded_url_for_unicode_slug(): void
    {
        $slug = 'ᐊᓂᔑᓈᐯᒧᐎᓐ-ākí';
        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        $router->addRoute(
            'content.view',
            RouteBuilder::create('/content/{slug}')->controller('X')->methods('GET')->build(),
        );

        $url = $router->generate('content.view', ['slug' => $slug]);
        $this->assertSame('/content/' . rawurlencode($slug), $url);
        $this->assertSame($slug, rawurldecode(basename($url)), 'generated URL must round-trip to the original slug');
    }

    #[Test]
    public function match_throws_route_not_found_for_unknown_path(): void
    {
        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        $router->addRoute('a', RouteBuilder::create('/known')->controller('X::a')->methods('GET')->build());

        $this->expectException(RouteNotFoundException::class);
        $router->match('/unknown');
    }
}

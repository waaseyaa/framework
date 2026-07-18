<?php

declare(strict_types=1);

namespace Waaseyaa\Routing\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
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
    public function priority_sort_matches_the_stable_reference_for_randomized_duplicate_priorities(): void
    {
        mt_srand(20_641);
        $router = new WaaseyaaRouter();
        $reference = [];
        for ($index = 0; $index < 200; ++$index) {
            $priority = mt_rand(-8, 8);
            $name = 'route_' . $index;
            $router->addRoute($name, new Route('/' . $name, options: ['_waaseyaa_priority' => $priority]));
            $reference[] = ['name' => $name, 'priority' => $priority, 'index' => $index];
        }
        usort($reference, static fn(array $left, array $right): int =>
            $right['priority'] <=> $left['priority'] ?: $left['index'] <=> $right['index']);

        $router->sortRoutesByPriority();

        self::assertSame(
            array_column($reference, 'name'),
            array_keys($router->getRouteCollection()->all()),
        );
    }

    #[Test]
    public function priority_sort_reads_each_route_priority_once_for_a_full_route_table(): void
    {
        $router = new WaaseyaaRouter();
        CountingPriorityRoute::$priorityReads = 0;
        for ($index = 0; $index < 554; ++$index) {
            $router->addRoute(
                'route_' . $index,
                new CountingPriorityRoute('/route-' . $index, options: ['_waaseyaa_priority' => ($index % 13) - 6]),
            );
        }

        $router->sortRoutesByPriority();

        self::assertSame(554, CountingPriorityRoute::$priorityReads);
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

final class CountingPriorityRoute extends Route
{
    public static int $priorityReads = 0;

    public function getOption(string $name): mixed
    {
        if ($name === '_waaseyaa_priority') {
            ++self::$priorityReads;
        }

        return parent::getOption($name);
    }
}

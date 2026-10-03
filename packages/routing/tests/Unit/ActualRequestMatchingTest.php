<?php

declare(strict_types=1);

namespace Waaseyaa\Routing\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\Routing\Metadata\RouteSnapshot;
use Waaseyaa\Routing\Exception\RouteNotFoundException;
use Waaseyaa\Routing\WaaseyaaRouter;

final class ActualRequestMatchingTest extends TestCase
{
    public function testActualRequestConditionsAndStrippedPathKeepOriginalRequest(): void
    {
        $snapshot = new RouteSnapshot([new RouteDefinition('actual', '/items/{id}', HandlerReference::fromString('builtin:render.page'), methods: ['GET'], host: 'alpha.example.test', schemes: ['https'], condition: 'request.headers.get("X-Route") == "yes" and request.getSession().get("allowed") == true and params["id"] == "42" and request.getPathInfo() == "/en/items/42"', sourceId: 'fixture')], []);
        $request = Request::create('https://alpha.example.test/en/items/42');
        $request->headers->set('X-Route', 'yes');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->getSession()->set('allowed', true);
        $router = new WaaseyaaRouter(snapshot: $snapshot);
        self::assertSame('actual', $router->matchRequest($request, '/items/42')['_route']);
        self::assertSame('/en/items/42', $request->getPathInfo());
        self::assertSame('/items/7', $router->generate('actual', ['id' => 7]));
        $request->headers->set('X-Route', 'no');
        $this->expectException(RouteNotFoundException::class);
        $router->matchRequest($request, '/items/42');
    }

    public function testMatchingDoesNotRetainTheActualRequestForLaterPathMatching(): void
    {
        $snapshot = new RouteSnapshot([new RouteDefinition('actual', '/items', HandlerReference::fromString('builtin:render.page'), condition: 'request.headers.get("X-Route") == "yes"', sourceId: 'fixture')], []);
        $request = Request::create('/items');
        $request->headers->set('X-Route', 'yes');
        $router = new WaaseyaaRouter(snapshot: $snapshot);
        self::assertSame('actual', $router->matchRequest($request)['_route']);
        $this->expectException(RouteNotFoundException::class);
        $router->match('/items');
    }
}

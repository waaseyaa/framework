<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\Router\GraphQlRouter;

#[CoversClass(GraphQlRouter::class)]
final class GraphQlRouterTest extends TestCase
{
    #[Test]
    public function supports_graphql_endpoint(): void
    {
        $etm = new EntityTypeManager(new EventDispatcher());
        $router = new GraphQlRouter($etm, new EntityAccessHandler());
        $request = Request::create('/api/graphql');
        $request->attributes->set('_controller', 'graphql.endpoint');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function does_not_support_unrelated(): void
    {
        $etm = new EntityTypeManager(new EventDispatcher());
        $router = new GraphQlRouter($etm, new EntityAccessHandler());
        $request = Request::create('/api/mcp');
        $request->attributes->set('_controller', 'mcp.endpoint');
        self::assertFalse($router->supports($request));
    }
}

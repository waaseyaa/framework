<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\GraphQL\Http\Router\GraphQlRouter;

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

    #[Test]
    public function propagates_the_endpoint_http_status(): void
    {
        $etm = new EntityTypeManager(new EventDispatcher());
        $router = new GraphQlRouter($etm, new EntityAccessHandler());
        $request = Request::create('/graphql', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{');
        $account = $this->createStub(AccountInterface::class);
        $account->method('isAuthenticated')->willReturn(false);
        $account->method('id')->willReturn(0);
        $request->attributes->set('_account', $account);
        $request->attributes->set('_broadcast_storage', new BroadcastStorage(DBALDatabase::createSqlite()));

        $response = $router->handle($request);

        self::assertSame(400, $response->getStatusCode());
    }
}

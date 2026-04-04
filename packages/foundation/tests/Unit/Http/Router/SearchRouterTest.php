<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Foundation\Http\Router\SearchRouter;

#[CoversClass(SearchRouter::class)]
final class SearchRouterTest extends TestCase
{
    #[Test]
    public function supports_search_semantic(): void
    {
        $router = new SearchRouter([], \Waaseyaa\Database\DBALDatabase::createSqlite());
        $request = Request::create('/api/search');
        $request->attributes->set('_controller', 'search.semantic');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function does_not_support_unrelated(): void
    {
        $router = new SearchRouter([], \Waaseyaa\Database\DBALDatabase::createSqlite());
        $request = Request::create('/api/mcp');
        $request->attributes->set('_controller', 'mcp.endpoint');
        self::assertFalse($router->supports($request));
    }

    #[Test]
    public function handle_returns_400_when_missing_query_params(): void
    {
        $db = \Waaseyaa\Database\DBALDatabase::createSqlite();
        $router = new SearchRouter([], $db);

        $account = $this->createStub(\Waaseyaa\Access\AccountInterface::class);
        $broadcastStorage = new \Waaseyaa\Api\Controller\BroadcastStorage($db);

        $request = Request::create('/api/search');
        $request->attributes->set('_controller', 'search.semantic');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_broadcast_storage', $broadcastStorage);
        $request->attributes->set('_parsed_body', null);
        $request->attributes->set('_waaseyaa_context',
            \Waaseyaa\Foundation\Http\Router\WaaseyaaContext::fromRequest($request)
        );

        $response = $router->handle($request);

        self::assertSame(400, $response->getStatusCode());
    }
}

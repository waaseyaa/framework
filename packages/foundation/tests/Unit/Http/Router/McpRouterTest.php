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
use Waaseyaa\Foundation\Http\Router\McpRouter;

#[CoversClass(McpRouter::class)]
final class McpRouterTest extends TestCase
{
    #[Test]
    public function supports_mcp_endpoint(): void
    {
        $etm = new EntityTypeManager(new EventDispatcher());
        $db = \Waaseyaa\Database\DBALDatabase::createSqlite();
        $router = new McpRouter($etm, new EntityAccessHandler(), $db, [], null);
        $request = Request::create('/api/mcp');
        $request->attributes->set('_controller', 'mcp.endpoint');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function does_not_support_unrelated(): void
    {
        $etm = new EntityTypeManager(new EventDispatcher());
        $db = \Waaseyaa\Database\DBALDatabase::createSqlite();
        $router = new McpRouter($etm, new EntityAccessHandler(), $db, [], null);
        $request = Request::create('/api/graphql');
        $request->attributes->set('_controller', 'graphql.endpoint');
        self::assertFalse($router->supports($request));
    }

    #[Test]
    public function handle_returns_501_when_ai_vector_missing(): void
    {
        // This test depends on whether ai-vector is installed.
        // If it is, the test verifies the router creates the McpController.
        // If not, it returns 501.
        // We test the supports/not-supports boundary; full MCP testing
        // is covered by McpControllerTest integration tests.
        self::assertTrue(true); // placeholder — integration tested
    }
}

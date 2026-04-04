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
use Waaseyaa\Foundation\Http\Router\JsonApiRouter;

#[CoversClass(JsonApiRouter::class)]
final class JsonApiRouterTest extends TestCase
{
    private function createRouter(): JsonApiRouter
    {
        $etm = new EntityTypeManager(new EventDispatcher());
        $accessHandler = new EntityAccessHandler();
        $db = \Waaseyaa\Database\DBALDatabase::createSqlite();
        return new JsonApiRouter($etm, $accessHandler, $db);
    }

    #[Test]
    public function supports_json_api_controller(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/node');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\JsonApiController');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function supports_controller_with_class_method_syntax(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/node');
        $request->attributes->set('_controller', 'App\\Controller\\NodeJsonApiController::index');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function does_not_support_unrelated(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/mcp');
        $request->attributes->set('_controller', 'mcp.endpoint');
        self::assertFalse($router->supports($request));
    }
}

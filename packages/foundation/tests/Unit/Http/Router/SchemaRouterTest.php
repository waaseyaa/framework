<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\Router\SchemaRouter;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;
use Waaseyaa\Access\AuthorizationPrincipalInterface;

#[CoversClass(SchemaRouter::class)]
final class SchemaRouterTest extends TestCase
{
    private function createRouter(): SchemaRouter
    {
        $etm = new EntityTypeManager(new EventDispatcher());
        $accessHandler = new EntityAccessHandler();
        return new SchemaRouter($etm, $accessHandler);
    }

    #[Test]
    public function supports_openapi_controller(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/openapi');
        $request->attributes->set('_controller', 'openapi');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function supports_schema_controller(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/schema/node');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\SchemaController');
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

    #[Test]
    public function handle_openapi_returns_json(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/openapi');
        $request->attributes->set('_controller', 'openapi');
        $response = $router->handle($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/vnd.api+json', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function handle_schema_controller_returns_not_found_for_unknown_entity_type(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/schema/missing');
        $request->attributes->set('_controller', 'Waaseyaa\Api\Controller\SchemaController');
        $request->attributes->set('entity_type', 'missing');
        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $request->attributes->set('_account', $account);
        $request->attributes->set('_authorization_principal', $account);
        $database = DBALDatabase::createSqlite();
        RuntimeSchemaMigrations::broadcast($database);
        $request->attributes->set('_broadcast_storage', new BroadcastStorage($database));

        $response = $router->handle($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Unknown entity type: missing.', (string) $response->getContent());
    }
}

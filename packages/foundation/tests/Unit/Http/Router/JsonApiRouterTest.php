<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Api\InternalFieldVisibilityPolicy;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\Router\JsonApiRouter;

#[CoversClass(JsonApiRouter::class)]
final class JsonApiRouterTest extends TestCase
{
    private function createRouter(): JsonApiRouter
    {
        return $this->createRouterFixture()['router'];
    }

    /**
     * @return array{router: JsonApiRouter, database: \Waaseyaa\Database\DBALDatabase}
     */
    private function createRouterFixture(?InternalFieldVisibilityPolicy $internalFieldVisibility = null): array
    {
        $etm = new EntityTypeManager(new EventDispatcher());
        $accessHandler = new EntityAccessHandler();
        $db = \Waaseyaa\Database\DBALDatabase::createSqlite();

        return [
            'router' => new JsonApiRouter($etm, $accessHandler, $db, internalFieldVisibility: $internalFieldVisibility),
            'database' => $db,
        ];
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
    public function getDispatchesWithTheInjectedInternalVisibilityPolicy(): void
    {
        $fixture = $this->createRouterFixture(new InternalFieldVisibilityPolicy([
            'node' => ['legacy_origin'],
        ]));
        $router = $fixture['router'];
        $database = $fixture['database'];
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::broadcast($database);
        $request = Request::create('/api/missing');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\JsonApiController::index');
        $request->attributes->set('_entity_type', 'missing');
        $request->attributes->set('_account', new AuthorizationPrincipal(1, true, [], [], 'test'));
        $request->attributes->set('_broadcast_storage', new BroadcastStorage($database));

        $response = $router->handle($request);

        self::assertSame(404, $response->getStatusCode());
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
    public function patch_without_if_match_is_rejected_before_dispatch(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/node/1', 'PATCH', content: '{}');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\JsonApiController::update');
        $request->attributes->set('_entity_type', 'node');
        $request->attributes->set('id', '1');

        $response = $router->handle($request);

        self::assertSame(428, $response->getStatusCode());
        self::assertSame('MUTATION_PRECONDITION_REQUIRED', json_decode((string) $response->getContent(), true)['errors'][0]['code']);
    }

    #[Test]
    public function weak_or_wildcard_if_match_is_rejected_before_dispatch(): void
    {
        $router = $this->createRouter();
        foreach (['W/"anything"', '*', '"one", "two"'] as $ifMatch) {
            $request = Request::create('/api/node/1', 'DELETE');
            $request->attributes->set('_controller', 'Waaseyaa\\Api\\JsonApiController::destroy');
            $request->attributes->set('_entity_type', 'node');
            $request->attributes->set('id', '1');
            $request->headers->set('If-Match', $ifMatch);

            self::assertSame(400, $router->handle($request)->getStatusCode(), $ifMatch);
        }
    }
}

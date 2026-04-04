<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityTypeLifecycleManager;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\Router\EntityTypeLifecycleRouter;

#[CoversClass(EntityTypeLifecycleRouter::class)]
final class EntityTypeLifecycleRouterTest extends TestCase
{
    private function createRouter(): EntityTypeLifecycleRouter
    {
        $etm = new EntityTypeManager(new EventDispatcher());
        return new EntityTypeLifecycleRouter($etm, new EntityTypeLifecycleManager('/tmp'));
    }

    #[Test]
    public function supports_entity_types_controller(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/entity-types');
        $request->attributes->set('_controller', 'entity_types');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function supports_entity_type_disable(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/entity-types/node/disable', 'POST');
        $request->attributes->set('_controller', 'entity_type.disable');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function supports_entity_type_enable(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/entity-types/node/enable', 'POST');
        $request->attributes->set('_controller', 'entity_type.enable');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function does_not_support_unrelated_controller(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/graphql');
        $request->attributes->set('_controller', 'graphql.endpoint');
        self::assertFalse($router->supports($request));
    }

    #[Test]
    public function handle_entity_types_returns_list(): void
    {
        $router = $this->createRouter();
        $account = $this->createStub(\Waaseyaa\Access\AccountInterface::class);
        $broadcastStorage = new \Waaseyaa\Api\Controller\BroadcastStorage(\Waaseyaa\Database\DBALDatabase::createSqlite());
        $request = Request::create('/api/entity-types');
        $request->attributes->set('_controller', 'entity_types');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_broadcast_storage', $broadcastStorage);
        $request->attributes->set('_parsed_body', null);

        $response = $router->handle($request);
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('data', $data);
        self::assertIsArray($data['data']);
    }
}

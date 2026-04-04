<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\Router\DiscoveryRouter;

#[CoversClass(DiscoveryRouter::class)]
final class DiscoveryRouterTest extends TestCase
{
    private function createRouter(): DiscoveryRouter
    {
        $etm = new EntityTypeManager(new EventDispatcher());
        $db = DBALDatabase::createSqlite();
        $handler = new DiscoveryApiHandler($etm, $db);
        return new DiscoveryRouter($handler, $etm);
    }

    #[Test]
    public function supports_discovery_topic_hub(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/discovery/topic-hub/node/1');
        $request->attributes->set('_controller', 'discovery.topic_hub');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function supports_discovery_cluster(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/discovery/cluster/node/1');
        $request->attributes->set('_controller', 'discovery.cluster');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function supports_api_discovery_controller(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/discovery');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\ApiDiscoveryController');
        self::assertTrue($router->supports($request));
    }

    #[Test]
    public function does_not_support_unrelated(): void
    {
        $router = $this->createRouter();
        $request = Request::create('/api/graphql');
        $request->attributes->set('_controller', 'graphql.endpoint');
        self::assertFalse($router->supports($request));
    }
}

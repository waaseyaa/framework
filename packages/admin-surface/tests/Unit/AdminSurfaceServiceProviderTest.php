<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\AdminSurface\AdminSurfaceServiceProvider;
use Waaseyaa\AdminSurface\Catalog\CatalogBuilder;
use Waaseyaa\AdminSurface\Host\AbstractAdminSurfaceHost;
use Waaseyaa\AdminSurface\Host\AdminSurfaceResultData;
use Waaseyaa\AdminSurface\Host\AdminSurfaceSessionData;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversClass(AdminSurfaceServiceProvider::class)]
#[CoversClass(AbstractAdminSurfaceHost::class)]
final class AdminSurfaceServiceProviderTest extends TestCase
{
    private AbstractAdminSurfaceHost $host;
    private AdminSurfaceSessionData $session;

    protected function setUp(): void
    {
        $this->session = new AdminSurfaceSessionData(
            accountId: '42',
            accountName: 'Test Admin',
            roles: ['administrator'],
            policies: ['admin_access'],
            email: 'admin@example.com',
            tenantId: 'default',
            tenantName: 'Default',
            features: ['content_editing' => true],
        );

        $this->host = $this->createTestHost($this->session);
    }

    #[Test]
    public function registerRoutesAddsAllFiveExpectedRoutes(): void
    {
        $router = new WaaseyaaRouter();

        AdminSurfaceServiceProvider::registerRoutes($router, $this->host);

        $collection = $router->getRouteCollection();
        $routeNames = array_keys(iterator_to_array($collection->getIterator()));

        $this->assertCount(5, $routeNames);
        $this->assertContains('admin_surface.session', $routeNames);
        $this->assertContains('admin_surface.catalog', $routeNames);
        $this->assertContains('admin_surface.list', $routeNames);
        $this->assertContains('admin_surface.get', $routeNames);
        $this->assertContains('admin_surface.action', $routeNames);
    }

    #[Test]
    public function registerRoutesUsesCorrectPaths(): void
    {
        $router = new WaaseyaaRouter();

        AdminSurfaceServiceProvider::registerRoutes($router, $this->host);

        $collection = $router->getRouteCollection();

        $this->assertSame('/admin/surface/session', $collection->get('admin_surface.session')->getPath());
        $this->assertSame('/admin/surface/catalog', $collection->get('admin_surface.catalog')->getPath());
        $this->assertSame('/admin/surface/{type}', $collection->get('admin_surface.list')->getPath());
        $this->assertSame('/admin/surface/{type}/{id}', $collection->get('admin_surface.get')->getPath());
        $this->assertSame('/admin/surface/{type}/action/{action}', $collection->get('admin_surface.action')->getPath());
    }

    #[Test]
    public function handleSessionReturnsSessionDataStructure(): void
    {
        $request = Request::create('/admin/surface/session');

        $result = $this->host->handleSession($request);

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('data', $result);

        $data = $result['data'];
        $this->assertArrayHasKey('account', $data);
        $this->assertArrayHasKey('tenant', $data);
        $this->assertArrayHasKey('policies', $data);
        $this->assertArrayHasKey('features', $data);

        $this->assertSame('42', $data['account']['id']);
        $this->assertSame('Test Admin', $data['account']['name']);
        $this->assertSame('admin@example.com', $data['account']['email']);
        $this->assertSame(['administrator'], $data['account']['roles']);

        $this->assertSame('default', $data['tenant']['id']);
        $this->assertSame('Default', $data['tenant']['name']);

        $this->assertSame(['admin_access'], $data['policies']);
        $this->assertSame(['content_editing' => true], (array) $data['features']);
    }

    #[Test]
    public function handleSessionReturnsUnauthorizedWhenSessionIsNull(): void
    {
        $host = $this->createTestHost(null);
        $request = Request::create('/admin/surface/session');

        $result = $host->handleSession($request);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame(401, $result['error']['status']);
        $this->assertSame('Unauthorized', $result['error']['title']);
    }

    #[Test]
    public function handleCatalogReturnsEntityDefinitions(): void
    {
        $request = Request::create('/admin/surface/catalog');

        $result = $this->host->handleCatalog($request);

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('entities', $result['data']);

        $entities = $result['data']['entities'];
        $this->assertCount(1, $entities);

        $entity = $entities[0];
        $this->assertSame('article', $entity['id']);
        $this->assertSame('Article', $entity['label']);
        $this->assertSame('content', $entity['group']);
        $this->assertArrayHasKey('capabilities', $entity);
        $this->assertTrue($entity['capabilities']['list']);
        $this->assertTrue($entity['capabilities']['get']);
        $this->assertTrue($entity['capabilities']['create']);
    }

    #[Test]
    public function handleCatalogReturnsUnauthorizedWhenSessionIsNull(): void
    {
        $host = $this->createTestHost(null);
        $request = Request::create('/admin/surface/catalog');

        $result = $host->handleCatalog($request);

        $this->assertFalse($result['ok']);
        $this->assertSame(401, $result['error']['status']);
    }

    #[Test]
    public function handleListReturnsEntityList(): void
    {
        $request = Request::create('/admin/surface/article', 'GET', ['status' => 'published']);

        $result = $this->host->handleList($request, 'article');

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('data', $result);
        $this->assertIsArray($result['data']);
        $this->assertCount(2, $result['data']);
        $this->assertSame('1', $result['data'][0]['id']);
        $this->assertSame('First Article', $result['data'][0]['title']);
    }

    #[Test]
    public function handleListReturnsUnauthorizedWhenSessionIsNull(): void
    {
        $host = $this->createTestHost(null);
        $request = Request::create('/admin/surface/article');

        $result = $host->handleList($request, 'article');

        $this->assertFalse($result['ok']);
        $this->assertSame(401, $result['error']['status']);
    }

    #[Test]
    public function handleGetReturnsSingleEntity(): void
    {
        $request = Request::create('/admin/surface/article/1');

        $result = $this->host->handleGet($request, 'article', '1');

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('data', $result);
        $this->assertSame('1', $result['data']['id']);
        $this->assertSame('article', $result['data']['type']);
        $this->assertSame('First Article', $result['data']['title']);
    }

    #[Test]
    public function handleGetReturnsUnauthorizedWhenSessionIsNull(): void
    {
        $host = $this->createTestHost(null);
        $request = Request::create('/admin/surface/article/1');

        $result = $host->handleGet($request, 'article', '1');

        $this->assertFalse($result['ok']);
        $this->assertSame(401, $result['error']['status']);
    }

    private function createTestHost(?AdminSurfaceSessionData $session): AbstractAdminSurfaceHost
    {
        return new class($session) extends AbstractAdminSurfaceHost {
            public function __construct(
                private readonly ?AdminSurfaceSessionData $session,
            ) {}

            public function resolveSession(Request $request): ?AdminSurfaceSessionData
            {
                return $this->session;
            }

            public function buildCatalog(AdminSurfaceSessionData $session): CatalogBuilder
            {
                $catalog = new CatalogBuilder();
                $entity = $catalog->defineEntity('article', 'Article')
                    ->group('content');
                $entity->field('title', 'Title', 'string');
                $entity->field('body', 'Body', 'text');
                return $catalog;
            }

            public function list(string $type, \Waaseyaa\AdminSurface\Query\SurfaceQuery|array $query = []): AdminSurfaceResultData
            {
                return AdminSurfaceResultData::success([
                    ['id' => '1', 'type' => $type, 'title' => 'First Article'],
                    ['id' => '2', 'type' => $type, 'title' => 'Second Article'],
                ]);
            }

            public function get(string $type, string $id): AdminSurfaceResultData
            {
                return AdminSurfaceResultData::success([
                    'id' => $id,
                    'type' => $type,
                    'title' => 'First Article',
                ]);
            }

            public function action(string $type, string $action, array $payload = []): AdminSurfaceResultData
            {
                return AdminSurfaceResultData::success([
                    'action' => $action,
                    'type' => $type,
                    'result' => 'completed',
                ]);
            }
        };
    }
}

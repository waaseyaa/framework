<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit\Host;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AdminSurface\Catalog\CatalogBuilder;
use Waaseyaa\AdminSurface\Host\AbstractAdminSurfaceHost;
use Waaseyaa\AdminSurface\Host\AdminSurfaceResultData;
use Waaseyaa\AdminSurface\Host\AdminSurfaceSessionData;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(AbstractAdminSurfaceHost::class)]
final class AbstractAdminSurfaceHostTest extends TestCase
{
    private function createHost(
        ?AdminSurfaceSessionData $session = null,
        ?CatalogBuilder $catalog = null,
    ): AbstractAdminSurfaceHost {
        return new class ($session, $catalog) extends AbstractAdminSurfaceHost {
            public function __construct(
                private readonly ?AdminSurfaceSessionData $session,
                private readonly ?CatalogBuilder $catalog,
            ) {
            }

            public function resolveSession(Request $request): ?AdminSurfaceSessionData
            {
                return $this->session;
            }

            public function buildCatalog(AdminSurfaceSessionData $session): CatalogBuilder
            {
                return $this->catalog ?? new CatalogBuilder();
            }

            public function list(string $type, \Waaseyaa\AdminSurface\Query\SurfaceQuery|array $query = []): AdminSurfaceResultData
            {
                return AdminSurfaceResultData::success([
                    'entities' => [],
                    'total' => 0,
                ]);
            }

            public function get(string $type, string $id): AdminSurfaceResultData
            {
                return AdminSurfaceResultData::success([
                    'type' => $type,
                    'id' => $id,
                    'attributes' => ['title' => 'Test'],
                ]);
            }

            public function action(string $type, string $action, array $payload = []): AdminSurfaceResultData
            {
                return AdminSurfaceResultData::success(['action' => $action]);
            }
        };
    }

    private function createRequest(string $method = 'GET', string $content = ''): Request
    {
        return Request::create('/admin/surface/session', $method, content: $content);
    }

    #[Test]
    public function handleSessionReturnsUnauthorizedWhenNoSession(): void
    {
        $host = $this->createHost(session: null);
        $request = $this->createRequest();

        $result = $host->handleSession($request);

        self::assertFalse($result['ok']);
        self::assertSame(401, $result['error']['status']);
    }

    #[Test]
    public function handleSessionReturnsSessionData(): void
    {
        $session = new AdminSurfaceSessionData(
            accountId: '1',
            accountName: 'Admin',
            roles: ['admin'],
            policies: ['administer content'],
        );
        $host = $this->createHost(session: $session);
        $request = $this->createRequest();

        $result = $host->handleSession($request);

        self::assertTrue($result['ok']);
        self::assertSame('1', $result['data']['account']['id']);
        self::assertSame(['admin'], $result['data']['account']['roles']);
    }

    #[Test]
    public function handleCatalogReturnsUnauthorizedWhenNoSession(): void
    {
        $host = $this->createHost(session: null);
        $request = $this->createRequest();

        $result = $host->handleCatalog($request);

        self::assertFalse($result['ok']);
        self::assertSame(401, $result['error']['status']);
    }

    #[Test]
    public function handleCatalogReturnsCatalogEntries(): void
    {
        $session = new AdminSurfaceSessionData(
            accountId: '1',
            accountName: 'Admin',
            roles: ['admin'],
            policies: [],
        );
        $catalog = new CatalogBuilder();
        $catalog->defineEntity('node', 'Content')->group('content');

        $host = $this->createHost(session: $session, catalog: $catalog);
        $request = $this->createRequest();

        $result = $host->handleCatalog($request);

        self::assertTrue($result['ok']);
        self::assertCount(1, $result['data']['entities']);
        self::assertSame('node', $result['data']['entities'][0]['id']);
    }

    #[Test]
    public function handleListReturnsUnauthorizedWhenNoSession(): void
    {
        $host = $this->createHost(session: null);
        $request = $this->createRequest();

        $result = $host->handleList($request, 'node');

        self::assertFalse($result['ok']);
    }

    #[Test]
    public function handleListDelegatesToListMethod(): void
    {
        $session = new AdminSurfaceSessionData(
            accountId: '1',
            accountName: 'Admin',
            roles: [],
            policies: [],
        );
        $host = $this->createHost(session: $session);
        $request = $this->createRequest();

        $result = $host->handleList($request, 'node');

        self::assertTrue($result['ok']);
        self::assertSame(0, $result['data']['total']);
    }

    #[Test]
    public function handleGetDelegatesToGetMethod(): void
    {
        $session = new AdminSurfaceSessionData(
            accountId: '1',
            accountName: 'Admin',
            roles: [],
            policies: [],
        );
        $host = $this->createHost(session: $session);
        $request = $this->createRequest();

        $result = $host->handleGet($request, 'node', '42');

        self::assertTrue($result['ok']);
        self::assertSame('42', $result['data']['id']);
    }

    #[Test]
    public function handleActionDelegatesToActionMethod(): void
    {
        $session = new AdminSurfaceSessionData(
            accountId: '1',
            accountName: 'Admin',
            roles: [],
            policies: [],
        );
        $host = $this->createHost(session: $session);
        $request = Request::create(
            '/admin/surface/node/action/publish',
            'POST',
            content: json_encode(['nid' => '7'], JSON_THROW_ON_ERROR),
        );

        $result = $host->handleAction($request, 'node', 'publish');

        self::assertTrue($result['ok']);
        self::assertSame('publish', $result['data']['action']);
    }

    #[Test]
    public function handleActionReturnsUnauthorizedWhenNoSession(): void
    {
        $host = $this->createHost(session: null);
        $request = Request::create('/admin/surface/node/action/publish', 'POST', content: '{}');

        $result = $host->handleAction($request, 'node', 'publish');

        self::assertFalse($result['ok']);
        self::assertSame(401, $result['error']['status']);
    }

    #[Test]
    public function handleActionThrowsOnInvalidJson(): void
    {
        $session = new AdminSurfaceSessionData(
            accountId: '1',
            accountName: 'Admin',
            roles: [],
            policies: [],
        );
        $host = $this->createHost(session: $session);
        $request = Request::create('/admin/surface/node/action/publish', 'POST', content: '{invalid');

        $this->expectException(\JsonException::class);

        $host->handleAction($request, 'node', 'publish');
    }
}

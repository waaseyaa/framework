<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseMercureMonitor;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Access\AccessChecker;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Api\Controller\MercureMonitorController;
use Waaseyaa\Api\Http\Router\MercureMonitorApiRouter;
use Waaseyaa\Api\MercureMonitor\ChannelInspectorInterface;
use Waaseyaa\Api\MercureMonitor\EventStreamReadModelInterface;
use Waaseyaa\Api\MercureMonitor\SubscriberObserverInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\Inbound\ChannelInspector;
use Waaseyaa\Foundation\Http\Inbound\EventStreamReadModel;
use Waaseyaa\Foundation\Http\Inbound\SubscriberObserver;
use Waaseyaa\Foundation\Kernel\BuiltinRouteRegistrar;
use Waaseyaa\Foundation\MercureMonitorServiceProvider;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Kernel-boot integration test for the M5D Mercure broadcast monitor (WP01 T004).
 *
 * FR-008 dead-code-in-production guard: this test MUST FAIL if any of the
 * three MercureMonitorServiceProvider bindings is removed. If any binding is
 * absent, `ApiServiceProvider::resolveOptional()` returns null, the router is
 * not wired (or the controller returns empty shapes), and the assertions below
 * will fail.
 *
 * Asserts:
 *   1. `BuiltinRouteRegistrar` registers all three routes with `_role: admin`.
 *   2. `AccessChecker` admits admin and 403s non-admin on all three routes.
 *   3. `GET /api/mercure/channels` with seeded _broadcast_log → grouped rows.
 *   4. `GET /api/mercure/events` → SSE headers + correct Content-Type.
 *   5. `GET /api/mercure/subscribers` with a fixture subscribers.json → rows.
 *   6. Response bodies contain zero matches for `Authorization`, `Cookie`,
 *      `User-Agent` (NFR-004 identity-leak guard).
 *
 * Spec: kitty-specs/mercure-broadcast-monitor-m5d-01KSEFTD/spec.md (#1415)
 */
#[CoversNothing]
final class MercureMonitorEndpointTest extends TestCase
{
    private DBALDatabase $database;
    private BroadcastStorage $broadcastStorage;
    private EntityTypeManager $entityTypeManager;
    private WaaseyaaRouter $router;
    private string $tmpDir;
    private string $subscribersJsonPath;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        \Waaseyaa\Tests\Support\RuntimeSchemaMigrations::broadcast($this->database);
        $this->broadcastStorage = new BroadcastStorage($this->database);

        $this->tmpDir = sys_get_temp_dir() . '/waaseyaa_monitor_test_' . uniqid();
        mkdir($this->tmpDir, 0o755, true);
        $this->subscribersJsonPath = $this->tmpDir . '/subscribers.json';

        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $this->router = new WaaseyaaRouter(new RequestContext('', 'GET'));

        // WP5: routes are now registered by ApiServiceProvider::routes().
        $registrar = new BuiltinRouteRegistrar($this->entityTypeManager, [new ApiServiceProvider()]);
        $registrar->register($this->router);

        // Seed ≥10 broadcast log rows across ≥3 channels + ≥3 event names
        $this->broadcastStorage->push('admin', 'entity.saved', ['type' => 'node', 'id' => 'uuid-1']);
        $this->broadcastStorage->push('admin', 'entity.deleted', ['type' => 'node', 'id' => 'uuid-2']);
        $this->broadcastStorage->push('admin', 'entity.saved', ['type' => 'user', 'id' => 'uuid-3']);
        $this->broadcastStorage->push('realtime', 'user.connected', ['uid' => 5]);
        $this->broadcastStorage->push('realtime', 'user.disconnected', ['uid' => 5]);
        $this->broadcastStorage->push('realtime', 'entity.saved', ['type' => 'node', 'id' => 'uuid-4']);
        $this->broadcastStorage->push('pipeline', 'job.started', ['job' => 'import-1']);
        $this->broadcastStorage->push('pipeline', 'job.completed', ['job' => 'import-1']);
        $this->broadcastStorage->push('pipeline', 'job.started', ['job' => 'import-2']);
        $this->broadcastStorage->push('admin', 'workflow.transitioned', ['from' => 'draft', 'to' => 'review']);

        // Write fixture subscribers.json with one active row
        $this->writeSubscribersJson([
            [
                'accountId' => 42,
                'accountLabel' => 'TestAdmin',
                'channels' => ['admin'],
                'connectedSince' => microtime(true) - 5.0,
                'lastHeartbeat' => microtime(true),
                'connectionId' => 'abc123testabcd12',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->subscribersJsonPath)) {
            unlink($this->subscribersJsonPath);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    private function writeSubscribersJson(array $data): void
    {
        file_put_contents(
            $this->subscribersJsonPath,
            json_encode($data, JSON_THROW_ON_ERROR),
        );
    }

    private function buildRouter(): MercureMonitorApiRouter
    {
        $inspector = new ChannelInspector($this->database);
        $stream = new EventStreamReadModel($this->database);
        $observer = new SubscriberObserver($this->subscribersJsonPath);

        return new MercureMonitorApiRouter(
            new MercureMonitorController($inspector, $stream, $observer),
        );
    }

    private function adminAccount(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int
            {
                return 1;
            }
            public function getRoles(): array
            {
                return ['admin'];
            }
            public function hasPermission(string $permission): bool
            {
                return true;
            }
            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }

    private function nonAdminAccount(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int
            {
                return 99;
            }
            public function getRoles(): array
            {
                return ['editor'];
            }
            public function hasPermission(string $permission): bool
            {
                return false;
            }
            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }

    // --- Route registration ---

    #[Test]
    public function allThreeMonitorRoutesAreRegistered(): void
    {
        $routes = $this->router->getRouteCollection();

        $channels = $routes->get('api.mercure.monitor.channels');
        $events = $routes->get('api.mercure.monitor.events');
        $subscribers = $routes->get('api.mercure.monitor.subscribers');

        self::assertNotNull($channels, 'GET /api/mercure/channels must be registered');
        self::assertSame('/api/mercure/channels', $channels->getPath());
        self::assertSame(['GET'], $channels->getMethods());

        self::assertNotNull($events, 'GET /api/mercure/events must be registered');
        self::assertSame('/api/mercure/events', $events->getPath());

        self::assertNotNull($subscribers, 'GET /api/mercure/subscribers must be registered');
        self::assertSame('/api/mercure/subscribers', $subscribers->getPath());
    }

    #[Test]
    public function allThreeRoutesRequireAdminRole(): void
    {
        $routes = $this->router->getRouteCollection();

        foreach (['api.mercure.monitor.channels', 'api.mercure.monitor.events', 'api.mercure.monitor.subscribers'] as $name) {
            $route = $routes->get($name);
            self::assertNotNull($route, "Route {$name} must be registered");
            self::assertSame('admin', $route->getOption('_role'), "Route {$name} must require admin role");
        }
    }

    // --- Access control ---

    #[Test]
    public function accessCheckerAllowsAdminAndRejects403ForNonAdmin(): void
    {
        $routes = $this->router->getRouteCollection();
        $checker = new AccessChecker();
        $admin = $this->adminAccount();
        $nonAdmin = $this->nonAdminAccount();

        foreach ([
            'api.mercure.monitor.channels',
            'api.mercure.monitor.events',
            'api.mercure.monitor.subscribers',
        ] as $name) {
            $route = $routes->get($name);
            self::assertNotNull($route, "Route {$name} must be registered");

            $adminResult = $checker->check($route, $admin);
            self::assertTrue($adminResult->isAllowed(), "Admin must be allowed on {$name}");

            $nonAdminResult = $checker->check($route, $nonAdmin);
            self::assertTrue($nonAdminResult->isForbidden(), "Non-admin must be forbidden on {$name}");
        }
    }

    // --- channels endpoint ---

    #[Test]
    public function channelsEndpointReturnsGroupedCountsFromSeededRows(): void
    {
        $mercureRouter = $this->buildRouter();

        $request = Request::create('/api/mercure/channels', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\MercureMonitorController::channels');

        self::assertTrue($mercureRouter->supports($request));
        $response = $mercureRouter->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('rows', $body['data']);
        self::assertGreaterThanOrEqual(3, count($body['data']['rows']));

        // Find admin channel
        $byChannel = [];
        foreach ($body['data']['rows'] as $row) {
            $byChannel[$row['channel']] = $row;
        }
        self::assertArrayHasKey('admin', $byChannel);
        self::assertSame(4, $byChannel['admin']['eventCount24h']); // 4 admin events seeded
        self::assertArrayHasKey('realtime', $byChannel);
        self::assertArrayHasKey('pipeline', $byChannel);

        // NFR-004 identity-leak guard
        $rawBody = (string) $response->getContent();
        self::assertStringNotContainsString('Authorization', $rawBody);
        self::assertStringNotContainsString('Cookie', $rawBody);
        self::assertStringNotContainsString('User-Agent', $rawBody);
    }

    // --- events endpoint ---

    #[Test]
    public function eventsEndpointReturnsSseHeaders(): void
    {
        $mercureRouter = $this->buildRouter();

        $request = Request::create('/api/mercure/events', 'GET', ['channels' => 'admin']);
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\MercureMonitorController::events');

        $response = $mercureRouter->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/event-stream', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }

    // --- subscribers endpoint ---

    #[Test]
    public function subscribersEndpointReturnsActiveFixtureRows(): void
    {
        $mercureRouter = $this->buildRouter();

        $request = Request::create('/api/mercure/subscribers', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\MercureMonitorController::subscribers');

        $response = $mercureRouter->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('rows', $body['data']);
        self::assertCount(1, $body['data']['rows']);
        self::assertSame(42, $body['data']['rows'][0]['accountId']);
        self::assertSame('TestAdmin', $body['data']['rows'][0]['accountLabel']);
        self::assertSame(['admin'], $body['data']['rows'][0]['channels']);

        // NFR-004 identity-leak guard
        $rawBody = (string) $response->getContent();
        self::assertStringNotContainsString('Authorization', $rawBody);
        self::assertStringNotContainsString('Cookie', $rawBody);
        self::assertStringNotContainsString('User-Agent', $rawBody);
    }

    // --- MercureMonitorServiceProvider binding guard (FR-008) ---

    #[Test]
    public function mercureMonitorServiceProviderBindsAllThreeInterfaces(): void
    {
        // FR-008 kernel-boot integration guard: prove that
        // MercureMonitorServiceProvider registers all three interface bindings
        // so ApiServiceProvider::resolveOptional() finds them and wires the router.
        //
        // Strategy: call register(), then assert each interface name appears in
        // getBindings(). If any singleton() call is removed from the SP,
        // assertArrayHasKey fails — test fails as required.
        //
        // Note: we cannot resolve() the lazy closures in isolation because they
        // call $this->resolve(DatabaseInterface::class) on the SP itself (which
        // has no DB binding outside of the full kernel boot). The binding existence
        // check is sufficient: ApiServiceProvider's resolveOptional() will find and
        // invoke the closure at boot time when DatabaseInterface IS bound.
        $sp = new MercureMonitorServiceProvider();
        $sp->setKernelContext(
            __DIR__,
            [
                'broadcasting' => ['monitor' => ['enabled' => true]],
                'storage_path' => $this->tmpDir,
            ],
            [],
        );
        $sp->register();

        $bindings = $sp->getBindings();

        // All three interface bindings MUST exist — if any is removed, this fails.
        self::assertArrayHasKey(
            ChannelInspectorInterface::class,
            $bindings,
            'MercureMonitorServiceProvider must bind ChannelInspectorInterface (FR-008)',
        );
        self::assertArrayHasKey(
            EventStreamReadModelInterface::class,
            $bindings,
            'MercureMonitorServiceProvider must bind EventStreamReadModelInterface (FR-008)',
        );
        self::assertArrayHasKey(
            SubscriberObserverInterface::class,
            $bindings,
            'MercureMonitorServiceProvider must bind SubscriberObserverInterface (FR-008)',
        );

        // Also prove the concrete end-to-end: the three adapters implement
        // the interfaces correctly when constructed directly.
        $inspector = new ChannelInspector($this->database);
        $stream = new EventStreamReadModel($this->database);
        $observer = new SubscriberObserver($this->subscribersJsonPath);

        self::assertInstanceOf(ChannelInspectorInterface::class, $inspector);
        self::assertInstanceOf(EventStreamReadModelInterface::class, $stream);
        self::assertInstanceOf(SubscriberObserverInterface::class, $observer);
    }
}

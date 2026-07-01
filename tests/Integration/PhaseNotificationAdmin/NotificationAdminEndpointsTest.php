<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseNotificationAdmin;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Access\AccessChecker;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Api\Controller\NotificationController;
use Waaseyaa\Api\Http\Router\NotificationAdminApiRouter;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\BuiltinRouteRegistrar;
use Waaseyaa\Notification\ChannelInterface;
use Waaseyaa\Notification\NotifiableInterface;
use Waaseyaa\Notification\NotificationDispatcher;
use Waaseyaa\Notification\NotificationInterface;
use Waaseyaa\Queue\SyncQueue;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * End-to-end wiring for the M4C WP01 admin notifications dashboard.
 *
 * Asserts:
 *   1. `BuiltinRouteRegistrar` registers the two notification routes with the
 *      canonical paths and `_role: admin` option (FR-001..FR-005, NFR-001).
 *   2. `AccessChecker` accepts an admin account and rejects a non-admin
 *      account on each route (FR-013, NFR-001 — route-option enforcement,
 *      not controller-side).
 *   3. The controller + router pair drives a `NotificationDispatcher`
 *      configured with a fake `ChannelInterface`:
 *      - index returns `{type, class}` rows for each registered channel
 *      - test fires the channel's `send()` once and returns a 200 success
 *      - test 404s on unknown channel type
 *      - test 500s + extracts `{exception_class, message}` when the channel
 *        throws (FR-009, FR-010 — no Throwable across the JSON boundary)
 *
 * Spec: kitty-specs/notification-rules-admin-01KSDRNW/spec.md
 */
#[CoversNothing]
final class NotificationAdminEndpointsTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private WaaseyaaRouter $router;

    protected function setUp(): void
    {
        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $this->router = new WaaseyaaRouter(new RequestContext('', 'GET'));

        // WP5: routes are now registered by ApiServiceProvider::routes().
        $registrar = new BuiltinRouteRegistrar($this->entityTypeManager, [new ApiServiceProvider()]);
        $registrar->register($this->router);
    }

    #[Test]
    public function bothNotificationRoutesAreRegistered(): void
    {
        $routes = $this->router->getRouteCollection();

        $index = $routes->get('api.notification.channels.index');
        $test = $routes->get('api.notification.channels.test');

        self::assertNotNull($index, 'GET /api/notification/channels must be registered');
        self::assertSame('/api/notification/channels', $index->getPath());
        self::assertSame(['GET'], $index->getMethods());

        self::assertNotNull($test, 'POST /api/notification/channels/{type}/test must be registered');
        self::assertSame('/api/notification/channels/{type}/test', $test->getPath());
        self::assertSame(['POST'], $test->getMethods());
    }

    #[Test]
    public function bothNotificationRoutesRequireAdminRole(): void
    {
        $routes = $this->router->getRouteCollection();

        foreach (['api.notification.channels.index', 'api.notification.channels.test'] as $name) {
            $route = $routes->get($name);
            self::assertNotNull($route);
            self::assertSame('admin', $route->getOption('_role'), $name . ' must require role=admin');
        }
    }

    #[Test]
    public function accessCheckerAllowsAdminAndForbidsNonAdmin(): void
    {
        $routes = $this->router->getRouteCollection();
        $checker = new AccessChecker();
        $admin = self::account(['admin']);
        $editor = self::account(['editor']);

        foreach (['api.notification.channels.index', 'api.notification.channels.test'] as $name) {
            $route = $routes->get($name);
            self::assertNotNull($route);

            $adminResult = $checker->check($route, $admin);
            self::assertTrue($adminResult->isAllowed(), $name . ' must allow an admin');

            $editorResult = $checker->check($route, $editor);
            self::assertTrue($editorResult->isForbidden(), $name . ' must forbid a non-admin');
        }
    }

    #[Test]
    public function indexEndpointReturnsRegisteredChannelsViaRouter(): void
    {
        [$controller, $channels] = $this->wireController();

        $router = new NotificationAdminApiRouter($controller);
        $request = Request::create('/api/notification/channels', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\NotificationController::index');

        self::assertTrue($router->supports($request));
        $response = $router->handle($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(2, $body['data']);
        $types = array_column($body['data'], 'type');
        self::assertContains('mail', $types);
        self::assertContains('database', $types);
        // class FQCNs match the anonymous fake instances we registered.
        $classMap = [];
        foreach ($body['data'] as $row) {
            $classMap[$row['type']] = $row['class'];
        }
        self::assertSame($channels['mail']::class, $classMap['mail']);
        self::assertSame($channels['database']::class, $classMap['database']);
    }

    #[Test]
    public function testEndpointFiresChannelSendAndReturnsSuccessEnvelope(): void
    {
        [$controller, $channels] = $this->wireController();

        $router = new NotificationAdminApiRouter($controller);
        $request = Request::create('/api/notification/channels/mail/test', 'POST');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\NotificationController::test');
        $request->attributes->set('type', 'mail');

        $response = $router->handle($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('mail', $body['type']);
        self::assertSame('success', $body['status']);
        self::assertSame(1, $channels['mail']->sentCount(), 'mail channel send() must run exactly once');
        self::assertSame(0, $channels['database']->sentCount(), 'database channel must not be touched');
    }

    #[Test]
    public function testEndpointReturns404ForUnknownType(): void
    {
        [$controller] = $this->wireController();

        $router = new NotificationAdminApiRouter($controller);
        $request = Request::create('/api/notification/channels/ghost/test', 'POST');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\NotificationController::test');
        $request->attributes->set('type', 'ghost');

        $response = $router->handle($request);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('1.1', $body['jsonapi']['version']);
        self::assertSame('Not Found', $body['errors'][0]['title']);
        self::assertStringContainsString('ghost', $body['errors'][0]['detail']);
    }

    #[Test]
    public function testEndpointReturnsStructuredFailureWhenChannelThrows(): void
    {
        $failing = new class implements ChannelInterface {
            public function send(NotifiableInterface $notifiable, NotificationInterface $notification): void
            {
                throw new \RuntimeException('SMTP unreachable');
            }
        };
        $dispatcher = new NotificationDispatcher(new SyncQueue(), ['mail' => $failing]);
        $controller = new NotificationController($dispatcher);
        $router = new NotificationAdminApiRouter($controller);

        $request = Request::create('/api/notification/channels/mail/test', 'POST');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\NotificationController::test');
        $request->attributes->set('type', 'mail');

        $response = $router->handle($request);

        self::assertSame(500, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('mail', $body['type']);
        self::assertSame('failed', $body['status']);
        self::assertSame('SMTP unreachable', $body['message']);
        self::assertSame(\RuntimeException::class, $body['exception_class']);
    }

    /**
     * Wire a real controller against a `NotificationDispatcher` with two
     * recording channel fakes.
     *
     * @return array{0: NotificationController, 1: array{mail: object, database: object}}
     */
    private function wireController(): array
    {
        $mail = self::recordingChannel();
        $database = self::recordingChannel();
        $dispatcher = new NotificationDispatcher(
            new SyncQueue(),
            ['mail' => $mail, 'database' => $database],
        );

        return [
            new NotificationController($dispatcher),
            ['mail' => $mail, 'database' => $database],
        ];
    }

    private static function recordingChannel(): ChannelInterface
    {
        return new class implements ChannelInterface {
            private int $sent = 0;

            public function send(NotifiableInterface $notifiable, NotificationInterface $notification): void
            {
                $this->sent++;
            }

            public function sentCount(): int
            {
                return $this->sent;
            }
        };
    }

    /**
     * @param list<string> $roles
     */
    private static function account(array $roles): AccountInterface
    {
        return new class ($roles) implements AccountInterface {
            /**
             * @param list<string> $roles
             */
            public function __construct(private readonly array $roles) {}

            public function id(): int|string
            {
                return 1;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function getRoles(): array
            {
                return $this->roles;
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }
}

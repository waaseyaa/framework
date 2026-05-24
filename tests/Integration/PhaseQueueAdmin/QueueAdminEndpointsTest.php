<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseQueueAdmin;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Access\AccessChecker;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\Controller\QueueController;
use Waaseyaa\Api\Http\Router\QueueAdminApiRouter;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\BuiltinRouteRegistrar;
use Waaseyaa\Queue\QueueInterface;
use Waaseyaa\Queue\Storage\DatabaseFailedJobRepository;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * End-to-end wiring for the M4B WP01 admin queue dashboard.
 *
 * Asserts:
 *   1. `BuiltinRouteRegistrar` registers the three queue routes with the
 *      canonical paths and `_role: admin` option (FR-001..FR-003, NFR-001).
 *   2. `AccessChecker` accepts an admin account and rejects a non-admin
 *      account on each route (FR-016, NFR-001 — route-option enforcement,
 *      not controller-side).
 *   3. The full controller + router pair against a real
 *      `DatabaseFailedJobRepository` (SQLite in-memory) produces:
 *      - paginated index payload with the documented shape (FR-004),
 *      - retry → 204 + record removed from the failed table + message
 *        dispatched to QueueInterface (FR-006),
 *      - discard → 204 + record removed (FR-007),
 *      - retry / discard on an unknown id → 404 (FR-016).
 *
 * Spec: kitty-specs/queue-scheduler-admin-01KSBKQF/spec.md
 */
#[CoversNothing]
final class QueueAdminEndpointsTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private WaaseyaaRouter $router;

    protected function setUp(): void
    {
        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $this->router = new WaaseyaaRouter(new RequestContext('', 'GET'));

        $registrar = new BuiltinRouteRegistrar($this->entityTypeManager);
        $registrar->register($this->router);
    }

    #[Test]
    public function allThreeQueueRoutesAreRegistered(): void
    {
        $routes = $this->router->getRouteCollection();

        $index = $routes->get('api.queue.jobs.index');
        $retry = $routes->get('api.queue.jobs.retry');
        $discard = $routes->get('api.queue.jobs.discard');

        self::assertNotNull($index, 'GET /api/queue/jobs must be registered');
        self::assertSame('/api/queue/jobs', $index->getPath());
        self::assertSame(['GET'], $index->getMethods());

        self::assertNotNull($retry, 'POST /api/queue/jobs/{id}/retry must be registered');
        self::assertSame('/api/queue/jobs/{id}/retry', $retry->getPath());
        self::assertSame(['POST'], $retry->getMethods());

        self::assertNotNull($discard, 'POST /api/queue/jobs/{id}/discard must be registered');
        self::assertSame('/api/queue/jobs/{id}/discard', $discard->getPath());
        self::assertSame(['POST'], $discard->getMethods());
    }

    #[Test]
    public function allThreeQueueRoutesRequireAdminRole(): void
    {
        $routes = $this->router->getRouteCollection();

        foreach (['api.queue.jobs.index', 'api.queue.jobs.retry', 'api.queue.jobs.discard'] as $name) {
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

        foreach (['api.queue.jobs.index', 'api.queue.jobs.retry', 'api.queue.jobs.discard'] as $name) {
            $route = $routes->get($name);
            self::assertNotNull($route);

            $adminResult = $checker->check($route, $admin);
            self::assertTrue($adminResult->isAllowed(), $name . ' must allow an admin');

            $editorResult = $checker->check($route, $editor);
            self::assertTrue($editorResult->isForbidden(), $name . ' must forbid a non-admin');
        }
    }

    #[Test]
    public function indexEndpointReturnsPaginatedFailedJobs(): void
    {
        [$controller, $repo, , ] = $this->wireControllerWithRealStorage();
        $repo->record('default', serialize(new \stdClass()), new \RuntimeException('first failure'));
        $repo->record('high', serialize(new \stdClass()), new \RuntimeException('second failure'));

        $router = new QueueAdminApiRouter($controller);
        $request = Request::create('/api/queue/jobs', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\QueueController::index');

        self::assertTrue($router->supports($request));
        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(JsonResponse::class, $response);
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertCount(2, $body['data']);
        self::assertSame(['page' => 1, 'per_page' => 20, 'total' => 2], $body['meta']);
        self::assertSame('first failure', $body['data'][0]['exception_message']);
        self::assertSame('RuntimeException', $body['data'][0]['exception_class']);
        self::assertSame('default', $body['data'][0]['queue']);
        self::assertArrayHasKey('failed_at', $body['data'][0]);
    }

    #[Test]
    public function retryEndpointDispatchesAndRemovesRecord(): void
    {
        [$controller, $repo, $queue, ] = $this->wireControllerWithRealStorage();
        $message = new \stdClass();
        $message->kind = 'TestJob';
        $id = $repo->record('default', serialize($message), new \RuntimeException('boom'));

        $router = new QueueAdminApiRouter($controller);
        $request = Request::create('/api/queue/jobs/' . $id . '/retry', 'POST');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\QueueController::retry');
        $request->attributes->set('id', $id);

        $response = $router->handle($request);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertNull($repo->find($id), 'failed-job row must be removed after retry');
        self::assertCount(1, $queue->dispatched);
        self::assertSame('TestJob', $queue->dispatched[0]->kind);
    }

    #[Test]
    public function discardEndpointForgetsRecord(): void
    {
        [$controller, $repo, , ] = $this->wireControllerWithRealStorage();
        $id = $repo->record('default', 'p', new \RuntimeException('boom'));

        $router = new QueueAdminApiRouter($controller);
        $request = Request::create('/api/queue/jobs/' . $id . '/discard', 'POST');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\QueueController::discard');
        $request->attributes->set('id', $id);

        $response = $router->handle($request);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertNull($repo->find($id));
    }

    #[Test]
    public function retryAndDiscardReturn404ForUnknownId(): void
    {
        [$controller] = $this->wireControllerWithRealStorage();

        $retryResponse = $controller->retry('does-not-exist');
        self::assertSame(404, $retryResponse->getStatusCode());

        $discardResponse = $controller->discard('does-not-exist');
        self::assertSame(404, $discardResponse->getStatusCode());
    }

    /**
     * Wire a real controller against a real DatabaseFailedJobRepository
     * backed by an in-memory SQLite, plus a fake QueueInterface we can
     * inspect from the test.
     *
     * @return array{0: QueueController, 1: DatabaseFailedJobRepository, 2: object{dispatched: list<object>}, 3: DBALDatabase}
     */
    private function wireControllerWithRealStorage(): array
    {
        $db = DBALDatabase::createSqlite();
        $db->schema()->createTable('waaseyaa_failed_jobs', [
            'fields' => [
                'id' => ['type' => 'serial'],
                'queue' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
                'payload' => ['type' => 'text', 'not null' => true],
                'exception' => ['type' => 'text', 'not null' => true],
                'failed_at' => ['type' => 'varchar', 'length' => 50, 'not null' => true],
                'retried_at' => ['type' => 'varchar', 'length' => 50],
            ],
            'primary key' => ['id'],
        ]);

        $repo = new DatabaseFailedJobRepository($db);

        $queue = new class implements QueueInterface {
            /** @var list<object> */
            public array $dispatched = [];

            public function dispatch(object $message): void
            {
                $this->dispatched[] = $message;
            }
        };

        return [new QueueController($repo, $queue), $repo, $queue, $db];
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

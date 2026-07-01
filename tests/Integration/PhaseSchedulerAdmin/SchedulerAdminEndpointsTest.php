<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseSchedulerAdmin;

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
use Waaseyaa\Api\Controller\SchedulerController;
use Waaseyaa\Api\Http\Router\SchedulerAdminApiRouter;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\BuiltinRouteRegistrar;
use Waaseyaa\Queue\SyncQueue;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\Scheduler\Lock\InMemoryLock;
use Waaseyaa\Scheduler\Schedule;
use Waaseyaa\Scheduler\ScheduledTask;
use Waaseyaa\Scheduler\ScheduleRunner;
use Waaseyaa\Scheduler\Storage\ScheduleStateRepository;

/**
 * End-to-end wiring for the M4B WP02 admin scheduler dashboard.
 *
 * Asserts:
 *   1. `BuiltinRouteRegistrar` registers both scheduler routes with the
 *      canonical paths and `_role: admin` option (FR-008..FR-011, NFR-001).
 *   2. `AccessChecker` accepts an admin account and rejects a non-admin
 *      account on each route (NFR-001 — route-option enforcement, not
 *      controller-side).
 *   3. The full controller + router pair against a real
 *      `ScheduleStateRepository` (SQLite in-memory) and a real
 *      `ScheduleRunner` produces:
 *      - list payload with the documented shape (FR-009),
 *      - trigger → 200 + `recordRun()` side effect + last_status updated
 *        on the very next index call (FR-013),
 *      - trigger on an unknown task → 404 (FR-011),
 *      - trigger of a failing task → 200 with `{status: failed, message,
 *        exception_class}` and no `\Throwable` in the response body
 *        (FR-010).
 *
 * Mirrors `QueueAdminEndpointsTest` (M4B WP01).
 *
 * Spec: kitty-specs/queue-scheduler-admin-01KSBKQF/spec.md
 */
#[CoversNothing]
final class SchedulerAdminEndpointsTest extends TestCase
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
    public function bothSchedulerRoutesAreRegistered(): void
    {
        $routes = $this->router->getRouteCollection();

        $index = $routes->get('api.scheduler.tasks.index');
        $trigger = $routes->get('api.scheduler.tasks.trigger');

        self::assertNotNull($index, 'GET /api/scheduler/tasks must be registered');
        self::assertSame('/api/scheduler/tasks', $index->getPath());
        self::assertSame(['GET'], $index->getMethods());

        self::assertNotNull($trigger, 'POST /api/scheduler/tasks/{name}/trigger must be registered');
        self::assertSame('/api/scheduler/tasks/{name}/trigger', $trigger->getPath());
        self::assertSame(['POST'], $trigger->getMethods());
    }

    #[Test]
    public function bothSchedulerRoutesRequireAdminRole(): void
    {
        $routes = $this->router->getRouteCollection();

        foreach (['api.scheduler.tasks.index', 'api.scheduler.tasks.trigger'] as $name) {
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

        foreach (['api.scheduler.tasks.index', 'api.scheduler.tasks.trigger'] as $name) {
            $route = $routes->get($name);
            self::assertNotNull($route);

            $adminResult = $checker->check($route, $admin);
            self::assertTrue($adminResult->isAllowed(), $name . ' must allow an admin');

            $editorResult = $checker->check($route, $editor);
            self::assertTrue($editorResult->isForbidden(), $name . ' must forbid a non-admin');
        }
    }

    #[Test]
    public function indexEndpointReturnsAllRegisteredTasks(): void
    {
        [$controller, $schedule, ] = $this->wireControllerWithRealStorage([
            new ScheduledTask(
                name: 'closure-task',
                expression: '0 * * * *',
                command: fn() => null,
                description: 'Closure-based hourly task.',
            ),
            new ScheduledTask(
                name: 'string-task',
                expression: '0 2 * * *',
                command: \Waaseyaa\Queue\Tests\Unit\Fixtures\SuccessfulJob::class,
                description: 'Job-class nightly task.',
            ),
        ]);
        unset($schedule); // Tasks are kept alive by the controller's reference.

        $router = new SchedulerAdminApiRouter($controller);
        $request = Request::create('/api/scheduler/tasks', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\SchedulerController::index');

        self::assertTrue($router->supports($request));
        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(JsonResponse::class, $response);
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(2, $body['data']);
        $names = array_column($body['data'], 'name');
        self::assertContains('closure-task', $names);
        self::assertContains('string-task', $names);
        foreach ($body['data'] as $row) {
            self::assertNull($row['last_run_at']);
            self::assertNull($row['last_status']);
            self::assertNotEmpty($row['next_run_at']);
        }
    }

    #[Test]
    public function triggerEndpointRunsTaskAndRecordsState(): void
    {
        $invoked = false;
        [$controller, , $stateRepo] = $this->wireControllerWithRealStorage([
            new ScheduledTask(
                name: 'closure-task',
                expression: '0 0 1 1 *', // Never due — runOne() bypasses cron.
                command: function () use (&$invoked) {
                    $invoked = true;
                },
            ),
        ]);

        $router = new SchedulerAdminApiRouter($controller);
        $request = Request::create('/api/scheduler/tasks/closure-task/trigger', 'POST');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\SchedulerController::trigger');
        $request->attributes->set('name', 'closure-task');

        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($invoked, 'the closure must have fired');

        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('success', $body['status']);

        // FR-013: the very next index call must reflect the manual run.
        $state = $stateRepo->getState('closure-task');
        self::assertNotNull($state);
        self::assertSame('success', $state['last_result']);
    }

    #[Test]
    public function triggerEndpointReturns404ForUnknownTask(): void
    {
        [$controller] = $this->wireControllerWithRealStorage([]);

        $router = new SchedulerAdminApiRouter($controller);
        $request = Request::create('/api/scheduler/tasks/does-not-exist/trigger', 'POST');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\SchedulerController::trigger');
        $request->attributes->set('name', 'does-not-exist');

        $response = $router->handle($request);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Not Found', $body['errors'][0]['title']);
    }

    #[Test]
    public function triggerEndpointSurfacesStructuredFailureWithoutLeakingThrowable(): void
    {
        [$controller] = $this->wireControllerWithRealStorage([
            new ScheduledTask(
                name: 'angry-task',
                expression: '* * * * *',
                command: fn() => throw new \DomainException('intentional failure'),
            ),
        ]);

        $router = new SchedulerAdminApiRouter($controller);
        $request = Request::create('/api/scheduler/tasks/angry-task/trigger', 'POST');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\SchedulerController::trigger');
        $request->attributes->set('name', 'angry-task');

        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $raw = (string) $response->getContent();
        $body = json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('failed', $body['status']);
        self::assertSame('intentional failure', $body['message']);
        self::assertSame(\DomainException::class, $body['exception_class']);

        // FR-010 — response must never include a serialised Throwable.
        self::assertStringNotContainsString('"trace"', $raw);
        self::assertStringNotContainsString('"file"', $raw);
        self::assertStringNotContainsString('"line"', $raw);
    }

    /**
     * @param list<ScheduledTask> $tasks
     * @return array{0: SchedulerController, 1: Schedule, 2: ScheduleStateRepository, 3: DBALDatabase}
     */
    private function wireControllerWithRealStorage(array $tasks): array
    {
        $db = DBALDatabase::createSqlite();
        $db->query('
            CREATE TABLE waaseyaa_schedule_state (
                task_name VARCHAR(255) PRIMARY KEY,
                last_run_at VARCHAR(50) NOT NULL,
                last_result TEXT NOT NULL
            )
        ');
        $stateRepo = new ScheduleStateRepository($db);

        $schedule = new Schedule();
        foreach ($tasks as $task) {
            $schedule->add($task);
        }

        $runner = new ScheduleRunner($schedule, new SyncQueue(), new InMemoryLock(), $stateRepo);
        $controller = new SchedulerController($schedule, $stateRepo, $runner);

        return [$controller, $schedule, $stateRepo, $db];
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

<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseOcapAudit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Access\AccessChecker;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\Audit\ApiAuditQueryAdapter;
use Waaseyaa\Api\Audit\AuditQueryReadModelInterface;
use Waaseyaa\Api\Controller\AuditQueryController;
use Waaseyaa\Api\Http\Router\AuditApiRouter;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditQueryInterface;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Enum\AuditEventKind;
use Waaseyaa\Audit\Query\AuditEventQuery;
use Waaseyaa\Audit\Schema\AuditEventSchemaHandler;
use Waaseyaa\Audit\Storage\AppendOnlyAuditDatabase;
use Waaseyaa\Audit\Writer\AuditEventWriter;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\BuiltinRouteRegistrar;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * End-to-end wiring for the OCAP audit events query endpoint (T-O).
 *
 * =====================================================================
 * FR-013 DEAD-CODE GUARD — READ THIS BEFORE EDITING
 * =====================================================================
 * The `AuditQueryReadModelInterface` binding in `ApiServiceProvider::register()`
 * is the SINGLE connection between the L0 audit contracts and the L4 API
 * controller. If that binding is removed:
 *
 *   1. `ApiServiceProvider::resolveOptional(AuditQueryReadModelInterface::class)`
 *      returns null.
 *   2. `AuditQueryController` receives null → returns empty shape:
 *      `{data: [], meta: {total: 0, limit: 50, offset: 0}}`.
 *   3. `self::assertCount(6, $body['data'])` in this test FAILS.
 *   4. `self::assertSame(6, $body['meta']['total'])` also FAILS.
 *
 * To verify the guard manually (per verification gate §6):
 *   1. Comment out `$this->singleton(AuditQueryReadModelInterface::class, ...)` in ApiServiceProvider.
 *   2. Re-run this test — it MUST fail with count mismatch.
 *   3. Restore the binding before committing.
 * =====================================================================
 *
 * Asserts:
 *   1. `BuiltinRouteRegistrar` registers the audit route with `_role: admin`.
 *   2. `AccessChecker` allows admin, forbids non-admin.
 *   3. Router + controller against real SQLite: seeded events, kind filter,
 *      meta shape, ordering.
 */
#[CoversNothing]
final class OcapAuditEndpointTest extends TestCase
{
    private DBALDatabase $database;
    private AuditWriterInterface $writer;
    private AuditQueryInterface $query;
    private EntityTypeManager $entityTypeManager;
    private WaaseyaaRouter $router;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();

        // Ensure schema exists.
        $schemaHandler = new AuditEventSchemaHandler($this->database);
        $schemaHandler->ensureSchema();

        $dispatcher = new EventDispatcher();
        $this->entityTypeManager = new EntityTypeManager($dispatcher);

        // Register audit_event in the manager so the builtin router wiring below
        // resolves. The writer itself appends through the append-only decorator
        // (production wiring) — audit_event is a plain OCAP log table, not an
        // entity the writer persists via a repository.
        $auditEventType = new EntityType(
            id: 'audit_event',
            label: 'Audit Event',
            class: \Waaseyaa\Audit\Entity\AuditEvent::class,
            keys: ['id' => 'id', 'uuid' => 'uuid'],
        );
        $this->entityTypeManager->registerEntityType($auditEventType);
        $this->writer = new AuditEventWriter(new AppendOnlyAuditDatabase($this->database));

        // Wire query.
        $this->query = new AuditEventQuery($this->database);

        // Wire router.
        $this->router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        $registrar = new BuiltinRouteRegistrar($this->entityTypeManager);
        $registrar->register($this->router);
    }

    #[Test]
    public function auditRouteIsRegisteredWithAdminRole(): void
    {
        $route = $this->router->getRouteCollection()->get('api.audit.events.index');

        self::assertNotNull($route, 'api.audit.events.index must be registered');
        self::assertSame('/api/audit/events', $route->getPath());
        self::assertSame(['GET'], $route->getMethods());
    }

    #[Test]
    public function auditRouteAllowsAdminAndForbidsNonAdmin(): void
    {
        $route = $this->router->getRouteCollection()->get('api.audit.events.index');
        self::assertNotNull($route);

        $checker = new AccessChecker();

        $admin = $this->account(['admin']);
        $author = $this->account(['content_editor']);

        $adminResult = $checker->check($route, $admin);
        self::assertTrue($adminResult->isAllowed(), 'admin must be allowed');

        $authorResult = $checker->check($route, $author);
        self::assertTrue($authorResult->isForbidden(), 'non-admin must be forbidden');
    }

    #[Test]
    public function indexReturnsSeededEventsWithKindFilter(): void
    {
        // Seed 6 events across 4 kinds and 2 accounts.
        $this->seed([
            ['kind' => AuditEventKind::EntityRead,      'account' => 1, 'subject' => '/node/1'],
            ['kind' => AuditEventKind::EntityRead,      'account' => 1, 'subject' => '/node/2'],
            ['kind' => AuditEventKind::EntityRead,      'account' => 2, 'subject' => '/node/3'],
            ['kind' => AuditEventKind::EntityWrite,     'account' => 1, 'subject' => '/node/4'],
            ['kind' => AuditEventKind::AccessDenied,    'account' => 2, 'subject' => '/node/5'],
            ['kind' => AuditEventKind::AgentToolExecute, 'account' => 1, 'subject' => '/ai/run/1'],
        ]);

        $readModel = new ApiAuditQueryAdapter($this->query);
        $router = new AuditApiRouter(new AuditQueryController($readModel));

        $request = Request::create('/api/audit/events?filter[kind]=entity.read&page[limit]=10', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\AuditQueryController::index');

        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        // Shape: {data, meta: {total, limit, offset}}
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('meta', $body);
        self::assertArrayHasKey('total', $body['meta']);
        self::assertArrayHasKey('limit', $body['meta']);
        self::assertArrayHasKey('offset', $body['meta']);

        // Only entity.read events (3 out of 6).
        self::assertCount(3, $body['data'], 'Only entity.read events must be returned');
        self::assertSame(3, $body['meta']['total']);
        self::assertSame(10, $body['meta']['limit']);
        self::assertSame(0, $body['meta']['offset']);

        // All returned events must have eventKind = 'entity.read'.
        foreach ($body['data'] as $row) {
            self::assertSame('entity.read', $row['eventKind']);
        }

        // Ordering: created_at DESC — verify array is sorted descending.
        $dates = array_column($body['data'], 'createdAt');
        $sorted = $dates;
        rsort($sorted);
        self::assertSame($sorted, $dates, 'Events must be ordered by created_at DESC');
    }

    #[Test]
    public function indexWithNoKindFilterReturnsAllEvents(): void
    {
        $this->seed([
            ['kind' => AuditEventKind::EntityRead,   'account' => 1, 'subject' => '/a'],
            ['kind' => AuditEventKind::EntityWrite,  'account' => 1, 'subject' => '/b'],
            ['kind' => AuditEventKind::AccessDenied, 'account' => 2, 'subject' => '/c'],
        ]);

        $readModel = new ApiAuditQueryAdapter($this->query);
        $router = new AuditApiRouter(new AuditQueryController($readModel));

        $request = Request::create('/api/audit/events', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\AuditQueryController::index');

        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertCount(3, $body['data']);
        self::assertSame(3, $body['meta']['total']);
    }

    #[Test]
    public function nullReadModelReturnsEmptyShape(): void
    {
        $router = new AuditApiRouter(new AuditQueryController(null));

        $request = Request::create('/api/audit/events', 'GET');
        $request->attributes->set('_controller', 'Waaseyaa\\Api\\Controller\\AuditQueryController::index');

        $response = $router->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame([], $body['data']);
        self::assertSame(0, $body['meta']['total']);
        self::assertSame(50, $body['meta']['limit']);
        self::assertSame(0, $body['meta']['offset']);
    }

    /**
     * Seed events into the database.
     *
     * @param array<int, array{kind: AuditEventKind, account: int, subject: string}> $events
     */
    private function seed(array $events): void
    {
        foreach ($events as $e) {
            $this->writer->record(new AuditEventDescriptor(
                kind: $e['kind'],
                accountUid: $e['account'],
                subjectUri: $e['subject'],
                outcome: 'allowed',
                severity: 'info',
            ));
        }
    }

    /**
     * @param string[] $roles
     */
    private function account(array $roles): AccountInterface
    {
        return new class ($roles) implements AccountInterface {
            /** @param string[] $roles */
            public function __construct(private readonly array $roles) {}

            public function id(): int|string
            {
                return in_array('admin', $this->roles, true) ? 1 : 2;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            /** @return string[] */
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

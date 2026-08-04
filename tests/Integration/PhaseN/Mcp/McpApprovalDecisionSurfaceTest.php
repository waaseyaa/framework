<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\PhaseN\Mcp;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Waaseyaa\Access\AccessChecker;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AccountPrincipalFactory;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityDeclaration;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\InMemoryCapabilityRegistry;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\Middleware\AuthorizationMiddleware;
use Waaseyaa\Access\Middleware\FieldReadContextMiddleware;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Api\Http\Router\McpApprovalApiRouter;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Audit\Bootstrap\IdentityBootstrapReader;
use Waaseyaa\Audit\Bootstrap\SessionBootstrapReader;
use Waaseyaa\Audit\Contract\AuditEventDescriptor;
use Waaseyaa\Audit\Contract\AuditWriterInterface;
use Waaseyaa\Audit\Listener\McpApprovalDecisionAuditListener;
use Waaseyaa\Audit\Schema\AuditEventSchemaHandler;
use Waaseyaa\Audit\Storage\ApprovalEventSchema;
use Waaseyaa\Audit\Storage\StrictAuditLedgerSchema;
use Waaseyaa\Audit\Writer\DatabaseOperationApprovalStore;
use Waaseyaa\Audit\Writer\DatabaseStrictAuditLedger;
use Waaseyaa\Audit\Writer\DatabaseStrictPrivilegedReadLedger;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\DateTime\EntityClockInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Audit\Approval\ApprovalRequest;
use Waaseyaa\Foundation\Audit\Approval\ApprovalStatus;
use Waaseyaa\Foundation\Audit\Approval\ApprovalTuple;
use Waaseyaa\Foundation\Audit\Approval\OperationApprovalStoreInterface;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpPipeline;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;
use Waaseyaa\Mcp\CapabilityScopedToolRegistry;
use Waaseyaa\Mcp\McpEndpoint;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\User\Middleware\CsrfMiddleware;
use Waaseyaa\User\Middleware\SessionMiddleware;
use Waaseyaa\User\User;

/**
 * End-to-end contract of the MCP approval decision surface (#2177 F1 C1b)
 * through the PRODUCTION composition: ApiServiceProvider routes, the real
 * middleware pipeline in kernel priority order (Session 30 → Csrf 20 →
 * FieldReadContext 15 → Authorization 10), the real McpApprovalApiRouter from
 * ApiServiceProvider::httpDomainRouters(), and a real SQLite-backed
 * DatabaseOperationApprovalStore with real users resolved from a real PHP
 * session.
 */
#[CoversNothing]
final class McpApprovalDecisionSurfaceTest extends TestCase
{
    private const ALLOWED_ORIGIN = 'https://admin.example.test';
    private const OPERATOR_UID = 42;
    private const VIEWER_UID = 43;
    private const NO_CAPS_UID = 44;
    private const SECOND_OPERATOR_UID = 57;
    /** The MCP bearer principal whose destructive calls open approval requests. */
    private const REQUESTER_PRINCIPAL_KEY = '42';
    private const MCP_WRITE_CAP = 'content.publish';

    private DBALDatabase $database;
    private EntityTypeManager $entityTypeManager;
    private WaaseyaaRouter $router;
    private InMemoryCapabilityRegistry $capabilities;
    private DatabaseOperationApprovalStore $store;

    /** @var EntityClockInterface&object{now: \DateTimeImmutable} */
    private EntityClockInterface $clock;

    /** @var list<AuditEventDescriptor> */
    private array $auditRecords = [];

    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        session_start();
        $_SESSION = [];

        $this->database = DBALDatabase::createSqlite();
        $entityDispatcher = new EventDispatcher();
        $this->entityTypeManager = new EntityTypeManager(
            $entityDispatcher,
            null,
            function (string $entityTypeId, EntityTypeInterface $definition) use ($entityDispatcher): EntityRepository {
                $idKey = $definition->getKeys()['id'] ?? 'id';
                new SqlSchemaHandler($definition, $this->database)->ensureTable();

                return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                    $definition,
                    new SqlStorageDriver(new SingleConnectionResolver($this->database), $idKey),
                    $entityDispatcher,
                );
            },
        );
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'user',
            label: 'User',
            class: User::class,
            keys: ['id' => 'uid', 'uuid' => 'uuid', 'label' => 'name'],
        ));

        $this->saveUser(self::OPERATOR_UID, 'approval-operator', ['mcp.approval.view', 'mcp.approval.decide']);
        $this->saveUser(self::VIEWER_UID, 'approval-viewer', ['mcp.approval.view']);
        $this->saveUser(self::NO_CAPS_UID, 'approval-bystander', []);
        $this->saveUser(self::SECOND_OPERATOR_UID, 'approval-second-operator', ['mcp.approval.view', 'mcp.approval.decide']);

        new AuditEventSchemaHandler($this->database)->ensureSchema();
        new ApprovalEventSchema($this->database)->ensure();
        new StrictAuditLedgerSchema($this->database)->ensure();
        $this->clock = new class implements EntityClockInterface {
            public \DateTimeImmutable $now;

            public function __construct()
            {
                $this->now = new \DateTimeImmutable('2026-08-03 12:00:00', new \DateTimeZone('UTC'));
            }

            public function now(): \DateTimeImmutable
            {
                return $this->now;
            }
        };
        $this->store = new DatabaseOperationApprovalStore($this->database, $this->clock, ttlSeconds: 900);

        $this->capabilities = new InMemoryCapabilityRegistry();
        $this->capabilities->register(new CapabilityDeclaration(
            issuer: 'http.identity-bootstrap',
            reason: CapabilityReason::SessionBootstrap,
            entityTypes: ['user'],
            bundles: ['user'],
            fields: ['roles', 'permissions', 'status'],
            actorSemantics: [CapabilityActorSemantics::NoActingContext],
            maxTtlSeconds: 60,
            justification: 'Build the immutable HTTP authorization principal after identity resolution.',
            bindTenantFromContext: true,
            bindCommunityFromContext: true,
        ));

        // Best-effort audit projection: the REAL listener, recording writer.
        $this->auditRecords = [];
        $this->dispatcher = new EventDispatcher();
        $records = &$this->auditRecords;
        $writer = new class ($records) implements AuditWriterInterface {
            public function __construct(private array &$records) {}

            public function record(AuditEventDescriptor $d): void
            {
                $this->records[] = $d;
            }
        };
        $this->dispatcher->addSubscriber(new McpApprovalDecisionAuditListener($writer));

        $this->router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        new ApiServiceProvider()->routes($this->router, $this->entityTypeManager);
    }

    protected function tearDown(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
    }

    // ------------------------------------------------------------------
    // Harness
    // ------------------------------------------------------------------

    /** @param list<string> $permissions */
    private function saveUser(int $uid, string $name, array $permissions): void
    {
        $user = new User([
            'uid' => $uid,
            'uuid' => sprintf('7d444840-9dc0-11d1-b245-5ffdce74f%03d', $uid),
            'name' => $name,
            'roles' => ['editor'],
            'permissions' => $permissions,
            'status' => true,
        ]);
        $user->enforceIsNew();
        $this->entityTypeManager->getRepository('user')->save($user, validate: false);
    }

    private function login(int $uid): void
    {
        $_SESSION['waaseyaa_uid'] = $uid;
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    /**
     * The production approval router, resolved through the REAL
     * ApiServiceProvider::httpDomainRouters() wiring (store lazily through the
     * kernel-services bus, config-driven origin allowlist and self-approval
     * switch).
     *
     * @param array<string, mixed> $configOverrides
     */
    private function approvalRouter(array $configOverrides = [], ?OperationApprovalStoreInterface $store = null): McpApprovalApiRouter
    {
        $provider = new ApiServiceProvider();
        $provider->setKernelContext('', array_replace_recursive([
            'cors_origins' => [self::ALLOWED_ORIGIN],
        ], $configOverrides), []);
        $store ??= $this->store;
        $dispatcher = $this->dispatcher;
        $provider->setKernelServices(new class ($store, $dispatcher) implements KernelServicesInterface {
            public function __construct(
                private readonly OperationApprovalStoreInterface $store,
                private readonly EventDispatcher $dispatcher,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    OperationApprovalStoreInterface::class => $this->store,
                    \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $this->dispatcher,
                    default => null,
                };
            }
        });
        $provider->register();

        $kernel = new \Waaseyaa\Foundation\Kernel\HttpKernel(sys_get_temp_dir());
        new \ReflectionProperty(\Waaseyaa\Foundation\Kernel\AbstractKernel::class, 'entityTypeManager')
            ->setValue($kernel, $this->entityTypeManager);
        new \ReflectionProperty(\Waaseyaa\Foundation\Kernel\HttpKernel::class, 'discoveryHandler')->setValue(
            $kernel,
            new \Waaseyaa\Api\Http\DiscoveryApiHandler($this->entityTypeManager, $this->database),
        );

        foreach ($provider->httpDomainRouters($kernel) as $candidate) {
            if ($candidate instanceof McpApprovalApiRouter) {
                return $candidate;
            }
        }

        self::fail('ApiServiceProvider::httpDomainRouters() must produce the McpApprovalApiRouter.');
    }

    /**
     * Drive one request through the real pipeline in kernel priority order.
     *
     * @param array<string, string> $headers
     */
    private function dispatch(
        string $method,
        string $path,
        ?string $body = null,
        array $headers = [],
        ?McpApprovalApiRouter $approvalRouter = null,
        ?User $bearerAccount = null,
    ): Response {
        $request = Request::create(
            'http://localhost' . $path,
            $method,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        $matched = new UrlMatcher($this->router->getRouteCollection(), new RequestContext('', $method))
            ->match(parse_url($path, \PHP_URL_PATH) ?: $path);
        $routeName = $matched['_route'];
        $route = $this->router->getRouteCollection()->get($routeName);
        self::assertInstanceOf(Route::class, $route);
        $request->attributes->set('_route', $routeName);
        $request->attributes->set('_route_object', $route);
        foreach ($matched as $key => $value) {
            if (!str_starts_with((string) $key, '_')) {
                $request->attributes->set((string) $key, $value);
            }
        }
        $request->attributes->set('_controller', $route->getDefault('_controller'));

        if ($bearerAccount !== null) {
            // BearerAuthMiddleware (priority 40) resolves bearer identities
            // BEFORE SessionMiddleware; simulate its contract by pre-setting
            // the resolved account attribute.
            $request->attributes->set('_account', $bearerAccount);
        }

        $ledger = new DatabaseStrictPrivilegedReadLedger($this->database);
        $principalFactory = new AccountPrincipalFactory(new IdentityBootstrapReader(
            new SessionBootstrapReader(new AuditedFieldRead($this->capabilities, $ledger)),
            $this->capabilities,
            'http.identity-bootstrap',
        ));
        $pipeline = new HttpPipeline()
            ->withMiddleware(new SessionMiddleware($this->entityTypeManager->getRepository('user')))
            ->withMiddleware(new CsrfMiddleware())
            ->withMiddleware(new FieldReadContextMiddleware($principalFactory, new AccountFieldReadScope()))
            ->withMiddleware(new AuthorizationMiddleware(new AccessChecker()));

        $controllerDispatcher = new ControllerDispatcher([$approvalRouter ?? $this->approvalRouter()]);

        return $pipeline->handle($request, new class ($controllerDispatcher) implements HttpHandlerInterface {
            public function __construct(private readonly ControllerDispatcher $dispatcher) {}

            public function handle(Request $request): Response
            {
                return $this->dispatcher->dispatch($request);
            }
        });
    }

    private function openRequest(
        string $principalKey = self::REQUESTER_PRINCIPAL_KEY,
        string $operation = 'delete_page',
        array $safeArguments = ['page' => 'safe-title'],
    ): ApprovalRequest {
        return $this->store->open(
            ApprovalTuple::forCall($principalKey, 'mcp.write', $operation, ['page' => 'raw-secret-argument']),
            'corr-' . $operation,
            $safeArguments,
        );
    }

    /** @param array<string, string> $extra @return array<string, string> */
    private function decisionHeaders(array $extra = []): array
    {
        return $extra + [
            'X-CSRF-Token' => (string) ($_SESSION['_csrf_token'] ?? ''),
            'Origin' => self::ALLOWED_ORIGIN,
        ];
    }

    private function decisionPath(string $id): string
    {
        return '/api/mcp/approvals/' . $id . '/decision';
    }

    // ------------------------------------------------------------------
    // Authentication + session identity
    // ------------------------------------------------------------------

    #[Test]
    public function an_unauthenticated_request_is_refused_with_401(): void
    {
        $response = $this->dispatch('GET', '/api/mcp/approvals');

        self::assertSame(401, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    public function a_bearer_only_identity_is_refused_despite_holding_the_capability(): void
    {
        // The bearer account is fully privileged — but carries no login
        // session (`_session['waaseyaa_uid']`), and the queue must never be
        // reachable by the very principal class it supervises.
        $bearer = new User([
            'uid' => 99,
            'name' => 'bearer-robot',
            'roles' => ['editor'],
            'permissions' => ['mcp.approval.view', 'mcp.approval.decide'],
            'status' => true,
        ]);

        $response = $this->dispatch('GET', '/api/mcp/approvals', bearerAccount: $bearer);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    public function a_session_without_the_view_capability_is_refused(): void
    {
        $this->login(self::NO_CAPS_UID);

        $response = $this->dispatch('GET', '/api/mcp/approvals');

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    public function a_view_only_session_can_read_the_queue_but_not_decide(): void
    {
        $this->login(self::VIEWER_UID);
        $open = $this->openRequest();

        $list = $this->dispatch('GET', '/api/mcp/approvals');
        $decide = $this->dispatch(
            'POST',
            $this->decisionPath($open->id),
            json_encode(['decision' => 'approve'], JSON_THROW_ON_ERROR),
            $this->decisionHeaders(),
        );

        self::assertSame(200, $list->getStatusCode(), (string) $list->getContent());
        self::assertSame(403, $decide->getStatusCode(), (string) $decide->getContent());
        self::assertSame(ApprovalStatus::Pending, $this->store->find($open->id)?->status);
    }

    // ------------------------------------------------------------------
    // Pending queue
    // ------------------------------------------------------------------

    #[Test]
    public function the_queue_lists_pending_requests_with_safe_arguments_only(): void
    {
        $this->login(self::OPERATOR_UID);
        $open = $this->openRequest(principalKey: '9');

        $response = $this->dispatch('GET', '/api/mcp/approvals');

        self::assertSame(200, $response->getStatusCode());
        $content = (string) $response->getContent();
        $payload = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload['data']);
        self::assertSame($open->id, $payload['data'][0]['id']);
        self::assertSame('pending', $payload['data'][0]['status']);
        self::assertSame(['page' => 'safe-title'], $payload['data'][0]['safeArguments']);
        self::assertStringNotContainsString('raw-secret-argument', $content, 'Raw call arguments must never leave the fingerprint.');
    }

    #[Test]
    public function the_queue_paginates_with_the_stores_opaque_cursor(): void
    {
        $this->login(self::OPERATOR_UID);
        $first = $this->openRequest(principalKey: '9', operation: 'delete_page');
        $second = $this->openRequest(principalKey: '9', operation: 'purge_media');

        $pageOne = json_decode(
            (string) $this->dispatch('GET', '/api/mcp/approvals?limit=1')->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertCount(1, $pageOne['data']);
        self::assertSame($first->id, $pageOne['data'][0]['id']);
        self::assertNotNull($pageOne['meta']['nextCursor']);

        $pageTwo = json_decode(
            (string) $this->dispatch('GET', '/api/mcp/approvals?limit=1&cursor=' . rawurlencode($pageOne['meta']['nextCursor']))->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertCount(1, $pageTwo['data']);
        self::assertSame($second->id, $pageTwo['data'][0]['id']);
    }

    #[Test]
    public function queue_pagination_input_is_validated_with_400(): void
    {
        $this->login(self::OPERATOR_UID);

        foreach (['/api/mcp/approvals?limit=0', '/api/mcp/approvals?limit=101', '/api/mcp/approvals?limit=abc', '/api/mcp/approvals?cursor=@@tampered@@'] as $path) {
            $response = $this->dispatch('GET', $path);
            self::assertSame(400, $response->getStatusCode(), $path . ' → ' . (string) $response->getContent());
        }
    }

    // ------------------------------------------------------------------
    // CSRF + origin
    // ------------------------------------------------------------------

    #[Test]
    public function a_decision_without_a_csrf_token_is_refused_despite_the_json_content_type(): void
    {
        $this->login(self::OPERATOR_UID);
        $open = $this->openRequest(principalKey: '9');

        $response = $this->dispatch(
            'POST',
            $this->decisionPath($open->id),
            json_encode(['decision' => 'approve'], JSON_THROW_ON_ERROR),
            ['Origin' => self::ALLOWED_ORIGIN],
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(ApprovalStatus::Pending, $this->store->find($open->id)?->status);
    }

    #[Test]
    public function a_decision_with_a_wrong_csrf_token_is_refused(): void
    {
        $this->login(self::OPERATOR_UID);
        $open = $this->openRequest(principalKey: '9');

        $response = $this->dispatch(
            'POST',
            $this->decisionPath($open->id),
            json_encode(['decision' => 'approve'], JSON_THROW_ON_ERROR),
            ['X-CSRF-Token' => str_repeat('0', 64), 'Origin' => self::ALLOWED_ORIGIN],
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(ApprovalStatus::Pending, $this->store->find($open->id)?->status);
    }

    #[Test]
    public function a_decision_without_an_origin_header_is_refused(): void
    {
        $this->login(self::OPERATOR_UID);
        $open = $this->openRequest(principalKey: '9');

        $response = $this->dispatch(
            'POST',
            $this->decisionPath($open->id),
            json_encode(['decision' => 'approve'], JSON_THROW_ON_ERROR),
            ['X-CSRF-Token' => (string) $_SESSION['_csrf_token']],
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(ApprovalStatus::Pending, $this->store->find($open->id)?->status);
    }

    #[Test]
    public function a_decision_from_a_non_allowlisted_origin_is_refused(): void
    {
        $this->login(self::OPERATOR_UID);
        $open = $this->openRequest(principalKey: '9');

        foreach (['https://evil.example', 'https://admin.example.test.evil.example', 'https://sub.admin.example.test'] as $origin) {
            $response = $this->dispatch(
                'POST',
                $this->decisionPath($open->id),
                json_encode(['decision' => 'approve'], JSON_THROW_ON_ERROR),
                $this->decisionHeaders(['Origin' => $origin]),
            );
            self::assertSame(403, $response->getStatusCode(), $origin);
        }
        self::assertSame(ApprovalStatus::Pending, $this->store->find($open->id)?->status);
    }

    // ------------------------------------------------------------------
    // Deciding
    // ------------------------------------------------------------------

    #[Test]
    public function an_operator_approves_a_pending_request_from_an_exact_allowlisted_origin(): void
    {
        $this->login(self::OPERATOR_UID);
        $open = $this->openRequest(principalKey: '9');

        $response = $this->dispatch(
            'POST',
            $this->decisionPath($open->id),
            json_encode(['decision' => 'approve'], JSON_THROW_ON_ERROR),
            $this->decisionHeaders(),
        );

        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        $decided = $this->store->find($open->id);
        self::assertSame(ApprovalStatus::Approved, $decided?->status);
        self::assertSame(self::OPERATOR_UID, $decided?->decidedByUid, 'The operator uid must be the server-derived session identity.');
    }

    #[Test]
    public function an_operator_denies_with_a_normalized_reason(): void
    {
        $this->login(self::OPERATOR_UID);
        $open = $this->openRequest(principalKey: '9');

        $response = $this->dispatch(
            'POST',
            $this->decisionPath($open->id),
            json_encode(['decision' => 'deny', 'reason' => '  not during release week  '], JSON_THROW_ON_ERROR),
            $this->decisionHeaders(),
        );

        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        $decided = $this->store->find($open->id);
        self::assertSame(ApprovalStatus::Denied, $decided?->status);
        self::assertSame('not during release week', $decided?->decisionReason);
    }

    #[Test]
    public function a_body_supplied_operator_identity_is_refused_not_honoured(): void
    {
        $this->login(self::OPERATOR_UID);
        $open = $this->openRequest(principalKey: '9');

        $response = $this->dispatch(
            'POST',
            $this->decisionPath($open->id),
            json_encode(['decision' => 'approve', 'operator_uid' => 7], JSON_THROW_ON_ERROR),
            $this->decisionHeaders(),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ApprovalStatus::Pending, $this->store->find($open->id)?->status);
    }

    #[Test]
    public function unknown_decided_and_expired_requests_map_to_404_and_409(): void
    {
        $this->login(self::OPERATOR_UID);

        $unknown = $this->dispatch(
            'POST',
            $this->decisionPath('apr_' . str_repeat('0', 32)),
            json_encode(['decision' => 'approve'], JSON_THROW_ON_ERROR),
            $this->decisionHeaders(),
        );
        self::assertSame(404, $unknown->getStatusCode());

        $decidedRequest = $this->openRequest(principalKey: '9', operation: 'delete_page');
        $this->store->decide($decidedRequest->id, true, 7);
        $alreadyDecided = $this->dispatch(
            'POST',
            $this->decisionPath($decidedRequest->id),
            json_encode(['decision' => 'approve'], JSON_THROW_ON_ERROR),
            $this->decisionHeaders(),
        );
        self::assertSame(409, $alreadyDecided->getStatusCode());

        $expiring = $this->openRequest(principalKey: '9', operation: 'purge_media');
        $this->clock->now = $this->clock->now->modify('+901 seconds');
        $expired = $this->dispatch(
            'POST',
            $this->decisionPath($expiring->id),
            json_encode(['decision' => 'approve'], JSON_THROW_ON_ERROR),
            $this->decisionHeaders(),
        );
        self::assertSame(409, $expired->getStatusCode());
    }

    #[Test]
    public function a_store_outage_is_sanitized_to_503(): void
    {
        $this->login(self::OPERATOR_UID);
        $open = $this->openRequest(principalKey: '9');
        $this->database->getConnection()->executeStatement('DROP TABLE mcp_approval_event');

        $response = $this->dispatch(
            'POST',
            $this->decisionPath($open->id),
            json_encode(['decision' => 'approve'], JSON_THROW_ON_ERROR),
            $this->decisionHeaders(),
        );

        self::assertSame(503, $response->getStatusCode());
        $content = (string) $response->getContent();
        self::assertStringNotContainsString('SQLSTATE', $content);
        self::assertStringNotContainsString('mcp_approval_event', $content);
        self::assertStringNotContainsString('Exception', $content);
    }

    /**
     * The PRODUCTION write-tier endpoint shape: BearerTokenAuth maps the
     * requester token to the account with the given uid, and McpEndpoint puts
     * `(string) $principal->id()` into the ApprovalTuple.
     */
    private function writeTierEndpoint(int $bearerAccountUid): McpEndpoint
    {
        $impl = new class extends AbstractAgentTool {
            public function execute(array $arguments, AccountInterface $account): AgentToolResult
            {
                return AgentToolResult::success([['type' => 'text', 'text' => 'published']]);
            }

            public function inputSchema(): array
            {
                return [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'string']],
                    'required' => ['id'],
                    'additionalProperties' => false,
                ];
            }

            public function description(): string
            {
                return 'publishes';
            }
        };
        $tool = new AgentTool('article.publish', self::MCP_WRITE_CAP, true, false, 'content', $impl->inputSchema(), $impl);
        $registry = new class ($tool) implements ToolRegistryInterface {
            public function __construct(private AgentTool $tool) {}

            public function register(AgentTool $tool): void {}

            public function get(string $name): AgentTool
            {
                return $name === $this->tool->name ? $this->tool : throw ToolNotFoundException::forName($name);
            }

            public function has(string $name): bool
            {
                return $name === $this->tool->name;
            }

            public function all(): iterable
            {
                return [$this->tool];
            }
        };

        return new McpEndpoint(
            auth: new BearerTokenAuth([
                'requester-token' => new AuthorizationPrincipal($bearerAccountUid, true, [], [self::MCP_WRITE_CAP], 'g1'),
            ]),
            agentRegistry: new CapabilityScopedToolRegistry($registry, [self::MCP_WRITE_CAP]),
            rateLimitTier: 'write',
            auditLedger: new DatabaseStrictAuditLedger($this->database),
            durableAudit: true,
            approvalStore: $this->store,
            approvalGate: true,
        );
    }

    /** Drive a real destructive tools/call through the endpoint, returning the challenge's request id. */
    private function challengeViaMcpEndpoint(int $bearerAccountUid): string
    {
        $endpoint = $this->writeTierEndpoint($bearerAccountUid);
        $request = Request::create('/mcp/write', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer requester-token',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ], json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'article.publish', 'arguments' => ['id' => 'a1']],
        ], JSON_THROW_ON_ERROR));
        $response = $endpoint->serve(new AuthorizationPrincipal(99, true, [], [], 'session'), $request);
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(-32003, $payload['error']['code'] ?? null, (string) $response->getContent());

        return $payload['error']['data']['approval_request_id'];
    }

    // ------------------------------------------------------------------
    // Separation of duties
    // ------------------------------------------------------------------

    #[Test]
    public function the_production_mcp_identity_binds_self_approval_end_to_end(): void
    {
        // The challenge comes from the REAL auth/endpoint path: BearerTokenAuth
        // resolves the token to account OPERATOR_UID, and McpEndpoint stamps
        // that identity into the tuple.
        $requestId = $this->challengeViaMcpEndpoint(self::OPERATOR_UID);
        self::assertSame(
            (string) self::OPERATOR_UID,
            $this->store->find($requestId)?->tuple->principalKey,
            'McpEndpoint must bind the tuple to (string) $principal->id() — the invariant the admin gate compares against.',
        );

        // A session for the SAME account is refused.
        $this->login(self::OPERATOR_UID);
        $selfDecision = $this->dispatch(
            'POST',
            $this->decisionPath($requestId),
            json_encode(['decision' => 'approve'], JSON_THROW_ON_ERROR),
            $this->decisionHeaders(),
        );
        self::assertSame(403, $selfDecision->getStatusCode(), (string) $selfDecision->getContent());
        self::assertSame(ApprovalStatus::Pending, $this->store->find($requestId)?->status);

        // A DIFFERENT authorized operator decides it.
        $this->login(self::SECOND_OPERATOR_UID);
        $otherDecision = $this->dispatch(
            'POST',
            $this->decisionPath($requestId),
            json_encode(['decision' => 'approve'], JSON_THROW_ON_ERROR),
            $this->decisionHeaders(),
        );
        self::assertSame(204, $otherDecision->getStatusCode(), (string) $otherDecision->getContent());
        $decided = $this->store->find($requestId);
        self::assertSame(ApprovalStatus::Approved, $decided?->status);
        self::assertSame(self::SECOND_OPERATOR_UID, $decided?->decidedByUid);
    }

    #[Test]
    public function self_approval_is_refused_by_default(): void
    {
        $this->login(self::OPERATOR_UID);
        // The approval request was opened by the SAME identity now deciding:
        // tuple principalKey === (string) session operator uid.
        $open = $this->openRequest(principalKey: self::REQUESTER_PRINCIPAL_KEY);

        $response = $this->dispatch(
            'POST',
            $this->decisionPath($open->id),
            json_encode(['decision' => 'approve'], JSON_THROW_ON_ERROR),
            $this->decisionHeaders(),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(ApprovalStatus::Pending, $this->store->find($open->id)?->status);
    }

    #[Test]
    public function self_approval_is_allowed_only_under_the_explicit_config_switch(): void
    {
        $this->login(self::OPERATOR_UID);
        $open = $this->openRequest(principalKey: self::REQUESTER_PRINCIPAL_KEY);
        $router = $this->approvalRouter([
            'mcp' => ['write_tier' => ['approval' => ['allow_self_approval' => true]]],
        ]);

        $response = $this->dispatch(
            'POST',
            $this->decisionPath($open->id),
            json_encode(['decision' => 'approve'], JSON_THROW_ON_ERROR),
            $this->decisionHeaders(),
            approvalRouter: $router,
        );

        self::assertSame(204, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(ApprovalStatus::Approved, $this->store->find($open->id)?->status);
    }

    // ------------------------------------------------------------------
    // Audit projection
    // ------------------------------------------------------------------

    #[Test]
    public function a_successful_decision_projects_one_best_effort_audit_event(): void
    {
        $this->login(self::OPERATOR_UID);
        $open = $this->openRequest(principalKey: '9');

        $response = $this->dispatch(
            'POST',
            $this->decisionPath($open->id),
            json_encode(['decision' => 'deny', 'reason' => 'no'], JSON_THROW_ON_ERROR),
            $this->decisionHeaders(),
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertCount(1, $this->auditRecords);
        $descriptor = $this->auditRecords[0];
        self::assertSame('mcp.approval_decision', $descriptor->kind->value);
        self::assertSame(self::OPERATOR_UID, $descriptor->accountUid);
        self::assertSame($open->id, $descriptor->attributes['request_id']);
        self::assertSame('denied', $descriptor->attributes['decision']);
        self::assertSame('no', $descriptor->attributes['reason']);
    }

    #[Test]
    public function an_audit_projection_failure_never_unwinds_the_durable_decision(): void
    {
        $this->login(self::OPERATOR_UID);
        $open = $this->openRequest(principalKey: '9');
        $this->dispatcher->addListener(
            McpApprovalDecisionAuditListener::EVENT_NAME,
            static function (): void {
                throw new \RuntimeException('projection sink offline');
            },
            priority: 100,
        );

        $response = $this->dispatch(
            'POST',
            $this->decisionPath($open->id),
            json_encode(['decision' => 'approve'], JSON_THROW_ON_ERROR),
            $this->decisionHeaders(),
        );

        self::assertSame(204, $response->getStatusCode(), 'The decision is already durable; a projection failure must not misreport it.');
        self::assertSame(ApprovalStatus::Approved, $this->store->find($open->id)?->status);
    }
}

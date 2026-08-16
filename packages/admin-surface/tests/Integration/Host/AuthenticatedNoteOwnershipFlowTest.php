<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Integration\Host;

use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Access\AccessChecker;
use Waaseyaa\Access\AccountPrincipalFactory;
use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityDeclaration;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\InMemoryCapabilityRegistry;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Access\Middleware\AuthorizationMiddleware;
use Waaseyaa\Access\Middleware\FieldReadContextMiddleware;
use Waaseyaa\Access\Policy\ContentAdminAccessPolicy;
use Waaseyaa\Access\Policy\PublishedContentAccessPolicy;
use Waaseyaa\AdminSurface\AdminSurfaceServiceProvider;
use Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Audit\Bootstrap\IdentityBootstrapReader;
use Waaseyaa\Audit\Bootstrap\SessionBootstrapReader;
use Waaseyaa\Audit\Schema\AuditEventSchemaHandler;
use Waaseyaa\Audit\Writer\DatabaseStrictPrivilegedReadLedger;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpPipeline;
use Waaseyaa\Note\Note;
use Waaseyaa\Note\NoteAccessPolicy;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\User\Middleware\SessionMiddleware;
use Waaseyaa\User\User;

/**
 * Full admin-host note loop using migrated-shape SQL and session-resolved identity.
 */
#[CoversNothing]
final class AuthenticatedNoteOwnershipFlowTest extends TestCase
{
    private DBALDatabase $database;
    private EntityTypeManager $entityTypeManager;
    private EntityAccessHandler $accessHandler;
    private AccountFieldReadScope $scope;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $dispatcher = new SymfonyEventDispatcherAdapter();
        $liveAccessHandler = null;
        $this->entityTypeManager = new EntityTypeManager(
            $dispatcher,
            null,
            function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher, &$liveAccessHandler): EntityRepositoryInterface {
                new SqlSchemaHandler($definition, $this->database)->ensureTable();

                return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                    $definition,
                    new SqlStorageDriver(new SingleConnectionResolver($this->database), $definition->getKeys()['id']),
                    $dispatcher,
                    database: $this->database,
                    accessHandlerResolver: static function () use (&$liveAccessHandler): ?EntityAccessHandler {
                        return $liveAccessHandler;
                    },
                );
            },
        );
        $this->entityTypeManager->registerEntityType(EntityType::fromClass(User::class));
        $this->entityTypeManager->registerEntityType(EntityType::fromClass(Note::class, group: 'content'));
        $this->entityTypeManager->getRepository('note');

        $admin = new User([
            'uid' => 73,
            'uuid' => 'b166f495-7133-469a-a65c-16ef7590af23',
            'name' => 'note-browser-admin',
            'roles' => ['administrator'],
            'permissions' => [],
            'status' => true,
        ]);
        $admin->enforceIsNew();
        $this->entityTypeManager->getRepository('user')->save($admin, validate: false);

        $this->accessHandler = new EntityAccessHandler([
            new NoteAccessPolicy(),
            new PublishedContentAccessPolicy($this->entityTypeManager),
            new ContentAdminAccessPolicy($this->entityTypeManager),
        ]);
        $liveAccessHandler = $this->accessHandler;
        $this->scope = new AccountFieldReadScope();
        EntityReadRuntime::installGuard(new FieldReadGuard(
            $this->scope,
            $this->accessHandler->checkProtectedFieldRead(...),
        ));
        RuntimeSchemaMigrations::audit($this->database);
    }

    protected function tearDown(): void
    {
        EntityReadRuntime::installGuard(null);
    }

    #[Test]
    public function administrator_can_create_read_list_and_delete_their_own_note(): void
    {
        $columns = array_column(iterator_to_array($this->database->query('PRAGMA table_info(note)')), 'name');
        self::assertSame(
            ['id', 'uuid', 'bundle', 'title', 'langcode', '_data'],
            $columns,
            'The regression must retain the six-column migrated note shape where uid round-trips through `_data`.',
        );

        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        AdminSurfaceServiceProvider::registerRoutes(
            $router,
            new GenericAdminSurfaceHost($this->entityTypeManager, $this->accessHandler),
        );

        $created = $this->request($router, '/admin/_surface/note/action/create', 'POST', [
            'attributes' => ['title' => 'Session-owned note', 'body' => 'Browser audit regression'],
        ]);
        $uuid = (string) ($created['data']['id'] ?? '');
        $detail = $this->request($router, "/admin/_surface/note/$uuid", 'GET');
        $list = $this->request($router, '/admin/_surface/note', 'GET');
        $row = $this->database->getConnection()->fetchAssociative('SELECT _data FROM note WHERE uuid = :uuid', ['uuid' => $uuid]);
        $deleted = $this->request($router, '/admin/_surface/note/action/delete', 'POST', [
            'id' => $uuid,
            'mutation_token' => $detail['data']['mutation_token'] ?? null,
        ]);
        $remaining = (int) $this->database->getConnection()->fetchOne('SELECT COUNT(*) FROM note WHERE uuid = :uuid', ['uuid' => $uuid]);

        self::assertTrue($created['ok'], json_encode($created));
        self::assertNotSame('', $uuid);
        self::assertTrue($detail['ok'], json_encode($detail));
        self::assertSame('Session-owned note', $detail['data']['attributes']['title'] ?? null);
        self::assertTrue($list['ok'], json_encode($list));
        self::assertSame(1, $list['data']['total'] ?? null);
        self::assertContains('Session-owned note', array_map(
            static fn(array $entity): mixed => $entity['attributes']['title'] ?? null,
            $list['data']['entities'] ?? [],
        ));
        $stored = is_array($row) ? json_decode((string) $row['_data'], true, flags: JSON_THROW_ON_ERROR) : null;
        self::assertSame(73, $stored['uid'] ?? null, 'The session principal must be persisted as note owner.');
        self::assertTrue($deleted['ok'], json_encode($deleted));
        self::assertSame(0, $remaining);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function request(WaaseyaaRouter $router, string $path, string $method, array $payload = []): array
    {
        $request = Request::create(
            $path,
            $method,
            content: $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR),
        );
        $request->attributes->set('_session', ['waaseyaa_uid' => 73]);
        $match = new UrlMatcher($router->getRouteCollection(), new RequestContext('', $method))->match($path);
        $route = $router->getRouteCollection()->get($match['_route']);
        self::assertNotNull($route);
        $request->attributes->set('_route_object', $route);
        $controller = $route->getDefault('_controller');
        self::assertIsCallable($controller);

        $pipeline = new HttpPipeline()
            ->withMiddleware(new SessionMiddleware($this->entityTypeManager->getRepository('user')))
            ->withMiddleware(new FieldReadContextMiddleware($this->principalFactory(), $this->scope))
            ->withMiddleware(new AuthorizationMiddleware(new AccessChecker()));

        $response = $pipeline->handle($request, new class ($controller, $match) implements HttpHandlerInterface {
            public function __construct(private readonly mixed $controller, private readonly array $match) {}

            public function handle(Request $request): Response
            {
                $args = [$request];
                foreach (['type', 'id', 'action'] as $name) {
                    if (isset($this->match[$name])) {
                        $args[] = $this->match[$name];
                    }
                }

                return new JsonResponse(($this->controller)(...$args));
            }
        });

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function principalFactory(): AccountPrincipalFactory
    {
        $capabilities = new InMemoryCapabilityRegistry();
        $capabilities->register(new CapabilityDeclaration(
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
        $ledger = new DatabaseStrictPrivilegedReadLedger($this->database);

        return new AccountPrincipalFactory(new IdentityBootstrapReader(
            new SessionBootstrapReader(new AuditedFieldRead($capabilities, $ledger)),
            $capabilities,
            'http.identity-bootstrap',
        ));
    }
}

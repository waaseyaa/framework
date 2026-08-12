<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Access\AccessChecker;
use Waaseyaa\Access\AccountPrincipalFactory;
use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityDeclaration;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\InMemoryCapabilityRegistry;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\Middleware\AuthorizationMiddleware;
use Waaseyaa\Access\Middleware\FieldReadContextMiddleware;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Audit\Bootstrap\IdentityBootstrapReader;
use Waaseyaa\Audit\Bootstrap\SessionBootstrapReader;
use Waaseyaa\Audit\Writer\DatabaseStrictPrivilegedReadLedger;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Kernel\BuiltinRouteRegistrar;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpPipeline;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;
use Waaseyaa\User\Middleware\SessionMiddleware;
use Waaseyaa\User\User;

/**
 * Browser-audit regression for administrator access to operational admin pages.
 *
 * The account is persisted in the production storage shape (identity columns plus
 * an `_data` JSON blob), then resolved from an actual session by
 * SessionMiddleware. The route objects are the production ApiServiceProvider
 * routes consumed by the six admin pages.
 */
#[CoversNothing]
final class AdminOperationsAdministratorSessionTest extends TestCase
{
    private DBALDatabase $database;
    private EntityTypeManager $entityTypeManager;
    private WaaseyaaRouter $router;
    private InMemoryCapabilityRegistry $capabilities;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();
        $this->entityTypeManager = new EntityTypeManager(
            $dispatcher,
            null,
            function (string $entityTypeId, EntityTypeInterface $definition) use ($dispatcher): EntityRepository {
                $idKey = $definition->getKeys()['id'] ?? 'id';
                $schema = new SqlSchemaHandler($definition, $this->database);
                $schema->ensureTable();

                return \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
                    $definition,
                    new SqlStorageDriver(new SingleConnectionResolver($this->database), $idKey),
                    $dispatcher,
                );
            },
        );
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'user',
            label: 'User',
            class: User::class,
            keys: ['id' => 'uid', 'uuid' => 'uuid', 'label' => 'name'],
        ));

        $administrator = new User([
            'uid' => 71,
            'uuid' => 'f7c1981f-4078-454f-951e-b357aa48ac80',
            'name' => 'browser-audit-admin',
            'roles' => ['administrator'],
            'permissions' => [],
            'status' => true,
        ]);
        $administrator->enforceIsNew();
        $this->entityTypeManager->getRepository('user')->save($administrator, validate: false);

        RuntimeSchemaMigrations::audit($this->database);
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

        $this->router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        new BuiltinRouteRegistrar($this->entityTypeManager, [new ApiServiceProvider()])->register($this->router);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function operationalPages(): iterable
    {
        yield 'workflows' => ['api.workflow_definitions.list', 'workflows'];
        yield 'queue' => ['api.queue.jobs.index', 'queue'];
        yield 'scheduler' => ['api.scheduler.tasks.index', 'scheduler'];
        yield 'notifications' => ['api.notification.channels.index', 'notifications'];
        yield 'mercure monitor' => ['api.mercure.monitor.channels', 'mercure'];
        yield 'classification policies' => ['api.classification.policies.index', 'classification'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('operationalPages')]
    public function authenticated_administrator_reaches_operational_page_content(
        string $routeName,
        string $contentMarker,
    ): void {
        $columns = array_column(iterator_to_array($this->database->query('PRAGMA table_info(user)')), 'name');
        self::assertNotContains('roles', $columns, 'Roles must be exercised from migrated-shape `_data`, not a fixture column.');
        $stored = $this->database->getConnection()->fetchAssociative('SELECT _data FROM user WHERE uid = :uid', ['uid' => 71]);
        self::assertIsArray($stored);
        self::assertStringContainsString('administrator', (string) $stored['_data']);

        $route = $this->router->getRouteCollection()->get($routeName);
        self::assertNotNull($route, "Production route {$routeName} must be registered.");

        $request = Request::create($route->getPath(), 'GET');
        $request->attributes->set('_route_object', $route);
        $request->attributes->set('_session', ['waaseyaa_uid' => 71]);

        $ledger = new DatabaseStrictPrivilegedReadLedger($this->database);
        $principalFactory = new AccountPrincipalFactory(new IdentityBootstrapReader(
            new SessionBootstrapReader(new AuditedFieldRead($this->capabilities, $ledger)),
            $this->capabilities,
            'http.identity-bootstrap',
        ));
        $pipeline = new HttpPipeline()
            ->withMiddleware(new SessionMiddleware($this->entityTypeManager->getRepository('user')))
            ->withMiddleware(new FieldReadContextMiddleware($principalFactory, new AccountFieldReadScope()))
            ->withMiddleware(new AuthorizationMiddleware(new AccessChecker()));

        $response = $pipeline->handle($request, new class ($contentMarker) implements HttpHandlerInterface {
            public function __construct(private readonly string $contentMarker) {}

            public function handle(Request $request): Response
            {
                return new JsonResponse(['data' => [['content' => $this->contentMarker]]]);
            }
        });

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString($contentMarker, (string) $response->getContent());
        self::assertStringNotContainsString('The required role is missing.', (string) $response->getContent());
    }
}

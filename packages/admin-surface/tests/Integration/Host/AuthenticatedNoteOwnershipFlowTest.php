<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Integration\Host;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccessChecker;
use Waaseyaa\Access\AccountPrincipalFactory;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityDeclaration;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\InMemoryCapabilityRegistry;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Access\Middleware\AuthorizationMiddleware;
use Waaseyaa\Access\Middleware\FieldReadContextMiddleware;
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
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpPipeline;
use Waaseyaa\Note\Note;
use Waaseyaa\Note\NoteAccessPolicy;
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
                (new SqlSchemaHandler($definition, $this->database))->ensureTable();

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

        $this->accessHandler = new EntityAccessHandler([new NoteAccessPolicy()]);
        $liveAccessHandler = $this->accessHandler;
        $this->scope = new AccountFieldReadScope();
        EntityReadRuntime::installGuard(new FieldReadGuard(
            $this->scope,
            $this->accessHandler->checkProtectedFieldRead(...),
        ));
        (new AuditEventSchemaHandler($this->database))->ensureSchema();
    }

    protected function tearDown(): void
    {
        EntityReadRuntime::installGuard(null);
    }

    #[Test]
    public function administrator_can_create_read_list_and_delete_their_own_note(): void
    {
        $columns = array_column(iterator_to_array($this->database->query('PRAGMA table_info(note)')), 'name');
        self::assertNotContains('uid', $columns, 'Ownership must round-trip through migrated-shape `_data`.');

        $request = Request::create('/admin/_surface/note', 'POST');
        $request->attributes->set('_session', ['waaseyaa_uid' => 73]);

        $pipeline = new HttpPipeline()
            ->withMiddleware(new SessionMiddleware($this->entityTypeManager->getRepository('user')))
            ->withMiddleware(new FieldReadContextMiddleware($this->principalFactory(), $this->scope))
            ->withMiddleware(new AuthorizationMiddleware(new AccessChecker()));

        $response = $pipeline->handle($request, new class ($this->entityTypeManager, $this->accessHandler, $this->database) implements HttpHandlerInterface {
            public function __construct(
                private readonly EntityTypeManager $entityTypeManager,
                private readonly EntityAccessHandler $accessHandler,
                private readonly DBALDatabase $database,
            ) {}

            public function handle(Request $request): Response
            {
                $principal = $request->attributes->get('_authorization_principal');
                if (!$principal instanceof AuthorizationPrincipalInterface) {
                    return new JsonResponse(['error' => 'missing principal'], 500);
                }

                $host = new GenericAdminSurfaceHost($this->entityTypeManager, $this->accessHandler);
                $host->resolveSession($request);
                $created = $host->action('note', 'create', [
                    'attributes' => ['title' => 'Session-owned note', 'body' => 'Browser audit regression'],
                ]);
                $uuid = (string) ($created->data['id'] ?? '');
                $detail = $host->get('note', $uuid);
                $list = $host->list('note');
                $row = $this->database->getConnection()->fetchAssociative('SELECT _data FROM note WHERE uuid = :uuid', ['uuid' => $uuid]);
                $deleted = $host->action('note', 'delete', ['id' => $uuid]);
                $remaining = (int) $this->database->getConnection()->fetchOne('SELECT COUNT(*) FROM note WHERE uuid = :uuid', ['uuid' => $uuid]);

                return new JsonResponse([
                    'created' => ['ok' => $created->ok, 'status' => is_array($created->error) ? ($created->error['status'] ?? null) : null],
                    'detail' => ['ok' => $detail->ok, 'title' => $detail->data['attributes']['title'] ?? null],
                    'list' => [
                        'ok' => $list->ok,
                        'total' => $list->data['total'] ?? null,
                        'titles' => array_map(
                            static fn(array $entity): mixed => $entity['attributes']['title'] ?? null,
                            $list->data['entities'] ?? [],
                        ),
                    ],
                    'stored' => is_array($row) ? json_decode((string) $row['_data'], true, flags: JSON_THROW_ON_ERROR) : null,
                    'deleted' => ['ok' => $deleted->ok, 'status' => is_array($deleted->error) ? ($deleted->error['status'] ?? null) : null],
                    'remaining' => $remaining,
                ]);
            }
        });

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($body['created']['ok'], json_encode($body));
        self::assertTrue($body['detail']['ok'], json_encode($body));
        self::assertSame('Session-owned note', $body['detail']['title']);
        self::assertTrue($body['list']['ok'], json_encode($body));
        self::assertSame(1, $body['list']['total']);
        self::assertContains('Session-owned note', $body['list']['titles']);
        self::assertSame(73, $body['stored']['uid'] ?? null, 'The session principal must be persisted as note owner.');
        self::assertTrue($body['deleted']['ok'], json_encode($body));
        self::assertSame(0, $body['remaining']);
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

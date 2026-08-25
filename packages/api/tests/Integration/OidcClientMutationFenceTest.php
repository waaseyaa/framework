<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccessChecker;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Api\Controller\OidcClientController;
use Waaseyaa\Api\Http\Router\OidcClientApiRouter;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\Audit\EntityAuditLogger;
use Waaseyaa\Entity\Audit\EntityWriteAuditListener;
use Waaseyaa\Entity\Concurrency\EntityMutationToken;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Http\Router\JsonApiRouter;
use Waaseyaa\Oidc\Access\OidcClientAccessPolicy;
use Waaseyaa\Oidc\ClientRegistry\OidcClientSystemReader;
use Waaseyaa\Oidc\Entity\OidcClient;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

/**
 * HTTP-level mutation-fence contract for the purpose-built OIDC client admin
 * surface (`/api/oidc-clients/{id}`) versus the auto-generated JSON:API
 * aggregate (`/api/oidc_client/{id}`).
 *
 * @see https://github.com/waaseyaa/framework/issues/2493
 */
#[CoversNothing]
final class OidcClientMutationFenceTest extends TestCase
{
    private DBALDatabase $database;
    private EntityTypeManager $entityTypeManager;
    private EntityRepository $repository;
    private OidcClientApiRouter $dedicated;
    private OidcClientController $controller;
    private JsonApiRouter $generated;
    private EntityAuditLogger $auditLogger;
    private string $auditRoot;
    private AuthorizationPrincipal $admin;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();
        $this->auditRoot = sys_get_temp_dir() . '/waaseyaa_oidc_fence_audit_' . uniqid('', true);
        mkdir($this->auditRoot . '/storage/framework', 0755, true);
        $this->auditLogger = new EntityAuditLogger($this->auditRoot);
        $dispatcher->addSubscriber(new EntityWriteAuditListener($this->auditLogger));

        $entityType = new EntityType(
            id: 'oidc_client',
            label: 'OIDC Client',
            class: OidcClient::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'name'],
            api: true,
        );
        $schemaHandler = new SqlSchemaHandler($entityType, $this->database);
        $schemaHandler->ensureTable();
        $schemaHandler->addFieldColumns([
            'client_id' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
            'name' => ['type' => 'varchar', 'length' => 255, 'not null' => true],
            'is_confidential' => ['type' => 'int', 'not null' => true, 'default' => 0],
            'client_secret_hash' => ['type' => 'varchar', 'length' => 255, 'not null' => false],
        ]);

        $this->repository = \Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $entityType,
            new SqlStorageDriver(new SingleConnectionResolver($this->database)),
            $dispatcher,
            database: $this->database,
        );

        $this->entityTypeManager = new EntityTypeManager(
            $dispatcher,
            null,
            function (string $entityTypeId, EntityTypeInterface $definition): EntityRepository {
                self::assertSame('oidc_client', $entityTypeId);

                return $this->repository;
            },
        );
        $this->entityTypeManager->registerEntityType($entityType);

        $this->controller = new OidcClientController($this->entityTypeManager);
        $this->dedicated = new OidcClientApiRouter($this->controller);
        $this->generated = new JsonApiRouter(
            $this->entityTypeManager,
            new EntityAccessHandler([new OidcClientAccessPolicy()]),
            $this->database,
        );
        $this->admin = new AuthorizationPrincipal(
            1,
            true,
            ['admin'],
            ['administer oidc clients'],
            'test',
        );
        RuntimeSchemaMigrations::broadcast($this->database);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->auditRoot);
    }

    #[Test]
    public function dedicatedGetReturnsAUsableMutationEtagWithoutLeakingTheSecret(): void
    {
        $id = $this->seedClient();

        $response = $this->dedicatedHandle('GET', $id);

        self::assertSame(200, $response->getStatusCode());
        $etag = $response->headers->get('ETag');
        self::assertNotNull($etag);
        $token = EntityMutationToken::fromHttpIfMatch($etag);
        self::assertSame('oidc_client', $token->entityTypeId);
        self::assertSame($id, $token->entityId);

        $body = $this->decode($response);
        self::assertSame($token->toOpaqueString(), $body['meta']['mutation_token'] ?? null);
        self::assertArrayNotHasKey('client_secret', $body['data']);
        self::assertArrayNotHasKey('client_secret_hash', $body['data']);
    }

    #[Test]
    public function dedicatedPatchWithoutIfMatchReturns428(): void
    {
        $id = $this->seedClient();

        $response = $this->dedicatedHandle('PATCH', $id, ['name' => 'lost-update']);

        $this->assertPreconditionRequired($response);
        self::assertSame('Minoo', $this->clientName($id));
        self::assertSame([], $this->auditLogger->read('oidc_client'));
    }

    #[Test]
    public function dedicatedPatchWithoutIfMatchOnUnknownIdReturns428Not404(): void
    {
        $response = $this->dedicatedHandle('PATCH', '999', ['name' => 'ghost']);

        $this->assertPreconditionRequired($response);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function malformedIfMatchValues(): iterable
    {
        yield 'weak' => ['W/"emt1.invalid"'];
        yield 'wildcard' => ['*'];
        yield 'quoted-wildcard' => ['"*"'];
        yield 'comma-list' => ['"one", "two"'];
        yield 'unquoted' => ['emt1.not-an-etag'];
        yield 'empty-quotes' => ['""'];
    }

    #[Test]
    #[DataProvider('malformedIfMatchValues')]
    public function dedicatedPatchWithMalformedIfMatchReturnsInvalidPrecondition(string $ifMatch): void
    {
        $id = $this->seedClient();

        $response = $this->dedicatedHandle('PATCH', $id, ['name' => 'rejected'], $ifMatch);

        $this->assertInvalidMutationPrecondition($response);
        self::assertSame('Minoo', $this->clientName($id));
        self::assertSame([], $this->auditLogger->read('oidc_client'));
    }

    #[Test]
    public function dedicatedPatchWithStaleIfMatchReturns412AndDoesNotPersist(): void
    {
        $id = $this->seedClient();
        $stale = $this->currentDedicatedEtag($id);
        $this->advanceClient($id, 'winner');

        $response = $this->dedicatedHandle('PATCH', $id, ['name' => 'stale-loser'], $stale);

        $this->assertPreconditionFailed($response);
        self::assertSame('winner', $this->clientName($id));
        self::assertSame([], $this->auditLogger->read('oidc_client'));
    }

    #[Test]
    public function dedicatedPatchWithCurrentTokenSucceedsAndWritesUpdateAudit(): void
    {
        $id = $this->seedClient();
        $etag = $this->currentDedicatedEtag($id);

        $response = $this->dedicatedHandle('PATCH', $id, ['name' => 'renamed'], $etag);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('renamed', $this->clientName($id));
        $successor = $response->headers->get('ETag');
        self::assertNotNull($successor);
        self::assertNotSame($etag, $successor);
        EntityMutationToken::fromHttpIfMatch($successor);

        $entries = $this->auditLogger->read('oidc_client');
        self::assertCount(1, $entries);
        self::assertSame('update', $entries[0]['action']);
        self::assertSame($id, $entries[0]['entity_id']);
        self::assertSame('oidc_client', $entries[0]['entity_type']);
        self::assertArrayNotHasKey('client_secret', $this->decode($response)['data']);
    }

    #[Test]
    public function dedicatedDeleteWithoutIfMatchReturns428(): void
    {
        $id = $this->seedClient();

        $response = $this->dedicatedHandle('DELETE', $id);

        $this->assertPreconditionRequired($response);
        self::assertNotNull($this->repository->find($id));
        self::assertSame([], $this->auditLogger->read('oidc_client'));
    }

    #[Test]
    public function dedicatedDeleteWithMalformedIfMatchReturnsInvalidPrecondition(): void
    {
        $id = $this->seedClient();

        $response = $this->dedicatedHandle('DELETE', $id, ifMatch: '*');

        $this->assertInvalidMutationPrecondition($response);
        self::assertNotNull($this->repository->find($id));
    }

    #[Test]
    public function dedicatedDeleteWithStaleIfMatchReturns412AndKeepsTheRow(): void
    {
        $id = $this->seedClient();
        $stale = $this->currentDedicatedEtag($id);
        $this->advanceClient($id, 'still-here');

        $response = $this->dedicatedHandle('DELETE', $id, ifMatch: $stale);

        $this->assertPreconditionFailed($response);
        self::assertSame('still-here', $this->clientName($id));
        self::assertSame([], $this->auditLogger->read('oidc_client'));
    }

    #[Test]
    public function dedicatedDeleteWithCurrentTokenSucceedsAndWritesDeleteAudit(): void
    {
        $id = $this->seedClient();
        $etag = $this->currentDedicatedEtag($id);

        $response = $this->dedicatedHandle('DELETE', $id, ifMatch: $etag);

        self::assertSame(204, $response->getStatusCode());
        self::assertNull($this->repository->find($id));
        $entries = $this->auditLogger->read('oidc_client');
        self::assertCount(1, $entries);
        self::assertSame('delete', $entries[0]['action']);
        self::assertSame($id, $entries[0]['entity_id']);
    }

    #[Test]
    public function generatedJsonApiRouteAlreadyRequiresIfMatchOnPatchAndDelete(): void
    {
        $id = $this->seedClient();

        $patch = $this->generatedHandle('PATCH', $id, ['name' => 'generated-lost']);
        $delete = $this->generatedHandle('DELETE', $id);

        $this->assertPreconditionRequired($patch);
        $this->assertPreconditionRequired($delete);
        self::assertSame('Minoo', $this->clientName($id));
    }

    #[Test]
    public function purposeBuiltAndGeneratedSurfacesAgreeOnPreconditionOutcomes(): void
    {
        $id = $this->seedClient();
        $current = $this->currentDedicatedEtag($id);
        $stale = $current;
        $this->advanceClient($id, 'head');
        $fresh = $this->currentDedicatedEtag($id);

        $cases = [
            'absent-patch' => ['PATCH', null],
            'absent-delete' => ['DELETE', null],
            'malformed-patch' => ['PATCH', '*'],
            'stale-patch' => ['PATCH', $stale],
            'stale-delete' => ['DELETE', $stale],
        ];
        foreach ($cases as $label => [$method, $ifMatch]) {
            $dedicated = $this->dedicatedHandle($method, $id, $method === 'PATCH' ? ['name' => 'x'] : null, $ifMatch);
            $generated = $this->generatedHandle($method, $id, $method === 'PATCH' ? ['name' => 'x'] : null, $ifMatch);
            self::assertSame(
                $generated->getStatusCode(),
                $dedicated->getStatusCode(),
                $label . ' status',
            );
            self::assertSame(
                $this->decode($generated)['errors'][0]['code'] ?? null,
                $this->decode($dedicated)['errors'][0]['code'] ?? null,
                $label . ' error code',
            );
        }

        $dedicatedOk = $this->dedicatedHandle('PATCH', $id, ['name' => 'dedicated-ok'], $fresh);
        self::assertSame(200, $dedicatedOk->getStatusCode());
        $generatedFresh = $this->currentDedicatedEtag($id);
        $generatedOk = $this->generatedHandle('PATCH', $id, ['name' => 'generated-ok'], $generatedFresh);
        self::assertSame(200, $generatedOk->getStatusCode());
        self::assertSame('generated-ok', $this->clientName($id));
    }

    #[Test]
    public function workerStyleRepeatedReadsDeriveTheTokenFromCurrentEntityState(): void
    {
        $id = $this->seedClient();
        $properties = (new \ReflectionClass($this->controller))->getProperties();
        foreach ($properties as $property) {
            self::assertNotSame(
                'mutationToken',
                $property->getName(),
                'OidcClientController must not retain a mutation token between requests.',
            );
        }

        $first = $this->dedicatedHandle('GET', $id);
        $firstEtag = $first->headers->get('ETag');
        self::assertNotNull($firstEtag);
        $this->advanceClient($id, 'after-worker-tick');

        $second = $this->dedicatedHandle('GET', $id);
        $secondEtag = $second->headers->get('ETag');
        self::assertNotNull($secondEtag);
        self::assertNotSame($firstEtag, $secondEtag);
        self::assertSame(
            $this->loadClient($id)->mutationToken()?->toStrongEtag(),
            $secondEtag,
        );
        self::assertSame(
            $this->loadClient($id)->mutationToken()?->toOpaqueString(),
            $this->decode($second)['meta']['mutation_token'] ?? null,
        );
    }

    #[Test]
    public function dedicatedMutationRoutesStayAdminGatedBeforeTheFence(): void
    {
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $entityTypeManager->registerEntityType(EntityType::fromClass(OidcClient::class));
        $router = new WaaseyaaRouter();
        (new ApiServiceProvider())->routes($router, $entityTypeManager);
        $checker = new AccessChecker();
        $editor = new AuthorizationPrincipal(2, true, ['editor'], [], 'test');

        foreach (['api.oidc-clients.update', 'api.oidc-clients.delete'] as $name) {
            $route = $router->getRouteCollection()->get($name);
            self::assertNotNull($route);
            self::assertSame('admin', $route->getOption('_role'));
            self::assertTrue($checker->check($route, $this->admin)->isAllowed(), $name);
            self::assertTrue($checker->check($route, $editor)->isForbidden(), $name);
        }
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function dedicatedHandle(string $method, string $id, ?array $body = null, ?string $ifMatch = null): Response
    {
        $action = match ($method) {
            'GET' => 'show',
            'PATCH' => 'update',
            'DELETE' => 'delete',
            default => throw new \InvalidArgumentException($method),
        };
        $request = Request::create(
            '/api/oidc-clients/' . $id,
            $method,
            content: $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
        );
        $request->headers->set('Content-Type', 'application/json');
        $request->attributes->set('_controller', OidcClientController::class . '::' . $action);
        $request->attributes->set('id', $id);
        if ($ifMatch !== null) {
            $request->headers->set('If-Match', $ifMatch);
        }

        return $this->dedicated->handle($request);
    }

    /**
     * @param array<string, mixed>|null $attributes
     */
    private function generatedHandle(string $method, string $id, ?array $attributes = null, ?string $ifMatch = null): Response
    {
        $action = match ($method) {
            'GET' => 'show',
            'PATCH' => 'update',
            'DELETE' => 'destroy',
            default => throw new \InvalidArgumentException($method),
        };
        $content = '';
        if ($method === 'PATCH') {
            $content = json_encode([
                'data' => [
                    'type' => 'oidc_client',
                    'attributes' => $attributes ?? [],
                ],
            ], JSON_THROW_ON_ERROR);
        }
        $request = Request::create('/api/oidc_client/' . $id, $method, content: $content);
        $request->headers->set('Content-Type', 'application/vnd.api+json');
        $request->attributes->set('_controller', JsonApiController::class . '::' . $action);
        $request->attributes->set('_entity_type', 'oidc_client');
        $request->attributes->set('id', $id);
        $request->attributes->set('_account', $this->admin);
        $request->attributes->set('_parsed_body', $content === '' ? null : json_decode($content, true, flags: JSON_THROW_ON_ERROR));
        $request->attributes->set('_broadcast_storage', new BroadcastStorage($this->database));
        if ($ifMatch !== null) {
            $request->headers->set('If-Match', $ifMatch);
        }

        return $this->generated->handle($request);
    }

    private function seedClient(): string
    {
        $client = $this->repository->create([
            'client_id' => 'minoo-web',
            'name' => 'Minoo',
            'redirect_uris' => ['https://minoo.test/callback'],
            'client_secret_hash' => 'hashed-secret',
        ]);
        $this->repository->save($client);
        $id = (string) $client->id();
        $this->auditLogger->read();
        file_put_contents($this->auditRoot . '/storage/framework/entity-audit.jsonl', '');

        return $id;
    }

    private function advanceClient(string $id, string $name): void
    {
        $client = $this->loadClient($id);
        $client->setName($name);
        $this->repository->save($client);
        file_put_contents($this->auditRoot . '/storage/framework/entity-audit.jsonl', '');
    }

    private function loadClient(string $id): OidcClient
    {
        $client = $this->repository->find($id);
        self::assertInstanceOf(OidcClient::class, $client);

        return $client;
    }

    private function clientName(string $id): string
    {
        return new OidcClientSystemReader()
            ->registration($this->loadClient($id))
            ->name;
    }

    private function currentDedicatedEtag(string $id): string
    {
        $loaded = $this->loadClient($id)->mutationToken();
        self::assertNotNull($loaded);

        return $loaded->toStrongEtag();
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $content = (string) $response->getContent();
        if ($content === '') {
            return [];
        }

        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function assertPreconditionRequired(Response $response): void
    {
        self::assertSame(428, $response->getStatusCode());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
        $body = $this->decode($response);
        self::assertSame('1.1', $body['jsonapi']['version'] ?? null);
        self::assertSame('428', $body['errors'][0]['status'] ?? null);
        self::assertSame('MUTATION_PRECONDITION_REQUIRED', $body['errors'][0]['code'] ?? null);
        self::assertSame('Precondition Required', $body['errors'][0]['title'] ?? null);
        self::assertArrayNotHasKey('meta', $body['errors'][0]);
    }

    private function assertInvalidMutationPrecondition(Response $response): void
    {
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
        $body = $this->decode($response);
        self::assertSame('1.1', $body['jsonapi']['version'] ?? null);
        self::assertSame('400', $body['errors'][0]['status'] ?? null);
        self::assertSame('INVALID_MUTATION_PRECONDITION', $body['errors'][0]['code'] ?? null);
        self::assertArrayNotHasKey('meta', $body['errors'][0]);
    }

    private function assertPreconditionFailed(Response $response): void
    {
        self::assertSame(412, $response->getStatusCode());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
        $body = $this->decode($response);
        self::assertSame('1.1', $body['jsonapi']['version'] ?? null);
        self::assertSame('412', $body['errors'][0]['status'] ?? null);
        self::assertSame('MUTATION_PRECONDITION_FAILED', $body['errors'][0]['code'] ?? null);
        self::assertArrayNotHasKey('meta', $body['errors'][0]);
        self::assertStringNotContainsString('emt1.', (string) $response->getContent());
    }
}

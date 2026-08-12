<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Integration\Host;

use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Waaseyaa\Access\AccessChecker;
use Waaseyaa\Access\AccountPrincipalFactory;
use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityDeclaration;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\InMemoryCapabilityRegistry;
use Waaseyaa\Access\ConfigEntityAccessPolicy;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Access\Middleware\AuthorizationMiddleware;
use Waaseyaa\Access\Middleware\FieldReadContextMiddleware;
use Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost;
use Waaseyaa\AdminSurface\AdminSurfaceServiceProvider;
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
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpPipeline;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Kernel\Bootstrap\AccessPolicyRegistry;
use Waaseyaa\Foundation\Kernel\Bootstrap\PolicyDependencyResolverInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\Taxonomy\TaxonomyServiceProvider;
use Waaseyaa\Taxonomy\Term;
use Waaseyaa\Taxonomy\Vocabulary;
use Waaseyaa\Taxonomy\VocabularyAccessPolicy;
use Waaseyaa\User\Middleware\SessionMiddleware;
use Waaseyaa\User\User;

/** Migrated-shape, session-authenticated vocabulary admin regression. */
#[CoversNothing]
final class AuthenticatedVocabularyActionsFlowTest extends TestCase
{
    private DBALDatabase $database;
    private EntityTypeManager $entityTypeManager;
    private EntityAccessHandler $accessHandler;
    private AccountFieldReadScope $scope;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();
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
                    accessHandlerResolver: static fn(): ?EntityAccessHandler => $liveAccessHandler,
                );
            },
        );
        $this->entityTypeManager->registerEntityType(EntityType::fromClass(User::class));
        $provider = new TaxonomyServiceProvider();
        $provider->register();
        foreach ($provider->getEntityTypes() as $definition) {
            $this->entityTypeManager->registerEntityType($definition);
        }

        $admin = new User([
            'uid' => 81,
            'uuid' => '19997072-9959-4554-8e1f-13b75084c3b7',
            'name' => 'vocabulary-browser-admin',
            'roles' => ['administrator'],
            'permissions' => [],
            'status' => true,
        ]);
        $admin->enforceIsNew();
        $this->entityTypeManager->getRepository('user')->save($admin, validate: false);

        $renamed = new Vocabulary([
            'vid' => 'renamed',
            'name' => '',
        ]);
        $renamed->enforceIsNew();
        $this->entityTypeManager->getRepository('taxonomy_vocabulary')->save($renamed, validate: false);
        $empty = new Vocabulary([
            'vid' => 'empty',
            'name' => 'Empty vocabulary',
        ]);
        $empty->enforceIsNew();
        $this->entityTypeManager->getRepository('taxonomy_vocabulary')->save($empty, validate: false);
        $occupied = new Vocabulary([
            'vid' => 'occupied',
            'name' => 'Occupied vocabulary',
        ]);
        $occupied->enforceIsNew();
        $this->entityTypeManager->getRepository('taxonomy_vocabulary')->save($occupied, validate: false);
        $term = new Term([
            'uuid' => 'f782010a-f4c0-42d2-a5ca-33593aa8c310',
            'vid' => 'occupied',
            'name' => 'Referenced term',
        ]);
        $this->entityTypeManager->getRepository('taxonomy_term')->save($term, validate: false);

        $manager = $this->entityTypeManager;
        $database = $this->database;
        $this->accessHandler = (new AccessPolicyRegistry(
            new NullLogger(),
            new class ($manager, $database) implements PolicyDependencyResolverInterface {
                public function __construct(
                    private readonly EntityTypeManager $manager,
                    private readonly DBALDatabase $database,
                ) {}

                public function resolveParameter(string $policyClass, \ReflectionParameter $param, array $entityTypes): mixed
                {
                    if ($param->getType() instanceof \ReflectionNamedType && $param->getType()->getName() === 'array') {
                        return $entityTypes;
                    }
                    if ($param->getType() instanceof \ReflectionNamedType
                        && is_a($this->manager, $param->getType()->getName())) {
                        return $this->manager;
                    }
                    if ($param->getType() instanceof \ReflectionNamedType
                        && is_a($this->database, $param->getType()->getName())) {
                        return $this->database;
                    }
                    throw new \LogicException("Unresolved policy dependency {$policyClass}::\${$param->getName()}");
                }
            },
        ))->discover(new PackageManifest(policies: [
            ConfigEntityAccessPolicy::class => ['taxonomy_vocabulary'],
            VocabularyAccessPolicy::class => ['taxonomy_vocabulary'],
        ]));
        $provider->setKernelServices(new class ($this->entityTypeManager, $this->database, $dispatcher) implements KernelServicesInterface {
            public function __construct(
                private readonly EntityTypeManager $manager,
                private readonly DBALDatabase $database,
                private readonly EventDispatcher $dispatcher,
            ) {}

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    EntityTypeManager::class, \Waaseyaa\Entity\EntityTypeManagerInterface::class => $this->manager,
                    \Waaseyaa\Database\DatabaseInterface::class => $this->database,
                    \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $this->dispatcher,
                    default => null,
                };
            }
        });
        $provider->boot();
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
    public function administrator_can_title_edit_and_safely_delete_vocabulary_rows(): void
    {
        $columns = array_column(iterator_to_array($this->database->query('PRAGMA table_info(taxonomy_vocabulary)')), 'name');
        self::assertContains('_data', $columns);
        self::assertNotContains('description', $columns, 'Optional fields must round-trip through migrated `_data`.');

        $host = new GenericAdminSurfaceHost($this->entityTypeManager, $this->accessHandler);
        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        AdminSurfaceServiceProvider::registerRoutes($router, $host);
        $catalog = $this->request($router, '/admin/_surface/catalog', 'GET');
        $entry = array_values(array_filter($catalog['data']['entities'] ?? [], static fn(array $row): bool => $row['id'] === 'taxonomy_vocabulary'))[0] ?? [];
        $create = $this->request($router, '/admin/_surface/taxonomy_vocabulary/action/create', 'POST', [
            'attributes' => ['vid' => 'bypass', 'name' => 'Bypass'],
        ]);
        $loaded = $this->request($router, '/admin/_surface/taxonomy_vocabulary/renamed', 'GET');
        $schema = $this->request($router, '/admin/_surface/taxonomy_vocabulary/action/schema', 'POST', [
            'id' => 'renamed',
        ]);
        $browserAttributes = array_intersect_key(
            $loaded['data']['attributes'] ?? [],
            $schema['data']['properties'] ?? [],
        );
        foreach ($schema['data']['properties'] ?? [] as $fieldName => $property) {
            if (($property['readOnly'] ?? false) === true) {
                unset($browserAttributes[$fieldName]);
            }
        }
        $browserAttributes['name'] = 'Audit vocabulary';
        $updated = $this->request($router, '/admin/_surface/taxonomy_vocabulary/action/update', 'POST', [
            'id' => 'renamed',
            'mutation_token' => $loaded['data']['mutation_token'],
            // Mirror SchemaForm's schema-declared writable projection after
            // loading the complete migrated row and changing only its title.
            'attributes' => $browserAttributes,
        ]);
        $list = $this->request($router, '/admin/_surface/taxonomy_vocabulary', 'GET');
        $byId = array_column($list['data']['entities'] ?? [], null, 'id');
        $emptyDelete = $this->request($router, '/admin/_surface/taxonomy_vocabulary/action/delete', 'POST', [
            'id' => 'empty',
            'mutation_token' => $byId['empty']['mutation_token'] ?? null,
        ]);
        $occupiedDelete = $this->request($router, '/admin/_surface/taxonomy_vocabulary/action/delete', 'POST', [
            'id' => 'occupied',
            'mutation_token' => $byId['occupied']['mutation_token'] ?? null,
        ]);

        $body = [
            'catalog' => $entry,
            'create' => $create,
            'loaded' => $loaded,
            'schema' => $schema,
            'updated' => $updated,
            'list' => $list,
            'emptyDelete' => $emptyDelete,
            'occupiedDelete' => $occupiedDelete,
            'emptyRemaining' => $this->entityTypeManager->getRepository('taxonomy_vocabulary')->find('empty') !== null,
            'occupiedRemaining' => $this->entityTypeManager->getRepository('taxonomy_vocabulary')->find('occupied') !== null,
        ];
        self::assertTrue($body['catalog']['capabilities']['update'] ?? false, json_encode($body));
        self::assertTrue($body['catalog']['capabilities']['delete'] ?? false, json_encode($body));
        self::assertContains('delete', array_column($body['catalog']['actions'] ?? [], 'id'));
        self::assertFalse($body['create']['ok'], json_encode($body));
        self::assertSame('', $body['loaded']['data']['attributes']['bundle'] ?? null, json_encode($body));
        self::assertArrayNotHasKey('bundle', $body['schema']['data']['properties'] ?? [], json_encode($body));
        self::assertTrue($body['updated']['ok'], json_encode($body));
        $names = array_map(
            static fn(array $row): mixed => $row['attributes']['name'] ?? null,
            $body['list']['data']['entities'] ?? [],
        );
        self::assertContains('Audit vocabulary', $names, json_encode($body));
        $occupiedRow = array_values(array_filter(
            $body['list']['data']['entities'] ?? [],
            static fn(array $row): bool => $row['id'] === 'occupied',
        ))[0] ?? [];
        self::assertTrue($occupiedRow['capabilities']['delete'] ?? false, json_encode($body));
        self::assertTrue($body['emptyDelete']['ok'], json_encode($body));
        self::assertFalse($body['emptyRemaining']);
        self::assertFalse($body['occupiedDelete']['ok'], json_encode($body));
        self::assertStringContainsString('contains terms', $body['occupiedDelete']['error']['detail'] ?? '');
        self::assertTrue($body['occupiedRemaining']);

        $foreignKeys = iterator_to_array($this->database->query('PRAGMA foreign_key_list(taxonomy_term)'));
        self::assertContains('taxonomy_vocabulary', array_column($foreignKeys, 'table'));

        $occupied = $this->entityTypeManager->getRepository('taxonomy_vocabulary')->find('occupied');
        self::assertNotNull($occupied);
        try {
            $this->entityTypeManager->getRepository('taxonomy_vocabulary')->deleteMany([$occupied]);
            self::fail('The storage constraint must reject a batch delete of a referenced vocabulary.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('FOREIGN KEY', strtoupper($exception->getMessage()));
        }
        self::assertNotNull($this->entityTypeManager->getRepository('taxonomy_vocabulary')->find('occupied'));
        try {
            $this->entityTypeManager->getRepository('taxonomy_vocabulary')->delete($occupied);
            self::fail('The storage constraint must reject a direct delete of a referenced vocabulary.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('FOREIGN KEY', strtoupper($exception->getMessage()));
        }
        self::assertNotNull($this->entityTypeManager->getRepository('taxonomy_vocabulary')->find('occupied'));
    }

    /** @return array<string, mixed> */
    private function request(WaaseyaaRouter $router, string $path, string $method, array $payload = []): array
    {
        $request = Request::create(
            $path,
            $method,
            content: $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR),
        );
        $request->attributes->set('_session', ['waaseyaa_uid' => 81]);
        $context = new RequestContext('', $method);
        $match = (new UrlMatcher($router->getRouteCollection(), $context))->match($path);
        $route = $router->getRouteCollection()->get($match['_route']);
        self::assertNotNull($route);
        $request->attributes->set('_route_object', $route);
        $controller = $route->getDefault('_controller');
        self::assertIsCallable($controller);

        $pipeline = (new HttpPipeline())
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

                return new \Symfony\Component\HttpFoundation\JsonResponse(($this->controller)(...$args));
            }
        });

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

        return new AccountPrincipalFactory(new IdentityBootstrapReader(
            new SessionBootstrapReader(new AuditedFieldRead(
                $capabilities,
                new DatabaseStrictPrivilegedReadLedger($this->database),
            )),
            $capabilities,
            'http.identity-bootstrap',
        ));
    }
}

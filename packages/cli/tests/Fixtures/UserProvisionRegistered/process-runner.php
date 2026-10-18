<?php

declare(strict_types=1);

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Access\AccountPrincipalFactory;
use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityDeclaration;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\InMemoryCapabilityRegistry;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\Middleware\FieldReadContextMiddleware;
use Waaseyaa\Access\PermissionHandler;
use Waaseyaa\Access\User\UserIdentityLookupInterface;
use Waaseyaa\AdminSurface\AdminSurfaceServiceProvider;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Audit\Bootstrap\IdentityBootstrapReader;
use Waaseyaa\Audit\Bootstrap\SessionBootstrapReader;
use Waaseyaa\Audit\Writer\DatabaseStrictPrivilegedReadLedger;
use Waaseyaa\Auth\Controller\LoginController;
use Waaseyaa\Auth\RateLimiter;
use Waaseyaa\Auth\TwoFactorManager;
use Waaseyaa\Auth\TwoFactorService;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Handler\UserProvisionRegisteredHandler;
use Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompilerFactory;
use Waaseyaa\CLI\UserProvisioning\RegisteredRoleAccountProvisioner;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\EntityStorage\Concurrency\EntityMutationAuthority;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriverV2;
use Waaseyaa\EntityStorage\Driver\StorageBoundary;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\EntityMutationAuthoritySchema;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\Field\FieldTypeManager;
use Waaseyaa\Field\FieldTypeManagerInterface;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpPipeline;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\SiteManifestParser;
use Waaseyaa\Tests\Support\AuthenticationEligibilityFixture;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;
use Waaseyaa\Tests\Support\UserInternalFieldReaderFixture;
use Waaseyaa\User\Middleware\SessionMiddleware;
use Waaseyaa\User\RegisteredRoleAssignmentService;
use Waaseyaa\User\RoleRepository;
use Waaseyaa\User\User;

require dirname(__DIR__, 5) . '/vendor/autoload.php';

final readonly class ProcessIdentityLookupFixture implements UserIdentityLookupInterface
{
    public function findActiveByLogin(EntityRepositoryInterface $repository, string $login): ?EntityInterface
    {
        return $this->one($repository, 'name', $login, '=');
    }

    public function findActiveByMail(EntityRepositoryInterface $repository, string $mail): ?EntityInterface
    {
        return $this->one($repository, 'mail', $mail, 'CASE_INSENSITIVE_EQUALS');
    }

    public function loginExists(EntityRepositoryInterface $repository, string $login): bool
    {
        return $repository->getQuery()->accessCheck(false)
            ->condition('name', $login, 'CASE_INSENSITIVE_EQUALS')
            ->range(0, 1)->execute() !== [];
    }

    public function mailExists(EntityRepositoryInterface $repository, string $mail): bool
    {
        return $repository->getQuery()->accessCheck(false)
            ->condition('mail', $mail, 'CASE_INSENSITIVE_EQUALS')
            ->range(0, 1)->execute() !== [];
    }

    private function one(EntityRepositoryInterface $repository, string $field, string $value, string $operator): ?EntityInterface
    {
        $ids = $repository->getQuery()->accessCheck(false)
            ->condition($field, $value, $operator)
            ->condition('status', true)
            ->range(0, 2)->execute();

        return count($ids) === 1 ? $repository->find((string) $ids[0]) : null;
    }
}

final readonly class ProcessEntityTypeManagerFixture implements EntityTypeManagerInterface
{
    public function __construct(
        private EntityTypeInterface $type,
        private EntityRepositoryInterface $repository,
    ) {}

    public function getDefinition(string $entityTypeId): EntityTypeInterface
    {
        return $this->type;
    }

    /** @return array<string, FieldDefinitionInterface> */
    public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array
    {
        return $this->type->getFieldDefinitions();
    }

    public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void
    {
        throw new LogicException('Not supported.');
    }

    public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void
    {
        throw new LogicException('Not supported.');
    }

    public function getDefinitions(): array
    {
        return [$this->type->id() => $this->type];
    }
    public function hasDefinition(string $entityTypeId): bool
    {
        return $entityTypeId === 'user';
    }

    public function getStorage(string $entityTypeId): EntityStorageInterface
    {
        throw new LogicException('Not supported.');
    }

    public function getRepository(string $entityTypeId): EntityRepositoryInterface
    {
        if ($entityTypeId !== 'user') {
            throw new LogicException('Unknown entity type.');
        }

        return $this->repository;
    }
}

$mode = $argv[1] ?? '';
$databasePath = $argv[2] ?? '';
if (!in_array($mode, ['prepare', 'provision', 'inspect', 'seed-inactive', 'ordinary-create', 'inspect-contended', 'login-admin'], true) || $databasePath === '') {
    exit(64);
}

EntityType::clearFromClassCache();
$database = DBALDatabase::createSqlite($databasePath, 'testing');
$type = EntityType::fromClass(User::class);
$schema = new SqlSchemaHandler($type, $database);
if ($mode === 'prepare') {
    $schema->ensureTable();
    EntityMutationAuthoritySchema::ensure($database);
    materializeCanonicalCommunityEventsProvider(dirname($databasePath) . '/generated-app');
    exit(0);
}
$schema->assertRuntimeSchema();
loadCanonicalCommunityEventsProvider(dirname($databasePath) . '/generated-app');
$boundary = new StorageBoundary();
$repository = new EntityRepository(
    $type,
    new SqlStorageDriverV2(
        new SqlStorageDriver(new SingleConnectionResolver($database), 'uid'),
        $boundary->driverRowFactory(),
        $boundary->driverSnapshotReader(),
    ),
    new EventDispatcher(),
    database: $database,
    mutationAuthority: new EntityMutationAuthority($database, 'primary'),
    storageBoundary: $boundary,
);
$internal = new UserInternalFieldReaderFixture();

if ($mode === 'login-admin') {
    // Fresh-process SQLite + real login/session/controller composition. Identity
    // lookup/credential readers are test adapters; principal bootstrap is audited.
    $sessions = dirname($databasePath) . '/sessions';
    if (!is_dir($sessions)) {
        mkdir($sessions, 0o700);
    }
    session_save_path($sessions);
    session_start();
    $manager = new EntityTypeManager(
        new EventDispatcher(),
        null,
        static fn(): EntityRepository => $repository,
    );
    $manager->registerEntityType($type);
    $login = new LoginController(
        $manager,
        new RateLimiter(),
        new TwoFactorService(new TwoFactorManager(), $manager, $internal),
        new ProcessIdentityLookupFixture(),
        $internal,
        AuthenticationEligibilityFixture::policy(),
    );
    $loginResponse = $login(Request::create('/api/auth/login', 'POST', content: stream_get_contents(STDIN)));

    RuntimeSchemaMigrations::audit($database);
    $capabilities = new InMemoryCapabilityRegistry();
    $capabilities->register(new CapabilityDeclaration(
        issuer: 'http.identity-bootstrap',
        reason: CapabilityReason::SessionBootstrap,
        entityTypes: ['user'],
        bundles: ['user'],
        fields: ['roles', 'permissions', 'status'],
        actorSemantics: [CapabilityActorSemantics::NoActingContext],
        maxTtlSeconds: 60,
        justification: 'Resolve persisted generated-owner authorization after login.',
        bindTenantFromContext: true,
        bindCommunityFromContext: true,
    ));
    $principals = new AccountPrincipalFactory(new IdentityBootstrapReader(
        new SessionBootstrapReader(new AuditedFieldRead($capabilities, new DatabaseStrictPrivilegedReadLedger($database))),
        $capabilities,
        'http.identity-bootstrap',
    ));
    $provider = new AdminSurfaceServiceProvider();
    $provider->setKernelServices(new class implements KernelServicesInterface {
        public function get(string $abstract): ?object
        {
            return $abstract === FieldTypeManagerInterface::class ? new FieldTypeManager() : null;
        }
    });
    $router = new WaaseyaaRouter(new RequestContext('', 'GET'));
    $provider->routes($router, $manager);
    $route = $router->getRouteCollection()->get('admin_surface.session');
    if ($route === null) {
        throw new LogicException('The production admin session route is missing.');
    }
    $request = Request::create($route->getPath(), 'GET');
    $request->attributes->set('_controller', $route->getDefault('_controller'));
    $pipeline = new HttpPipeline()
        ->withMiddleware(new SessionMiddleware($repository, internalFields: $internal))
        ->withMiddleware(new FieldReadContextMiddleware($principals, new AccountFieldReadScope()));
    $response = $pipeline->handle($request, new class implements HttpHandlerInterface {
        public function handle(Request $request): Response
        {
            return new ControllerDispatcher([])->dispatch($request);
        }
    });
    $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    session_destroy();
    echo CanonicalJson::encode([
        'admin_status' => $response->getStatusCode(),
        'login_status' => $loginResponse->getStatusCode(),
        'roles' => $body['data']['account']['roles'] ?? [],
    ]) . "\n";
    exit(0);
}

if ($mode === 'seed-inactive') {
    $repository->save($repository->create([
        'name' => 'historical-owner',
        'mail' => 'historical@example.test',
        'pass' => password_hash('historical-password', PASSWORD_DEFAULT),
        'roles' => [],
        'permissions' => [],
        'status' => false,
        'created' => 1_700_000_000,
    ]), validate: false);
    exit(0);
}

if ($mode === 'ordinary-create') {
    try {
        $repository->save($repository->create([
            'name' => 'Contended.Owner',
            'mail' => 'Contended@Example.TEST',
            'pass' => password_hash('ordinary-account-password', PASSWORD_DEFAULT),
            'roles' => [],
            'permissions' => [],
            'status' => true,
            'created' => 1_700_000_001,
        ]), validate: false);
        echo CanonicalJson::encode(['status' => 'created']) . "\n";
        exit(0);
    } catch (UniqueConstraintViolationException) {
        echo CanonicalJson::encode(['status' => 'conflict']) . "\n";
        exit(1);
    } catch (Throwable) {
        echo CanonicalJson::encode(['status' => 'uncertain']) . "\n";
        exit(2);
    }
}

if ($mode === 'inspect-contended') {
    $ids = $repository->getQuery()->accessCheck(false)
        ->condition('name', 'contended.owner', 'CASE_INSENSITIVE_EQUALS')
        ->range(0, 10)->execute();
    $accounts = [];
    foreach ($ids as $id) {
        $user = $repository->find((string) $id);
        if (!$user instanceof EntityInterface) {
            exit(65);
        }
        $identity = $internal->mailDelivery($user);
        $authorization = $internal->maintenanceAuthorization($user);
        $accounts[] = [
            'mail' => $identity->mail,
            'name' => $identity->name,
            'permissions' => $authorization->permissions,
            'roles' => $authorization->roles,
        ];
    }
    echo CanonicalJson::encode(['accounts' => $accounts, 'count' => count($accounts)]) . "\n";
    exit(0);
}

if ($mode === 'inspect') {
    $rows = [];
    foreach (['community-administrator', 'community-owner', 'community-reviewer'] as $name) {
        $ids = $repository->getQuery()->accessCheck(false)->condition('name', $name)->range(0, 1)->execute();
        $user = $ids === [] ? null : $repository->find((string) $ids[0]);
        if (!$user instanceof EntityInterface) {
            exit(65);
        }
        $authorization = $internal->maintenanceAuthorization($user);
        $rows[$name] = ['permissions' => $authorization->permissions, 'roles' => $authorization->roles];
    }
    echo CanonicalJson::encode($rows) . "\n";
    exit(0);
}

$providers = [new App\Provider\ApplicationBlueprintGovernanceServiceProvider()];
$roles = RoleRepository::fromProviders($providers);
$roles->assertPermissionsCatalogued(PermissionHandler::fromProviders($providers));
$handler = new UserProvisionRegisteredHandler(
    new ProcessEntityTypeManagerFixture($type, $repository),
    new RegisteredRoleAccountProvisioner(
        new RegisteredRoleAssignmentService($roles),
        new ProcessIdentityLookupFixture(),
        $internal,
    ),
);
$stdout = new BufferedOutput();
$stderr = new BufferedOutput();
$exit = $handler->execute(new SymfonyCommandIO(new ArrayInput([]), $stdout, $stderr));
echo $stdout->fetch();
fwrite(STDERR, $stderr->fetch());
exit($exit);

function materializeCanonicalCommunityEventsProvider(string $applicationRoot): void
{
    $repositoryRoot = dirname(__DIR__, 5);
    $manifest = new SiteManifestParser()->parse(
        (string) file_get_contents($repositoryRoot . '/packages/site-contract/resources/starters/community-events/v1.yaml'),
        'community-events@1-process-fixture',
    );
    $plan = ApplicationBlueprintCompilerFactory::create()->compile($manifest);
    $required = [
        'src/Access/ApplicationBlueprintPermissions.php',
        'src/Provider/ApplicationBlueprintGovernanceServiceProvider.php',
        'src/Workflow/EventEditorialWorkflowDefinition.php',
    ];
    foreach ($plan->artifacts as $artifact) {
        if (!in_array($artifact->path, $required, true)) {
            continue;
        }
        $path = $applicationRoot . '/' . $artifact->path;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0o700, true)) {
            throw new RuntimeException('Could not create the generated application directory.');
        }
        if (file_put_contents($path, $artifact->content) === false) {
            throw new RuntimeException('Could not write the generated application provider.');
        }
    }
    foreach ($required as $path) {
        if (!is_file($applicationRoot . '/' . $path)) {
            throw new RuntimeException('The canonical compiler omitted a required governance artifact.');
        }
    }
}

function loadCanonicalCommunityEventsProvider(string $applicationRoot): void
{
    foreach ([
        'src/Access/ApplicationBlueprintPermissions.php',
        'src/Workflow/EventEditorialWorkflowDefinition.php',
        'src/Provider/ApplicationBlueprintGovernanceServiceProvider.php',
    ] as $path) {
        $source = $applicationRoot . '/' . $path;
        if (!is_file($source)) {
            throw new RuntimeException('The generated Community Events provider is absent.');
        }
        require_once $source;
    }
}

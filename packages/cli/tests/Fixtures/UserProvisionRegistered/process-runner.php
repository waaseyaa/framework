<?php

declare(strict_types=1);

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\User\UserIdentityLookupInterface;
use Waaseyaa\CLI\Command\SymfonyCommandIO;
use Waaseyaa\CLI\Handler\UserProvisionRegisteredHandler;
use Waaseyaa\CLI\Site\Blueprint\ApplicationBlueprintCompilerFactory;
use Waaseyaa\CLI\UserProvisioning\RegisteredRoleAccountProvisioner;
use Waaseyaa\Database\DBALDatabase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Concurrency\EntityMutationAuthority;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriverV2;
use Waaseyaa\EntityStorage\Driver\StorageBoundary;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\EntityMutationAuthoritySchema;
use Waaseyaa\Field\FieldDefinitionInterface;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\SiteContract\SiteManifestParser;
use Waaseyaa\Tests\Support\UserInternalFieldReaderFixture;
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

    public function getDefinition(string $entityTypeId): EntityTypeInterface { return $this->type; }

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

    public function getDefinitions(): array { return [$this->type->id() => $this->type]; }
    public function hasDefinition(string $entityTypeId): bool { return $entityTypeId === 'user'; }

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
if (!in_array($mode, ['prepare', 'provision', 'inspect', 'seed-inactive', 'ordinary-create', 'inspect-contended'], true) || $databasePath === '') {
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

$roles = RoleRepository::fromProviders([new App\Provider\ApplicationBlueprintGovernanceServiceProvider()]);
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
    $manifest = (new SiteManifestParser())->parse(
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

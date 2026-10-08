<?php

declare(strict_types=1);
// Expected exit status 0 demonstrates source API identity classification; synthetic composition, real SQLite.
$sourceRoot = $argv[1] ?? dirname(__DIR__, 4);
require $sourceRoot . '/vendor/autoload.php';
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityReadRuntime;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Messaging\MessagingAccessPolicy;
use Waaseyaa\Messaging\MessagingServiceProvider;

$db = DBALDatabase::createSqlite();
$dispatcher = new SymfonyEventDispatcherAdapter();
$manager = new EntityTypeManager($dispatcher, null, function ($id, EntityTypeInterface $type) use ($db, $dispatcher) {
    new SqlSchemaHandler($type, $db)->ensureTable();
    return V2EntityRepositoryFactory::createFromSqlStorageDriver($type, new SqlStorageDriver(new SingleConnectionResolver($db), $type->getKeys()['id']), $dispatcher, database: $db);
});
$a = new AuthorizationPrincipal(101, true, [], [], 'verifier');
$b = new AuthorizationPrincipal(102, true, [], [], 'verifier');
$c = new AuthorizationPrincipal(103, true, [], [], 'verifier');
$context = new class ($a) implements AccountContextInterface {
    public function __construct(private ?\Waaseyaa\Access\AccountInterface $a) {} public function current(): ?\Waaseyaa\Access\AccountInterface
    {
        return $this->a;
    } public function set(?\Waaseyaa\Access\AccountInterface $a): void
    {
        $this->a = $a;
    }
};
$provider = new MessagingServiceProvider();
$provider->setKernelServices(new class ($manager, $dispatcher, $context)implements KernelServicesInterface {
    public function __construct(private $m, private $d, private $c) {}public function get(string $name): ?object
    {
        return match ($name) {
            EntityTypeManager::class => $this->m,\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $this->d,AccountContextInterface::class => $this->c,default => null
        };
    }
});
$provider->register();
foreach ($provider->getEntityTypeRegistrations() as $r) {
    $manager->registerEntityType($r['entityType']);
}$provider->boot();
$handler = new EntityAccessHandler([new MessagingAccessPolicy($manager)]);
$scope = new AccountFieldReadScope();
$guard = new FieldReadGuard($scope, $handler->checkProtectedFieldRead(...));
EntityReadRuntime::installGuard($guard);
function api($actor, $action)
{
    global $manager,$handler,$scope,$context;
    $context->set($actor);
    try {
        return $scope->run($actor, fn() => $action(new JsonApiController($manager, new ResourceSerializer($manager), $handler, $actor)));
    } catch (Throwable $e) {
        return new class ($e) {
            public int $statusCode = 599;
            public function __construct(private $e) {}public function toArray()
            {
                return ['exception' => get_class($this->e),'message' => $this->e->getMessage()];
            }
        };
    }
}
$cases = [];
foreach (['Ordinary synthetic title','1042',null] as $i => $title) {
    $attrs = ['created_by' => 101];
    if ($title !== null) {
        $attrs['title'] = $title;
    }try {
        $doc = api($a, fn($controller) => $controller->store('message_thread', ['data' => ['type' => 'message_thread','attributes' => $attrs]]));
        $cases[$i] = $doc->toArray();
    } catch (Throwable $e) {
        $cases[$i] = ['exception' => get_class($e)];
    }
}
echo json_encode($cases, JSON_PRETTY_PRINT),"\n";
if (!isset($cases[0]['exception']) || isset($cases[1]['exception']) || isset($cases[2]['exception'])) {
    exit(1);
}

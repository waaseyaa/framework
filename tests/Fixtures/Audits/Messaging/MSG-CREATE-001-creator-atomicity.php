<?php

declare(strict_types=1);
// Synthetic source composition. Expected exit: 0 for observation, not repair qualification.
$root = $argv[1] ?? dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Messaging\MessagingAccessPolicy;
use Waaseyaa\Messaging\MessagingServiceProvider;

function fixture(bool $sql): array
{
    EntityType::clearFromClassCache();
    $db = $sql ? DBALDatabase::createSqlite() : null;
    $dispatcher = new SymfonyEventDispatcherAdapter();
    $manager = new EntityTypeManager(
        $dispatcher,
        null,
        function (string $id, EntityTypeInterface $type) use ($db, $dispatcher) {
            if ($db === null) {
                return V2EntityRepositoryFactory::create($type, new InMemoryStorageDriver(), $dispatcher);
            }
            new SqlSchemaHandler($type, $db)->ensureTable();
            return V2EntityRepositoryFactory::createFromSqlStorageDriver(
                $type,
                new SqlStorageDriver(new SingleConnectionResolver($db), $type->getKeys()['id']),
                $dispatcher,
                database: $db,
            );
        },
    );
    $actor = new class implements AccountInterface {
        public function id(): int|string
        {
            return 100;
        }
        public function hasPermission(string $permission): bool
        {
            return false;
        }
        public function getRoles(): array
        {
            return [];
        }
        public function isAuthenticated(): bool
        {
            return true;
        }
    };
    $context = new class ($actor) implements AccountContextInterface {
        public function __construct(private ?AccountInterface $actor) {}
        public function current(): ?AccountInterface
        {
            return $this->actor;
        }
        public function set(?AccountInterface $account): void
        {
            $this->actor = $account;
        }
    };
    $provider = new MessagingServiceProvider();
    $provider->setKernelServices(new class ($manager, $dispatcher, $context) implements KernelServicesInterface {
        public function __construct(private object $manager, private object $dispatcher, private object $context) {}
        public function get(string $abstract): ?object
        {
            return match ($abstract) {
                EntityTypeManager::class => $this->manager,
                \Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class => $this->dispatcher,
                AccountContextInterface::class => $this->context,
                default => null,
            };
        }
    });
    $provider->register();
    foreach ($provider->getEntityTypeRegistrations() as $registration) {
        $manager->registerEntityType($registration['entityType']);
    }
    $provider->boot();
    return [$manager,$db,$actor];
}
$out = [];
foreach ([true,false] as $sql) {
    [$manager,$db,$actor] = fixture($sql);
    $threads = $manager->getRepository('message_thread');
    $participants = $manager->getRepository('thread_participant');
    $thread = $threads->create(['created_by' => 100]);
    $threads->save($thread, validate: false);
    $out[$sql ? 'sql' : 'memory'] = ['participants' => count($participants->findBy(['thread_id' => (int) $thread->id()])),
        'creator_view' => new MessagingAccessPolicy($manager)->access($thread, 'view', $actor)->isAllowed()];
    if (!$sql) {
        $participant = $participants->create(['thread_id' => (int) $thread->id(),'user_id' => 100,'thread_creator_id' => 100]);
        $participants->save($participant, validate: false);
        $out['memory']['view_after_manual_seed'] = new MessagingAccessPolicy($manager)->access($thread, 'view', $actor)->isAllowed();
    } else {
        $db->query("CREATE TRIGGER fail_membership BEFORE INSERT ON thread_participant BEGIN SELECT RAISE(ABORT, 'injected participant failure'); END");
        $thread2 = $threads->create(['created_by' => 100]);
        try {
            $threads->save($thread2, validate: false);
            $out['sql']['failure_propagated'] = false;
        } catch (Throwable $e) {
            $out['sql']['failure_propagated'] = true;
        }
        $out['sql']['failed_creation_thread_rows'] = count($threads->findBy(['mtid' => $thread2->id()]));
        $out['sql']['failed_creation_participant_rows'] = count($participants->findBy(['thread_id' => (int) $thread2->id()]));
    }
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),"\n";

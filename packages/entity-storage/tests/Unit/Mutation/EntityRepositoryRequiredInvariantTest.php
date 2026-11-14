<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Mutation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\Context\RequestAccountContext;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriverV2Interface;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Event\BeforeSaveEvent;
use Waaseyaa\EntityStorage\Mutation\EntityMutationInvariantContext;
use Waaseyaa\EntityStorage\Mutation\EntityMutationInvariantInterface;
use Waaseyaa\EntityStorage\Mutation\EntityMutationOperation;
use Waaseyaa\EntityStorage\Mutation\MutationInvariantRegistry;
use Waaseyaa\EntityStorage\Revision\RevisionPruningPolicy;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

final class EntityRepositoryRequiredInvariantTest extends TestCase
{
    private const string GUARD_ID = 'test.guard';

    /** @return iterable<string, array{string}> */
    public static function storageBackends(): iterable
    {
        yield 'sql-blob' => ['sql-blob'];
        yield 'sql-column' => ['sql-column'];
    }

    #[Test]
    public function required_entity_type_refuses_repository_construction_without_registry(): void
    {
        $database = DBALDatabase::createSqlite();
        $type = $this->type('sql-blob');

        $this->expectException(\LogicException::class);
        $this->repository($type, $database, null);
    }

    #[Test]
    public function required_entity_type_refuses_repository_construction_without_registered_guard(): void
    {
        $database = DBALDatabase::createSqlite();
        $type = $this->type('sql-blob');
        $registry = new MutationInvariantRegistry();

        $this->expectException(\OutOfBoundsException::class);
        $this->repository($type, $database, $registry);
    }

    #[Test]
    public function required_entity_type_refuses_repository_construction_without_database(): void
    {
        $registry = $this->registry(static function (): void {});

        $this->expectException(\LogicException::class);
        V2EntityRepositoryFactory::create(
            $this->type('sql-blob'),
            new \Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver(),
            new EventDispatcher(),
            mutationInvariants: $registry,
        );
    }

    #[Test]
    public function entity_type_without_required_invariant_keeps_existing_save_behavior(): void
    {
        $database = DBALDatabase::createSqlite();
        $type = new EntityType(
            id: 'ordinary_entity',
            label: 'Ordinary entity',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label'],
        );
        (new SqlSchemaHandler($type, $database))->ensureTable();
        $repository = $this->repository($type, $database, null);
        $entity = $repository->create(['id' => '101', 'uuid' => 'uuid-ordinary-1', 'label' => 'saved']);

        $repository->save($entity);

        self::assertSame('saved', $repository->find('101')?->get('label'));
    }

    #[Test]
    #[DataProvider('storageBackends')]
    public function guard_receives_complete_context_and_denial_rolls_back_create_update_and_delete(string $backend): void
    {
        $database = DBALDatabase::createSqlite();
        $type = $this->type($backend);
        (new SqlSchemaHandler($type, $database))->ensureTable();
        $contexts = [];
        $denyDelete = false;
        $registry = $this->registry(static function (EntityMutationInvariantContext $context) use (&$contexts, &$denyDelete): void {
            $contexts[] = $context;
            if ($context->proposed?->get('label') === 'deny'
                || ($denyDelete && $context->operation === EntityMutationOperation::Delete)) {
                throw new \DomainException('guard denied');
            }
        });
        $repository = $this->repository($type, $database, $registry);
        $principal = new AuthorizationPrincipal(17, true, ['member'], [], 'claims-v1');
        $accountContext = new RequestAccountContext();
        $repository->setAccountContext($accountContext);

        $new = $repository->create(['id' => '1', 'uuid' => 'uuid-1', 'label' => 'allow']);
        $repository->save($new);
        self::assertSame(EntityMutationOperation::Create, $contexts[0]->operation);
        self::assertNull($contexts[0]->original);
        self::assertSame($new, $contexts[0]->proposed);
        self::assertNull($contexts[0]->principal);
        self::assertSame($database, $contexts[0]->database);
        self::assertNotSame('', $contexts[0]->transactionIdentity);

        $accountContext->set($principal);
        $loaded = $repository->find('1');
        self::assertInstanceOf(TestStorageEntity::class, $loaded);
        $loaded->set('label', 'deny');
        try {
            $repository->save($loaded, validate: false);
            self::fail('The required invariant accepted a denied update.');
        } catch (\DomainException $exception) {
            self::assertSame('guard denied', $exception->getMessage());
        }
        self::assertSame('allow', $repository->find('1')?->get('label'));
        self::assertSame(EntityMutationOperation::Update, $contexts[array_key_last($contexts)]->operation);
        self::assertSame($principal, $contexts[array_key_last($contexts)]->principal);

        $deniedCreate = $repository->create(['id' => '2', 'uuid' => 'uuid-2', 'label' => 'deny']);
        try {
            $repository->save($deniedCreate, validate: false);
            self::fail('The required invariant accepted a denied create.');
        } catch (\DomainException) {
            self::assertNull($repository->find('2'));
        }

        $toDelete = $repository->find('1');
        self::assertInstanceOf(TestStorageEntity::class, $toDelete);
        $denyDelete = true;
        try {
            $repository->delete($toDelete);
            self::fail('The required invariant accepted a denied delete.');
        } catch (\DomainException) {
            self::assertSame('allow', $repository->find('1')?->get('label'));
        }
        self::assertSame(EntityMutationOperation::Delete, $contexts[array_key_last($contexts)]->operation);
    }

    #[Test]
    public function final_guard_catches_pre_save_listener_mutation(): void
    {
        $database = DBALDatabase::createSqlite();
        $type = $this->type('sql-blob');
        (new SqlSchemaHandler($type, $database))->ensureTable();
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(BeforeSaveEvent::class, static function (BeforeSaveEvent $event): void {
            $event->entity()->set('label', 'listener-changed');
        });
        $repository = $this->repository($type, $database, $this->registry(static function (EntityMutationInvariantContext $context): void {
            if ($context->proposed?->get('label') === 'listener-changed') {
                throw new \DomainException('final guard denied');
            }
        }), $dispatcher);
        $entity = $repository->create(['id' => '1', 'uuid' => 'uuid-1', 'label' => 'before']);

        try {
            $repository->save($entity);
            self::fail('The final required guard did not observe the listener mutation.');
        } catch (\DomainException $exception) {
            self::assertSame('final guard denied', $exception->getMessage());
        }
        self::assertNull($repository->find('1'));
        self::assertSame([], $this->authority($database));
    }

    #[Test]
    public function aggregate_callback_mutation_is_checked_and_refusal_rolls_back(): void
    {
        $database = DBALDatabase::createSqlite();
        $type = $this->type('sql-blob');
        (new SqlSchemaHandler($type, $database))->ensureTable();
        $repository = $this->repository($type, $database, $this->registry(static function (EntityMutationInvariantContext $context): void {
            if ($context->proposed?->get('label') === 'aggregate-denied') {
                throw new \DomainException('aggregate denied');
            }
        }));
        $entity = $repository->create(['id' => '1', 'uuid' => 'uuid-1', 'label' => 'seed']);
        $repository->save($entity);
        $entity = $repository->find('1');
        self::assertInstanceOf(TestStorageEntity::class, $entity);

        try {
            $repository->saveAggregateMutation($entity, static function (TestStorageEntity $entity): void {
                $entity->set('label', 'aggregate-denied');
            });
            self::fail('The required invariant accepted the aggregate callback mutation.');
        } catch (\DomainException $exception) {
            self::assertSame('aggregate denied', $exception->getMessage());
        }
        self::assertSame('seed', $repository->find('1')?->get('label'));
    }

    #[Test]
    public function publish_revision_aggregate_is_refused_before_callback_or_invariant_hooks(): void
    {
        $database = DBALDatabase::createSqlite();
        $type = $this->type('sql-blob');
        (new SqlSchemaHandler($type, $database))->ensureTable();
        $guardCalls = 0;
        $revisionDriver = $this->createMock(RevisionableStorageDriverV2Interface::class);
        $revisionDriver->expects(self::never())->method('assertEntityMutationAllowed');
        $revisionDriver->expects(self::never())->method('writeRevision');
        $repository = $this->repository(
            $type,
            $database,
            $this->registry(static function () use (&$guardCalls): void {
                ++$guardCalls;
            }),
            revisionDriver: $revisionDriver,
        );
        $entity = $repository->create(['id' => '1', 'uuid' => 'uuid-1', 'label' => 'seed']);
        $repository->save($entity);
        $entity = $repository->find('1');
        self::assertInstanceOf(TestStorageEntity::class, $entity);
        $guardCallsBefore = $guardCalls;
        $mutationTokenBefore = $entity->mutationToken()?->toOpaqueString();
        $authorityBefore = $this->authority($database);
        $callbackEffects = [];

        try {
            $repository->saveAggregateMutation(
                $entity,
                static function () use (&$callbackEffects): void {
                    $callbackEffects[] = 'called';
                },
                publishRevision: true,
            );
            self::fail('Required-invariant nonrevisionable entity accepted aggregate publication.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('Required-invariant entities reject', $exception->getMessage());
        }

        self::assertSame([], $callbackEffects);
        self::assertSame($guardCallsBefore, $guardCalls);
        self::assertSame('seed', $repository->find('1')?->get('label'));
        self::assertSame($mutationTokenBefore, $entity->mutationToken()?->toOpaqueString());
        self::assertSame($authorityBefore, $this->authority($database));
    }

    #[Test]
    public function required_invariant_acquires_file_database_writer_before_guard_and_releases_after_save(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'waaseyaa-required-invariant-');
        self::assertIsString($path);
        $database = null;
        $competitor = null;

        try {
            $database = DBALDatabase::createSqlite($path);
            $type = $this->type('sql-blob');
            $guardCalls = 0;
            $blockedAttempts = 0;
            $repository = $this->repository(
                $type,
                $database,
                $this->registry(static function (EntityMutationInvariantContext $context) use (
                    &$guardCalls,
                    &$blockedAttempts,
                    &$competitor,
                ): void {
                    ++$guardCalls;
                    self::assertInstanceOf(DBALDatabase::class, $context->database);
                    self::assertInstanceOf(DBALDatabase::class, $competitor);
                    self::assertSame(0, (int) $competitor->getConnection()->fetchOne(
                        "SELECT COUNT(*) FROM invariant_entity WHERE id = '201'",
                    ), 'The guard must run before committed content persistence.');
                    try {
                        $competitor->getConnection()->executeStatement(
                            'INSERT INTO invariant_lock_probe (id) VALUES (?)',
                            [$guardCalls],
                        );
                        self::fail('The competitor acquired a writer lock while the required guard was running.');
                    } catch (\Doctrine\DBAL\Exception $exception) {
                        self::assertStringContainsString('database is locked', strtolower($exception->getMessage()));
                        ++$blockedAttempts;
                    }
                }),
            );
            $competitor = DBALDatabase::createSqlite($path);
            $competitor->getConnection()->executeStatement('PRAGMA busy_timeout = 0');
            $database->getConnection()->executeStatement(
                'CREATE TABLE invariant_lock_probe (id INTEGER PRIMARY KEY)',
            );
            $entity = $repository->create(['id' => '201', 'uuid' => 'uuid-201', 'label' => 'locked']);

            $repository->save($entity);

            self::assertSame(2, $guardCalls, 'Create is checked before and after lifecycle hooks.');
            self::assertSame($guardCalls, $blockedAttempts);
            $competitor->getConnection()->executeStatement(
                'INSERT INTO invariant_lock_probe (id) VALUES (?)',
                [99],
            );
            self::assertSame(1, (int) $competitor->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM invariant_lock_probe',
            ));
        } finally {
            $competitor?->getConnection()->close();
            $database?->getConnection()->close();
            foreach ([$path, $path . '-wal', $path . '-shm'] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
        }
    }

    #[Test]
    public function second_batch_refusal_rolls_back_first_write_and_authority_and_next_save_succeeds(): void
    {
        $database = DBALDatabase::createSqlite();
        $type = $this->type('sql-blob');
        (new SqlSchemaHandler($type, $database))->ensureTable();
        $refuseSecond = true;
        $registry = $this->registry(static function (EntityMutationInvariantContext $context) use (&$refuseSecond): void {
            if ($refuseSecond && $context->proposed?->get('label') === 'deny-second') {
                throw new \DomainException('second refused');
            }
        });
        $repository = $this->repository($type, $database, $registry);
        $first = $repository->create(['id' => '1', 'uuid' => 'uuid-1', 'label' => 'one']);
        $second = $repository->create(['id' => '2', 'uuid' => 'uuid-2', 'label' => 'two']);
        $repository->saveMany([$first, $second]);
        $first = $repository->find('1');
        $second = $repository->find('2');
        self::assertInstanceOf(TestStorageEntity::class, $first);
        self::assertInstanceOf(TestStorageEntity::class, $second);
        $first->set('label', 'first-new');
        $second->set('label', 'deny-second');
        $authorityBefore = $this->authority($database);

        try {
            $repository->saveMany([$first, $second], validate: false);
            self::fail('The second batch item was accepted.');
        } catch (\DomainException $exception) {
            self::assertSame('second refused', $exception->getMessage());
        }
        self::assertSame('one', $repository->find('1')?->get('label'));
        self::assertSame('two', $repository->find('2')?->get('label'));
        self::assertSame($authorityBefore, $this->authority($database));

        $refuseSecond = false;
        $second->set('label', 'second-authorized');
        $repository->save($second);
        self::assertSame('second-authorized', $repository->find('2')?->get('label'));
    }

    #[Test]
    public function invariant_metadata_on_revision_translation_publication_and_maintenance_operations_refuses(): void
    {
        // The first slice deliberately supports only ordinary non-revisionable,
        // untranslated entity mutations. These operations must fail before writes.
        $database = DBALDatabase::createSqlite();
        $type = $this->type('sql-blob');
        (new SqlSchemaHandler($type, $database))->ensureTable();
        $repository = $this->repository($type, $database, $this->registry(static function (): void {}));
        $entity = $repository->create(['id' => '1', 'uuid' => 'uuid-1', 'label' => 'seed']);
        $repository->save($entity);

        $operations = [
            'revision restore' => static fn() => $repository->rollback('1', 1),
            'translation save' => static fn() => $repository->saveTranslation('1', 'fr', ['label' => 'translated']),
            'publication' => static fn() => $repository->setPublishedRevision('1', 1),
            'maintenance' => static fn() => $repository->pruneRevisions('1', RevisionPruningPolicy::default()),
        ];
        foreach ($operations as $name => $operation) {
            try {
                $operation();
                self::fail("Required-invariant entity accepted {$name}.");
            } catch (\LogicException $exception) {
                self::assertStringContainsString('Required-invariant entities reject', $exception->getMessage(), $name);
            }
        }
        self::assertSame('seed', $repository->find('1')?->get('label'));
        self::assertSame([], $this->revisionRows($database));
    }

    private function type(string $backend): EntityType
    {
        return new EntityType(
            id: 'invariant_entity',
            label: 'Invariant entity',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label'],
            primaryStorageBackend: $backend,
            requiredMutationInvariant: self::GUARD_ID,
        );
    }

    private function registry(\Closure $assertion): MutationInvariantRegistry
    {
        $invariant = new class ($assertion) implements EntityMutationInvariantInterface {
            public function __construct(private readonly \Closure $assertion) {}

            public function assertMutation(EntityMutationInvariantContext $context): void
            {
                ($this->assertion)($context);
            }
        };
        $registry = new MutationInvariantRegistry();
        $registry->register(self::GUARD_ID, $invariant);

        return $registry;
    }

    private function repository(
        EntityType $type,
        ?DBALDatabase $database,
        ?MutationInvariantRegistry $registry,
        ?EventDispatcher $dispatcher = null,
        RevisionableStorageDriverV2Interface|\Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver|null $revisionDriver = null,
    ): EntityRepository {
        if ($database !== null) {
            (new SqlSchemaHandler($type, $database))->ensureTable();
        }

        return V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $type,
            new SqlStorageDriver(new SingleConnectionResolver($database ?? DBALDatabase::createSqlite())),
            $dispatcher ?? new EventDispatcher(),
            revisionDriver: $revisionDriver,
            database: $database,
            mutationInvariants: $registry,
        );
    }

    /** @return list<array<string, mixed>> */
    private function authority(DBALDatabase $database): array
    {
        return $database->getConnection()->fetchAllAssociative(
            'SELECT * FROM waaseyaa_entity_mutation_authority ORDER BY entity_id',
        );
    }

    /** @return list<array<string, mixed>> */
    private function revisionRows(DBALDatabase $database): array
    {
        return $database->getConnection()->fetchAllAssociative(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE '%revision%' ORDER BY name",
        );
    }
}

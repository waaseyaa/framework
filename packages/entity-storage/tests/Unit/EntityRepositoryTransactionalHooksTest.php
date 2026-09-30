<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Event\EntitySourceChangedEvent;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\EntityStorage\Tests\Fixtures\TransactionalHookEntity;

#[CoversClass(EntityRepository::class)]
final class EntityRepositoryTransactionalHooksTest extends TestCase
{
    private DBALDatabase $database;
    private EntityRepository $repository;
    /** @var list<string> */
    private array $notifications = [];

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $dispatcher = new EventDispatcher();
        $type = new EntityType(id: 'test_entity', label: 'Hooks', class: TransactionalHookEntity::class, keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label']);
        new SqlSchemaHandler($type, $this->database)->ensureTable();
        $this->repository = V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $type,
            new SqlStorageDriver(new SingleConnectionResolver($this->database)),
            $dispatcher,
            database: $this->database,
        );
        $connection = $this->database->getConnection();
        $connection->executeStatement('CREATE TABLE hook_related (entity_id VARCHAR(191), operation VARCHAR(16))');
        $connection->executeStatement('CREATE TABLE source_projection (entity_id VARCHAR(191) PRIMARY KEY)');
        $dispatcher->addListener(EntitySourceChangedEvent::class, function (EntitySourceChangedEvent $event): void {
            self::assertTrue($this->database->getConnection()->isTransactionActive());
            // A projection invalidation on the same authority, like ai-vector.
            $this->database->getConnection()->executeStatement('DELETE FROM source_projection WHERE entity_id = ?', [$event->entityId]);
        });
        foreach ([EntityEvents::POST_SAVE->value, EntityEvents::POST_DELETE->value] as $name) {
            $dispatcher->addListener($name, function () use ($name): void {
                self::assertFalse($this->database->getConnection()->isTransactionActive(), 'Notification must wait for true commit.');
                $this->notifications[] = $name;
            });
        }
    }

    private function entity(string $id, bool $refuse = false): TransactionalHookEntity
    {
        $entity = new TransactionalHookEntity(['id' => $id, 'label' => 'source'], 'test_entity', ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label']);
        $entity->enforceIsNew();
        $entity->onPostHook = function (string $operation) use ($id, $refuse): void {
            self::assertTrue($this->database->getConnection()->isTransactionActive(), 'Host hook must execute inside the mutation transaction.');
            $this->database->getConnection()->executeStatement('INSERT INTO hook_related VALUES (?, ?)', [$id, $operation]);
            if ($refuse) {
                throw new \RuntimeException('host hook refused');
            }
        };

        return $entity;
    }

    /** @return list<array<string, mixed>> */
    private function authority(): array
    {
        return $this->database->getConnection()->fetchAllAssociative('SELECT * FROM waaseyaa_entity_mutation_authority ORDER BY entity_id');
    }

    private function rows(string $table): int
    {
        return (int) $this->database->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    private function projection(string $id): void
    {
        $this->database->getConnection()->executeStatement('INSERT INTO source_projection VALUES (?)', [$id]);
    }

    private function refuse(\Closure $mutation): void
    {
        try {
            $mutation();
            self::fail('Throwing host hook must abort the mutation.');
        } catch (\RuntimeException $error) {
            self::assertSame('host hook refused', $error->getMessage());
        }
    }

    #[Test]
    public function throwing_save_hook_rolls_back_source_projection_authority_and_related_writes(): void
    {
        $entity = $this->entity('1', refuse: true);
        $this->projection('1');
        $this->refuse(fn() => $this->repository->save($entity));
        self::assertSame(['save'], $entity->hookCalls);
        self::assertSame(0, $this->rows('test_entity'));
        self::assertSame(0, $this->rows('hook_related'));
        self::assertSame(1, $this->rows('source_projection'));
        self::assertSame([], $this->authority());
        self::assertSame([], $this->notifications);
    }

    #[Test]
    public function throwing_update_hook_preserves_previous_source_and_mutation_token(): void
    {
        $this->repository->save($this->entity('1'));
        $entity = $this->repository->find('1');
        self::assertInstanceOf(TransactionalHookEntity::class, $entity);
        $token = $entity->mutationToken();
        $authority = $this->authority();
        $entity->set('label', 'changed');
        $entity->onPostHook = function (): never {
            $this->database->getConnection()->executeStatement("INSERT INTO hook_related VALUES ('1', 'update')");
            throw new \RuntimeException('host hook refused');
        };
        $this->notifications = [];
        $this->refuse(fn() => $this->repository->save($entity));
        self::assertSame('source', $this->repository->find('1')?->label());
        self::assertSame($authority, $this->authority());
        self::assertSame($token, $entity->mutationToken());
        self::assertSame(1, $this->rows('hook_related'));
        self::assertSame([], $this->notifications);
    }

    #[Test]
    public function throwing_delete_hook_rolls_back_tombstone_source_and_projection(): void
    {
        $this->repository->save($this->entity('1'));
        $entity = $this->repository->find('1');
        self::assertInstanceOf(TransactionalHookEntity::class, $entity);
        $entity->onPostHook = function (string $operation): never {
            self::assertTrue($this->database->getConnection()->isTransactionActive());
            $this->database->getConnection()->executeStatement('INSERT INTO hook_related VALUES (?, ?)', ['1', $operation]);
            throw new \RuntimeException('host hook refused');
        };
        $this->projection('1');
        $authority = $this->authority();
        $this->notifications = [];
        $this->refuse(fn() => $this->repository->delete($entity));
        self::assertSame(['delete'], $entity->hookCalls);
        self::assertSame(1, $this->rows('test_entity'));
        self::assertSame(1, $this->rows('hook_related'));
        self::assertSame(1, $this->rows('source_projection'));
        self::assertSame($authority, $this->authority());
        self::assertSame([], $this->notifications);
    }

    #[Test]
    public function save_batch_rollback_retains_in_memory_hook_calls_but_undoes_all_database_effects(): void
    {
        $first = $this->entity('1');
        $second = $this->entity('2', refuse: true);
        $this->projection('1');
        $this->projection('2');
        $this->refuse(fn() => $this->repository->saveMany([$first, $second]));
        self::assertSame(['save'], $first->hookCalls);
        self::assertSame(['save'], $second->hookCalls);
        self::assertSame(0, $this->rows('test_entity'));
        self::assertSame(0, $this->rows('hook_related'));
        self::assertSame(2, $this->rows('source_projection'));
        self::assertSame([], $this->authority());
        self::assertSame([], $this->notifications);
    }

    #[Test]
    public function delete_batch_rollback_retains_earlier_hook_calls_and_restores_authority(): void
    {
        $this->repository->saveMany([$this->entity('1'), $this->entity('2')]);
        $first = $this->repository->find('1');
        $second = $this->repository->find('2');
        self::assertInstanceOf(TransactionalHookEntity::class, $first);
        self::assertInstanceOf(TransactionalHookEntity::class, $second);
        foreach ([$first, $second] as $entity) {
            $entity->onPostHook = function (string $operation) use ($entity): void {
                $this->database->getConnection()->executeStatement('INSERT INTO hook_related VALUES (?, ?)', [(string) $entity->id(), $operation]);
                if ((string) $entity->id() === '2') {
                    throw new \RuntimeException('host hook refused');
                }
            };
            $this->projection((string) $entity->id());
        }
        $authority = $this->authority();
        $this->notifications = [];
        $this->refuse(fn() => $this->repository->deleteMany([$first, $second]));
        self::assertSame(['delete'], $first->hookCalls);
        self::assertSame(['delete'], $second->hookCalls);
        self::assertSame(2, $this->rows('test_entity'));
        self::assertSame(2, $this->rows('hook_related'));
        self::assertSame(2, $this->rows('source_projection'));
        self::assertSame($authority, $this->authority());
        self::assertSame([], $this->notifications);
    }

    /** @return iterable<string, array{bool}> */
    public static function enclosingOutcomes(): iterable
    {
        yield 'commit' => [true];
        yield 'rollback' => [false];
    }

    #[Test]
    #[DataProvider('enclosingOutcomes')]
    public function enclosing_save_transaction_contains_hooks_and_defers_notifications(bool $commit): void
    {
        $this->projection('1');
        $outer = $this->database->transaction();
        $entity = $this->entity('1');
        $this->repository->save($entity);
        self::assertSame(['save'], $entity->hookCalls, 'Hook must already have run before outer completion.');
        self::assertSame(1, $this->rows('hook_related'));
        self::assertSame([], $this->notifications);
        if ($commit) {
            $outer->commit();
        } else {
            $outer->rollBack();
        }
        self::assertSame($commit ? 1 : 0, $this->rows('test_entity'));
        self::assertSame($commit ? 1 : 0, $this->rows('hook_related'));
        self::assertSame($commit ? 0 : 1, $this->rows('source_projection'));
        self::assertCount($commit ? 1 : 0, $this->authority());
        self::assertSame($commit ? [EntityEvents::POST_SAVE->value] : [], $this->notifications);
    }

    #[Test]
    #[DataProvider('enclosingOutcomes')]
    public function enclosing_delete_transaction_contains_hooks_and_defers_notifications(bool $commit): void
    {
        $this->repository->save($this->entity('1'));
        $entity = $this->repository->find('1');
        self::assertInstanceOf(TransactionalHookEntity::class, $entity);
        $entity->onPostHook = function (string $operation): void {
            self::assertTrue($this->database->getConnection()->isTransactionActive());
            $this->database->getConnection()->executeStatement('INSERT INTO hook_related VALUES (?, ?)', ['1', $operation]);
        };
        $this->projection('1');
        $authority = $this->authority();
        $this->notifications = [];
        $outer = $this->database->transaction();
        $this->repository->delete($entity);
        self::assertSame(['delete'], $entity->hookCalls);
        self::assertSame(2, $this->rows('hook_related'));
        self::assertSame([], $this->notifications);
        if ($commit) {
            $outer->commit();
        } else {
            $outer->rollBack();
        }
        self::assertSame($commit ? 0 : 1, $this->rows('test_entity'));
        self::assertSame($commit ? 2 : 1, $this->rows('hook_related'));
        self::assertSame($commit ? 0 : 1, $this->rows('source_projection'));
        if (!$commit) {
            self::assertSame($authority, $this->authority());
        } else {
            self::assertSame('tombstone', $this->authority()[0]['lifecycle_state']);
        }
        self::assertSame($commit ? [EntityEvents::POST_DELETE->value] : [], $this->notifications);
    }
}

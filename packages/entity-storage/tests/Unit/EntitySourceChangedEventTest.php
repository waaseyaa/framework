<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

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
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

final class EntitySourceChangedEventTest extends TestCase
{
    private DBALDatabase $database;
    private EventDispatcher $dispatcher;
    private EntityRepository $repository;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->dispatcher = new EventDispatcher();
        $type = new EntityType(
            id: 'test_entity',
            label: 'Source',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label'],
        );
        new SqlSchemaHandler($type, $this->database)->ensureTable();
        $this->repository = V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $type,
            new SqlStorageDriver(new SingleConnectionResolver($this->database)),
            $this->dispatcher,
            database: $this->database,
        );
    }

    private function entity(?string $id = null): TestStorageEntity
    {
        $entity = new TestStorageEntity(['id' => $id, 'label' => 'source'], 'test_entity', ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label']);
        $entity->enforceIsNew();
        return $entity;
    }

    private function countRows(): int
    {
        return (int) $this->database->getConnection()->fetchOne('SELECT COUNT(*) FROM test_entity');
    }

    #[Test]
    public function source_notification_has_assigned_identity_and_written_source_before_commit(): void
    {
        $observed = [];
        $this->dispatcher->addListener(EntitySourceChangedEvent::class, function (EntitySourceChangedEvent $event) use (&$observed): void {
            self::assertSame($this->database, $event->database);
            $observed[] = [$event->entityTypeId, $event->entityId, $this->countRows(), $this->database->getConnection()->isTransactionActive()];
        });
        $this->dispatcher->addListener(EntityEvents::POST_SAVE->value, function () use (&$observed): void {
            $observed[] = ['post', $this->database->getConnection()->isTransactionActive()];
        });
        $entity = $this->entity();
        $this->repository->save($entity);
        self::assertSame([['test_entity', (string) $entity->id(), 1, true], ['post', false]], $observed);
    }

    #[Test]
    public function source_subscriber_refusal_rolls_back_create_and_subscriber_writes(): void
    {
        $post = [];
        $this->dispatcher->addListener(EntityEvents::POST_SAVE->value, static function () use (&$post): void {
            $post[] = true;
        });
        $this->dispatcher->addListener(EntitySourceChangedEvent::class, function (EntitySourceChangedEvent $event): never {
            $event->database->query('INSERT INTO test_entity (id, label) VALUES (?, ?)', [99, 'projection']);
            throw new \RuntimeException('projection refused');
        });
        try {
            $this->repository->save($this->entity('1'));
            self::fail('Source subscriber must abort.');
        } catch (\RuntimeException $error) {
            self::assertSame('projection refused', $error->getMessage());
        }
        self::assertSame(0, $this->countRows());
        self::assertSame([], $post);
    }

    #[Test]
    public function source_subscriber_refusal_rolls_back_delete(): void
    {
        $entity = $this->entity('1');
        $this->repository->save($entity);
        $post = [];
        $this->dispatcher->addListener(EntityEvents::POST_DELETE->value, static function () use (&$post): void {
            $post[] = true;
        });
        $this->dispatcher->addListener(EntitySourceChangedEvent::class, function (EntitySourceChangedEvent $event): never {
            self::assertSame(0, $this->countRows());
            self::assertTrue($this->database->getConnection()->isTransactionActive());
            throw new \RuntimeException('delete refused');
        });
        try {
            $this->repository->delete($entity);
            self::fail('Source subscriber must abort.');
        } catch (\RuntimeException $error) {
            self::assertSame('delete refused', $error->getMessage());
        }
        self::assertSame(1, $this->countRows());
        self::assertSame([], $post);
    }

    #[Test]
    public function nested_save_and_delete_notifications_wait_for_outer_commit_or_disappear_on_rollback(): void
    {
        $posts = [];
        foreach ([EntityEvents::POST_SAVE->value, EntityEvents::POST_DELETE->value] as $name) {
            $this->dispatcher->addListener($name, function () use (&$posts, $name): void {
                self::assertFalse($this->database->getConnection()->isTransactionActive());
                $posts[] = $name;
            });
        }
        $outer = $this->database->transaction();
        $entity = $this->entity('1');
        $this->repository->save($entity);
        self::assertSame([], $posts);
        $outer->rollBack();
        self::assertSame(0, $this->countRows());
        self::assertSame([], $posts);
        $this->repository->save($this->entity('1'));
        $posts = [];
        $outer = $this->database->transaction();
        $this->repository->delete($this->repository->find('1'));
        self::assertSame([], $posts);
        $outer->rollBack();
        self::assertSame(1, $this->countRows());
        self::assertSame([], $posts);
        $outer = $this->database->transaction();
        $this->repository->delete($this->repository->find('1'));
        self::assertSame([], $posts);
        $outer->commit();
        self::assertSame([EntityEvents::POST_DELETE->value], $posts);
    }

    #[Test]
    public function initial_revision_backfill_notifies_after_the_base_pointer_is_written(): void
    {
        $this->repository->save($this->entity('1'));
        $type = new EntityType(
            id: 'test_entity',
            label: 'Source',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label', 'revision' => 'revision_id'],
            revisionable: true,
        );
        $schema = new SqlSchemaHandler($type, $this->database);
        $schema->ensureTable();
        $schema->ensureRevisionTable();
        $resolver = new SingleConnectionResolver($this->database);
        $repository = V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $type,
            new SqlStorageDriver($resolver),
            $this->dispatcher,
            new \Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver($resolver, $type),
            $this->database,
        );
        $seen = [];
        $this->dispatcher->addListener(EntitySourceChangedEvent::class, function (EntitySourceChangedEvent $event) use (&$seen): void {
            self::assertTrue($this->database->getConnection()->isTransactionActive());
            $seen[] = [$event->entityId, (int) (json_decode((string) $this->database->getConnection()->fetchOne('SELECT _data FROM test_entity WHERE id = 1'), true, flags: JSON_THROW_ON_ERROR)['revision_id'] ?? 0)];
        });
        self::assertSame(1, $repository->backfillInitialRevisions());
        self::assertSame([['1', 1]], $seen);
        self::assertSame(0, $repository->backfillInitialRevisions());
        self::assertSame([['1', 1]], $seen);
    }

    #[Test]
    public function batch_refusal_rolls_back_all_source_writes_and_notifications(): void
    {
        $type = new EntityType(
            id: 'test_entity',
            label: 'Source',
            class: TestStorageEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'label'],
        );
        $repository = V2EntityRepositoryFactory::createFromSqlStorageDriver(
            $type,
            new SqlStorageDriver(new SingleConnectionResolver($this->database)),
            $this->dispatcher,
            database: $this->database,
        );
        $posts = [];
        $this->dispatcher->addListener(EntityEvents::POST_SAVE->value, static function () use (&$posts): void {
            $posts[] = true;
        });
        $refuse = static function (EntitySourceChangedEvent $event): void {
            if ($event->entityId === '2') {
                throw new \RuntimeException('batch refused');
            }
        };
        $this->dispatcher->addListener(EntitySourceChangedEvent::class, $refuse);
        try {
            $repository->saveMany([$this->entity('1'), $this->entity('2')]);
            self::fail('Batch must abort.');
        } catch (\RuntimeException $error) {
            self::assertSame('batch refused', $error->getMessage());
        }
        self::assertSame(0, $this->countRows());
        self::assertSame([], $posts);
        $this->dispatcher->removeListener(EntitySourceChangedEvent::class, $refuse);
        $repository->saveMany([$this->entity('1'), $this->entity('2')]);
        $posts = [];
        $this->dispatcher->addListener(EntityEvents::POST_DELETE->value, static function () use (&$posts): void {
            $posts[] = true;
        });
        $this->dispatcher->addListener(EntitySourceChangedEvent::class, $refuse);
        try {
            $repository->deleteMany([$repository->find('1'), $repository->find('2')]);
            self::fail('Batch must abort.');
        } catch (\RuntimeException $error) {
            self::assertSame('batch refused', $error->getMessage());
        }
        self::assertSame(2, $this->countRows());
        self::assertSame([], $posts);
    }
}

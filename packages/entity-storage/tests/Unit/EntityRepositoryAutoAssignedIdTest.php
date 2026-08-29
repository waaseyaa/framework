<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityConstants;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Testing\V2EntityRepositoryFactory;
use Waaseyaa\EntityStorage\Tests\Fixtures\TestStorageEntity;

/**
 * Regression cover for #2646: an auto-assigned id must survive a re-read.
 *
 * Exercises the composition the issue names -- a plain {@see InMemoryStorageDriver}
 * handed to {@see V2EntityRepositoryFactory::create()}, never a hand-wrapped
 * InMemoryStorageDriverV2.
 */
#[CoversClass(InMemoryStorageDriver::class)]
#[CoversClass(EntityRepository::class)]
final class EntityRepositoryAutoAssignedIdTest extends TestCase
{
    /** @return array<string, string> */
    private function keys(string $idKey): array
    {
        return [
            'id' => $idKey,
            'uuid' => 'uuid',
            'bundle' => 'bundle',
            'label' => 'label',
            'langcode' => 'langcode',
        ];
    }

    /** @param array<string, string> $keys */
    private function repository(InMemoryStorageDriver $driver, array $keys): EntityRepository
    {
        return V2EntityRepositoryFactory::create(
            new EntityType(
                id: 'test_entity',
                label: 'Test Entity',
                class: TestStorageEntity::class,
                keys: $keys,
            ),
            $driver,
            new EventDispatcher(),
        );
    }

    /** @param array<string, string> $keys */
    private function entity(array $keys, ?string $id = null): TestStorageEntity
    {
        $values = ['uuid' => 'u', 'label' => 'Tansi', 'bundle' => 'article', 'langcode' => 'en'];
        if ($id !== null) {
            $values[$keys['id']] = $id;
        }

        return new TestStorageEntity($values, 'test_entity', $keys);
    }

    #[Test]
    public function anAutoAssignedIdSurvivesHydrationAndTheNextSaveUpdatesInPlace(): void
    {
        $keys = $this->keys('id');
        $driver = new InMemoryStorageDriver();
        $repository = $this->repository($driver, $keys);
        $entity = $this->entity($keys);

        self::assertSame(EntityConstants::SAVED_NEW, $repository->save($entity));
        $assignedId = $entity->id();
        self::assertSame(1, $assignedId);

        $row = $driver->read('test_entity', '1');
        self::assertNotNull($row);
        self::assertArrayHasKey('id', $row, 'The persisted row must carry the id storage assigned it.');
        self::assertSame(1, $row['id']);

        $hydrated = $repository->findBy(['uuid' => 'u'])[0] ?? null;
        self::assertNotNull($hydrated);
        self::assertSame($assignedId, $hydrated->id(), 'A re-read row must carry the id storage assigned it.');
        self::assertFalse($hydrated->isNew());

        $hydrated->set('label', 'Aaniin');

        self::assertSame(EntityConstants::SAVED_UPDATED, $repository->save($hydrated));
        self::assertSame($assignedId, $hydrated->id(), 'An update must not change the entity id.');
        self::assertSame(1, $repository->count());
        self::assertCount(1, $repository->findBy(['uuid' => 'u']));

        $reloaded = $repository->find('1');
        self::assertNotNull($reloaded);
        self::assertSame($assignedId, $reloaded->id());
        self::assertSame('Aaniin', $reloaded->get('label'));

        $many = $repository->findMany(['1']);
        self::assertCount(1, $many);
        self::assertSame($assignedId, $many[0]->id());
    }

    #[Test]
    public function anAutoAssignedIdSurvivesForAnEntityTypeWhoseIdKeyIsNotId(): void
    {
        $keys = $this->keys('nid');
        $driver = new InMemoryStorageDriver();
        $repository = $this->repository($driver, $keys);
        $entity = $this->entity($keys);

        self::assertSame(EntityConstants::SAVED_NEW, $repository->save($entity));
        $assignedId = $entity->id();
        self::assertSame(1, $assignedId);

        $row = $driver->read('test_entity', '1');
        self::assertNotNull($row);
        self::assertArrayHasKey('nid', $row, "The row must carry the entity type's own id key.");
        self::assertSame(1, $row['nid']);
        self::assertArrayNotHasKey('id', $row);

        $hydrated = $repository->findBy(['uuid' => 'u'])[0] ?? null;
        self::assertNotNull($hydrated);
        self::assertSame($assignedId, $hydrated->id());

        $hydrated->set('label', 'Aaniin');

        self::assertSame(EntityConstants::SAVED_UPDATED, $repository->save($hydrated));
        self::assertSame($assignedId, $hydrated->id());
        self::assertSame(1, $repository->count());
    }

    #[Test]
    public function aPreassignedIdIsPersistedUnchanged(): void
    {
        $keys = $this->keys('id');
        $driver = new InMemoryStorageDriver();
        $repository = $this->repository($driver, $keys);
        $entity = $this->entity($keys, '7');
        $entity->enforceIsNew();

        self::assertSame(EntityConstants::SAVED_NEW, $repository->save($entity));

        $row = $driver->read('test_entity', '7');
        self::assertNotNull($row);
        self::assertSame('7', $row['id'], 'A caller-supplied id must not be coerced.');

        $hydrated = $repository->findBy(['uuid' => 'u'])[0] ?? null;
        self::assertNotNull($hydrated);
        self::assertSame(7, $hydrated->id());

        self::assertSame(EntityConstants::SAVED_UPDATED, $repository->save($hydrated));
        self::assertSame(1, $repository->count());
    }
}

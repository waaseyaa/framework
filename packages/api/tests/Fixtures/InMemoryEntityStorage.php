<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Fixtures;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\FieldableInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

/**
 * In-memory entity storage for testing.
 */
class InMemoryEntityStorage implements EntityStorageInterface
{
    /** @var array<int|string, EntityInterface> */
    private array $entities = [];

    private int $nextId = 1;

    public function __construct(
        private readonly string $entityTypeId = 'article',
    ) {}

    public function create(array $values = []): EntityInterface
    {
        return new TestEntity(
            values: $values,
            entityTypeId: $this->entityTypeId,
        );
    }

    public function load(int|string $id): ?EntityInterface
    {
        return $this->entities[$id] ?? null;
    }

    public function loadByKey(string $key, mixed $value): ?EntityInterface
    {
        foreach ($this->entities as $entity) {
            if ($entity instanceof FieldableInterface && $entity->get($key) === $value) {
                return $entity;
            }
        }
        return null;
    }

    public function loadMultiple(array $ids = []): array
    {
        if ($ids === []) {
            return $this->entities;
        }

        $result = [];
        foreach ($ids as $id) {
            if (isset($this->entities[$id])) {
                $result[$id] = $this->entities[$id];
            }
        }

        return $result;
    }

    public function save(EntityInterface $entity): int
    {
        $isNew = $entity->isNew();

        if ($isNew) {
            $id = $this->nextId++;
            if ($entity instanceof FieldableInterface) {
                $entity->set('id', $id);
            }
            $entity->enforceIsNew(false);
        }

        $this->entities[$entity->id()] = $entity;

        return $isNew ? 1 : 2;
    }

    public function delete(array $entities): void
    {
        foreach ($entities as $entity) {
            unset($this->entities[$entity->id()]);
        }
    }

    public function getQuery(): EntityQueryInterface
    {
        return new InMemoryEntityQuery(array_keys($this->entities), $this->entities);
    }

    public function getEntityTypeId(): string
    {
        return $this->entityTypeId;
    }
}

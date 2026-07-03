<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Fixtures;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

/**
 * In-memory storage seeded with a fixed, pre-built set of entities (keyed by
 * their own `id()`), for tests that need real entity instances loadable by
 * id/uuid without a database. Unlike {@see StubEntityStorage} (single
 * configurable load handler) this backs a whole small dataset so
 * `getQuery()`/`loadMultiple()` can enumerate it.
 *
 * @internal Test double for Relationship package tests.
 */
final class PresetEntityStorage implements EntityStorageInterface
{
    /** @var array<int|string, EntityInterface> */
    private array $entities = [];

    /** @param list<EntityInterface> $entities */
    public function __construct(
        array $entities,
        private readonly string $entityTypeId,
    ) {
        foreach ($entities as $entity) {
            $id = $entity->id();
            if ($id !== null) {
                $this->entities[$id] = $entity;
            }
        }
    }

    public function load(int|string $id): ?EntityInterface
    {
        return $this->entities[$id] ?? null;
    }

    public function loadByKey(string $key, mixed $value): ?EntityInterface
    {
        foreach ($this->entities as $entity) {
            if ($entity->get($key) === $value) {
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

    public function create(array $values = []): EntityInterface
    {
        throw new \BadMethodCallException('PresetEntityStorage is read-only.');
    }

    public function save(EntityInterface $entity): int
    {
        throw new \BadMethodCallException('PresetEntityStorage is read-only.');
    }

    public function delete(array $entities): void
    {
        throw new \BadMethodCallException('PresetEntityStorage is read-only.');
    }

    public function getQuery(): EntityQueryInterface
    {
        $ids = array_keys($this->entities);

        // Both the count query and the main list query call execute() once
        // each against a FRESH FixedResultEntityQuery instance (JsonApiController
        // calls getQuery() separately for each), so the same full id list is
        // the correct "first execute()" result for either.
        return new FixedResultEntityQuery([$ids, $ids]);
    }

    public function getEntityTypeId(): string
    {
        return $this->entityTypeId;
    }
}

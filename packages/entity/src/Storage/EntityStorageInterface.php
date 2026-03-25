<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Storage;

use Waaseyaa\Entity\EntityInterface;

interface EntityStorageInterface
{
    public function create(array $values = []): EntityInterface;

    public function load(int|string $id): ?EntityInterface;

    /**
     * Load a single entity by an arbitrary unique key.
     *
     * Convenience method that encapsulates the common query+load pattern:
     *   $ids = $storage->getQuery()->condition($key, $value)->range(0, 1)->execute();
     *   return $ids ? $storage->load(reset($ids)) : null;
     */
    public function loadByKey(string $key, mixed $value): ?EntityInterface;

    /** @return array<int|string, EntityInterface> */
    public function loadMultiple(array $ids = []): array;

    /**
     * @return int SAVED_NEW (1) or SAVED_UPDATED (2)
     */
    public function save(EntityInterface $entity): int;

    /** @param EntityInterface[] $entities */
    public function delete(array $entities): void;

    public function getQuery(): EntityQueryInterface;

    public function getEntityTypeId(): string;
}

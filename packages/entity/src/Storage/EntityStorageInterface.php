<?php

declare(strict_types=1);

namespace Aurora\Entity\Storage;

use Aurora\Entity\EntityInterface;

interface EntityStorageInterface
{
    public function create(array $values = []): EntityInterface;

    public function load(int|string $id): ?EntityInterface;

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

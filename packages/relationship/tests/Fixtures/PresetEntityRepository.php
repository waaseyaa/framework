<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Fixtures;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;

/**
 * Read-only {@see EntityRepositoryInterface} wrapping a {@see PresetEntityStorage}.
 *
 * @internal Test double for Relationship package tests.
 */
final class PresetEntityRepository implements EntityRepositoryInterface
{
    public function __construct(
        private readonly PresetEntityStorage $storage,
    ) {}

    public function getQuery(): EntityQueryInterface
    {
        return $this->storage->getQuery();
    }

    public function create(array $values = []): EntityInterface
    {
        throw new \BadMethodCallException('PresetEntityRepository is read-only.');
    }

    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface
    {
        return $this->storage->load($id);
    }

    public function loadWorkingCopy(string $id): ?EntityInterface
    {
        return $this->find($id);
    }

    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array
    {
        return array_values($this->storage->loadMultiple($ids));
    }

    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function save(EntityInterface $entity, bool $validate = true): int
    {
        throw new \BadMethodCallException('PresetEntityRepository is read-only.');
    }

    public function delete(EntityInterface $entity): void
    {
        throw new \BadMethodCallException('PresetEntityRepository is read-only.');
    }

    public function exists(string $id): bool
    {
        return $this->storage->load($id) !== null;
    }

    public function count(array $criteria = []): int
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function loadRevision(string $entityId, int $revisionId): ?EntityInterface
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function rollback(string $entityId, int $targetRevisionId): EntityInterface
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function listRevisions(string $entityId): array
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function setCurrentRevision(string $entityId, int $revisionId): EntityInterface
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function loadPublishedRevision(string $entityId): ?EntityInterface
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function setPublishedRevision(string $entityId, int $revisionId): EntityInterface
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function saveMany(array $entities, bool $validate = true): array
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function deleteMany(array $entities): int
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function findTranslations(EntityInterface $entity): array
    {
        return [];
    }

    public function saveTranslation(string $entityId, string $langcode, array $values, ?string $log = null): int
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function loadTranslation(string $entityId, string $langcode): ?EntityInterface
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function listTranslationRevisions(string $entityId, string $langcode): array
    {
        throw new \BadMethodCallException('Not implemented.');
    }
}

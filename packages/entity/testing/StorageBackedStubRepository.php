<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Testing;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

/**
 * Test stub for EntityRepositoryInterface that delegates find()/findMany()/
 * save()/delete()/getQuery() to an existing EntityStorageInterface double.
 *
 * C-22 migrates production consumers from `getStorage()` to `getRepository()`;
 * this lets a test that already configured a storage double's load()/save()
 * expectations wire the SAME double as the repository double, instead of
 * duplicating the expectations across two separate mocks.
 *
 * Every other method throws — this stub is intentionally narrow.
 *
 * @api — Public test-helper surface. Safe to depend on from any package's tests.
 */
final class StorageBackedStubRepository implements EntityRepositoryInterface
{
    public function __construct(
        private readonly EntityStorageInterface $storage,
    ) {}

    public function create(array $values = []): EntityInterface
    {
        return $this->storage->create($values);
    }

    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface
    {
        return $this->storage->load($id);
    }

    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array
    {
        return array_values($this->storage->loadMultiple($ids));
    }

    public function getQuery(): EntityQueryInterface
    {
        return $this->storage->getQuery();
    }

    public function save(EntityInterface $entity, bool $validate = true): int
    {
        return $this->storage->save($entity);
    }

    public function delete(EntityInterface $entity): void
    {
        $this->storage->delete([$entity]);
    }

    public function exists(string $id): bool
    {
        return $this->storage->load($id) !== null;
    }

    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array
    {
        $entities = array_values($this->storage->loadMultiple());
        foreach ($criteria as $field => $value) {
            $entities = array_values(array_filter(
                $entities,
                static fn(EntityInterface $entity): bool => $entity instanceof \Waaseyaa\Entity\FieldableInterface
                    && $entity->get($field) === $value,
            ));
        }

        return $limit !== null ? array_slice($entities, 0, $limit) : $entities;
    }

    public function count(array $criteria = []): int
    {
        return count($this->findBy($criteria));
    }

    public function loadRevision(string $entityId, int $revisionId): ?EntityInterface
    {
        throw new \BadMethodCallException('StorageBackedStubRepository does not support revisions.');
    }

    public function rollback(string $entityId, int $targetRevisionId): EntityInterface
    {
        throw new \BadMethodCallException('StorageBackedStubRepository does not support revisions.');
    }

    public function listRevisions(string $entityId): array
    {
        throw new \BadMethodCallException('StorageBackedStubRepository does not support revisions.');
    }

    public function setCurrentRevision(string $entityId, int $revisionId): EntityInterface
    {
        throw new \BadMethodCallException('StorageBackedStubRepository does not support revisions.');
    }

    public function loadPublishedRevision(string $entityId): ?EntityInterface
    {
        throw new \BadMethodCallException('StorageBackedStubRepository does not support revisions.');
    }

    public function setPublishedRevision(string $entityId, int $revisionId): EntityInterface
    {
        throw new \BadMethodCallException('StorageBackedStubRepository does not support revisions.');
    }

    public function saveMany(array $entities, bool $validate = true): array
    {
        return array_map(fn(EntityInterface $entity): int => $this->save($entity, $validate), $entities);
    }

    public function deleteMany(array $entities): int
    {
        foreach ($entities as $entity) {
            $this->delete($entity);
        }

        return count($entities);
    }

    public function findTranslations(EntityInterface $entity): array
    {
        return [];
    }

    public function saveTranslation(string $entityId, string $langcode, array $values, ?string $log = null): int
    {
        throw new \BadMethodCallException('StorageBackedStubRepository does not support translations.');
    }

    public function loadTranslation(string $entityId, string $langcode): ?EntityInterface
    {
        throw new \BadMethodCallException('StorageBackedStubRepository does not support translations.');
    }

    public function listTranslationRevisions(string $entityId, string $langcode): array
    {
        throw new \BadMethodCallException('StorageBackedStubRepository does not support translations.');
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Fixtures;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;

/**
 * In-memory `EntityRepositoryInterface` for testing (C-22).
 *
 * Wraps an {@see InMemoryEntityStorage} instance and delegates every method
 * that the storage fixture already supports (load/loadMultiple/save/delete,
 * and — the point of this fixture — `getQuery()`), so a test can register
 * both a storage factory and a repository factory over the SAME underlying
 * entities without duplicating fixture data. Revision/translation methods
 * are not supported by the in-memory storage fixture and throw.
 */
final class InMemoryEntityRepository implements EntityRepositoryInterface
{
    public function __construct(
        private readonly InMemoryEntityStorage $storage,
    ) {}

    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface
    {
        return $this->storage->load($id);
    }

    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array
    {
        return array_values($this->storage->loadMultiple($ids));
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

    public function count(array $criteria = []): int
    {
        return count($this->findBy($criteria));
    }

    public function loadRevision(string $entityId, int $revisionId): ?EntityInterface
    {
        throw new \LogicException('InMemoryEntityRepository does not support revisions.');
    }

    public function rollback(string $entityId, int $targetRevisionId): EntityInterface
    {
        throw new \LogicException('InMemoryEntityRepository does not support revisions.');
    }

    public function listRevisions(string $entityId): array
    {
        throw new \LogicException('InMemoryEntityRepository does not support revisions.');
    }

    public function setCurrentRevision(string $entityId, int $revisionId): EntityInterface
    {
        throw new \LogicException('InMemoryEntityRepository does not support revisions.');
    }

    public function loadPublishedRevision(string $entityId): ?EntityInterface
    {
        throw new \LogicException('InMemoryEntityRepository does not support revisions.');
    }

    public function setPublishedRevision(string $entityId, int $revisionId): EntityInterface
    {
        throw new \LogicException('InMemoryEntityRepository does not support revisions.');
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
        throw new \LogicException('InMemoryEntityRepository does not support translations.');
    }

    public function loadTranslation(string $entityId, string $langcode): ?EntityInterface
    {
        throw new \LogicException('InMemoryEntityRepository does not support translations.');
    }

    public function listTranslationRevisions(string $entityId, string $langcode): array
    {
        throw new \LogicException('InMemoryEntityRepository does not support translations.');
    }
}

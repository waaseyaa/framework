<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Fixtures;

use Waaseyaa\Entity\Concurrency\EntityMutationToken;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;

/**
 * An {@see InMemoryEntityRepository} whose `loadWorkingCopy()` can answer a
 * DIFFERENT entity than `find()` for a given id — the shape a disciplined
 * revisionable type presents while a forward draft is in flight (published
 * pointer versus tip revision). Everything else delegates. Counts saves so a
 * test can prove nothing was persisted before authorization (#2788 review).
 */
final class DivergentWorkingCopyEntityRepository implements EntityRepositoryInterface
{
    /** @var array<string, EntityInterface> id => working copy */
    private array $workingCopies = [];

    public int $saves = 0;

    public function __construct(private readonly InMemoryEntityRepository $inner) {}

    public function withWorkingCopy(int|string $id, EntityInterface $workingCopy): void
    {
        $this->workingCopies[(string) $id] = $workingCopy;
    }

    public function loadWorkingCopy(int|string $id): ?EntityInterface
    {
        return $this->workingCopies[(string) $id] ?? $this->inner->loadWorkingCopy($id);
    }

    public function save(EntityInterface $entity, bool $validate = true): int
    {
        ++$this->saves;

        return $this->inner->save($entity, $validate);
    }

    public function create(array $values = []): EntityInterface
    {
        return $this->inner->create($values);
    }

    public function find(int|string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface
    {
        return $this->inner->find($id, $langcode, $fallback);
    }

    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array
    {
        return $this->inner->findMany($ids, $langcode, $fallback);
    }

    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array
    {
        return $this->inner->findBy($criteria, $orderBy, $limit);
    }

    public function getQuery(): EntityQueryInterface
    {
        return $this->inner->getQuery();
    }

    public function delete(EntityInterface $entity): void
    {
        $this->inner->delete($entity);
    }

    public function exists(int|string $id): bool
    {
        return $this->inner->exists($id);
    }

    public function count(array $criteria = []): int
    {
        return $this->inner->count($criteria);
    }

    public function loadRevision(int|string $entityId, int $revisionId): ?EntityInterface
    {
        return $this->inner->loadRevision($entityId, $revisionId);
    }

    public function rollback(int|string $entityId, int $targetRevisionId, ?EntityMutationToken $expected = null): EntityInterface
    {
        return $this->inner->rollback($entityId, $targetRevisionId, $expected);
    }

    public function listRevisions(int|string $entityId): array
    {
        return $this->inner->listRevisions($entityId);
    }

    public function setCurrentRevision(int|string $entityId, int $revisionId, ?EntityMutationToken $expected = null): EntityInterface
    {
        return $this->inner->setCurrentRevision($entityId, $revisionId, $expected);
    }

    public function loadPublishedRevision(int|string $entityId): ?EntityInterface
    {
        return $this->inner->loadPublishedRevision($entityId);
    }

    public function setPublishedRevision(int|string $entityId, int $revisionId, ?EntityMutationToken $expected = null): EntityInterface
    {
        return $this->inner->setPublishedRevision($entityId, $revisionId, $expected);
    }

    public function saveMany(array $entities, bool $validate = true): array
    {
        return array_map(fn(EntityInterface $entity): int => $this->save($entity, $validate), $entities);
    }

    public function deleteMany(array $entities): int
    {
        return $this->inner->deleteMany($entities);
    }

    public function findTranslations(EntityInterface $entity): array
    {
        return $this->inner->findTranslations($entity);
    }

    public function saveTranslation(int|string $entityId, string $langcode, array $values, ?string $log = null, ?EntityMutationToken $expected = null): int
    {
        return $this->inner->saveTranslation($entityId, $langcode, $values, $log, $expected);
    }

    public function loadTranslation(int|string $entityId, string $langcode): ?EntityInterface
    {
        return $this->inner->loadTranslation($entityId, $langcode);
    }

    public function listTranslationRevisions(int|string $entityId, string $langcode): array
    {
        return $this->inner->listTranslationRevisions($entityId, $langcode);
    }
}

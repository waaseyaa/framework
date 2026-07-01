<?php

declare(strict_types=1);

namespace Waaseyaa\Entity\Testing;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;

/**
 * Test stub for EntityRepositoryInterface that only supports getQuery().
 *
 * C-22 moved the account-checked query surface from EntityStorageInterface
 * onto EntityRepositoryInterface. Tests that already stub a storage's
 * getQuery() with a configured {@see EntityQueryInterface} double (e.g.
 * {@see RecordingEntityQuery}, a `StubEntityQuery`) can wrap that SAME query
 * double in this class and pass it as the entity type manager's
 * getRepository() return value — no need to duplicate query configuration
 * across a storage double and a repository double.
 *
 * Accepts either a fixed query instance, or a `\Closure(): EntityQueryInterface`
 * for callers that build a fresh query per call (e.g. a fake storage whose
 * getQuery() constructs a new query object every time — a batching/pagination
 * consumer that calls getQuery() once per page needs that live behavior, not
 * a one-time snapshot).
 *
 * Every other method throws — this stub is intentionally narrow.
 *
 * @api — Public test-helper surface. Safe to depend on from any package's tests.
 */
final class QueryOnlyStubRepository implements EntityRepositoryInterface
{
    private readonly EntityQueryInterface|\Closure $query;

    public function __construct(EntityQueryInterface|\Closure $query)
    {
        $this->query = $query;
    }

    public function getQuery(): EntityQueryInterface
    {
        return $this->query instanceof \Closure ? ($this->query)() : $this->query;
    }

    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface
    {
        throw new \BadMethodCallException('QueryOnlyStubRepository only supports getQuery().');
    }

    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array
    {
        throw new \BadMethodCallException('QueryOnlyStubRepository only supports getQuery().');
    }

    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array
    {
        throw new \BadMethodCallException('QueryOnlyStubRepository only supports getQuery().');
    }

    public function save(EntityInterface $entity, bool $validate = true): int
    {
        throw new \BadMethodCallException('QueryOnlyStubRepository only supports getQuery().');
    }

    public function delete(EntityInterface $entity): void
    {
        throw new \BadMethodCallException('QueryOnlyStubRepository only supports getQuery().');
    }

    public function exists(string $id): bool
    {
        throw new \BadMethodCallException('QueryOnlyStubRepository only supports getQuery().');
    }

    public function count(array $criteria = []): int
    {
        throw new \BadMethodCallException('QueryOnlyStubRepository only supports getQuery().');
    }

    public function loadRevision(string $entityId, int $revisionId): ?EntityInterface
    {
        throw new \BadMethodCallException('QueryOnlyStubRepository only supports getQuery().');
    }

    public function rollback(string $entityId, int $targetRevisionId): EntityInterface
    {
        throw new \BadMethodCallException('QueryOnlyStubRepository only supports getQuery().');
    }

    public function listRevisions(string $entityId): array
    {
        throw new \BadMethodCallException('QueryOnlyStubRepository only supports getQuery().');
    }

    public function setCurrentRevision(string $entityId, int $revisionId): EntityInterface
    {
        throw new \BadMethodCallException('QueryOnlyStubRepository only supports getQuery().');
    }

    public function loadPublishedRevision(string $entityId): ?EntityInterface
    {
        throw new \BadMethodCallException('QueryOnlyStubRepository only supports getQuery().');
    }

    public function setPublishedRevision(string $entityId, int $revisionId): EntityInterface
    {
        throw new \BadMethodCallException('QueryOnlyStubRepository only supports getQuery().');
    }

    public function saveMany(array $entities, bool $validate = true): array
    {
        throw new \BadMethodCallException('QueryOnlyStubRepository only supports getQuery().');
    }

    public function deleteMany(array $entities): int
    {
        throw new \BadMethodCallException('QueryOnlyStubRepository only supports getQuery().');
    }

    public function findTranslations(EntityInterface $entity): array
    {
        return [];
    }

    public function saveTranslation(string $entityId, string $langcode, array $values, ?string $log = null): int
    {
        throw new \BadMethodCallException('QueryOnlyStubRepository only supports getQuery().');
    }

    public function loadTranslation(string $entityId, string $langcode): ?EntityInterface
    {
        throw new \BadMethodCallException('QueryOnlyStubRepository only supports getQuery().');
    }

    public function listTranslationRevisions(string $entityId, string $langcode): array
    {
        throw new \BadMethodCallException('QueryOnlyStubRepository only supports getQuery().');
    }
}

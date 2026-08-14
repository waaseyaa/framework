<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Fixtures;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\Concurrency\EntityMutationToken;
use Waaseyaa\Entity\Concurrency\EntityMutationConflictException;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/**
 * In-memory repository for exercising the entity tools. Records the writes and
 * revision operations the tools perform so tests can assert on them, without a
 * real storage driver.
 */
final class InMemoryToolRepository implements EntityRepositoryInterface
{
    /** @var array<string, EntityInterface> */
    private array $store = [];
    /** @var list<EntityInterface> */
    public array $saved = [];
    /** @var list<string> */
    public array $deleted = [];
    /** @var list<array{0:string,1:int}> */
    public array $setCurrentCalls = [];
    /** @var list<array{0:string,1:int}> */
    public array $rollbackCalls = [];
    /** @var list<EntityInterface> */
    public array $revisions = [];

    /** @var array<string, EntityInterface> */
    private array $workingCopies = [];

    /** @var array<string, EntityMutationToken> */
    private array $mutationAuthorities = [];

    public function seed(EntityInterface $entity): void
    {
        $this->attachMutationAuthority($entity);
        $this->store[(string) $entity->id()] = $entity;
    }

    /**
     * Seed a diverged working copy (CW-v1 option-1: a draft tip newer than
     * the served base row) so tests can assert a tool mutates the working
     * copy rather than the find() entity.
     */
    public function seedWorkingCopy(EntityInterface $entity): void
    {
        $this->attachMutationAuthority($entity);
        $this->workingCopies[(string) $entity->id()] = $entity;
    }

    public function create(array $values = []): EntityInterface
    {
        throw new \LogicException('InMemoryToolRepository does not support create().');
    }

    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface
    {
        return $this->store[$id] ?? null;
    }

    public function loadWorkingCopy(string $id): ?EntityInterface
    {
        return $this->workingCopies[$id] ?? $this->find($id);
    }

    public function save(EntityInterface $entity, bool $validate = true): int
    {
        if ($entity instanceof EntityBase && $entity->id() !== null) {
            $id = (string) $entity->id();
            $expected = $entity->mutationToken();
            $current = $this->mutationAuthorities[$id] ?? null;
            if ($expected === null || $current === null || !hash_equals($current->toOpaqueString(), $expected->toOpaqueString())) {
                throw new EntityMutationConflictException('default', $entity->getEntityTypeId(), $id);
            }
            $successor = EntityMutationToken::issue(
                'ai-tool-test',
                'default',
                $entity->getEntityTypeId(),
                $id,
                $current->aggregateVersion + 1,
            );
            $this->mutationAuthorities[$id] = $successor;
            $entity->_hydrateMutationToken($successor);
        }
        $this->saved[] = $entity;
        $this->store[(string) $entity->id()] = $entity;

        return 1;
    }

    public function delete(EntityInterface $entity): void
    {
        $id = (string) $entity->id();
        if ($entity instanceof EntityBase) {
            $expected = $entity->mutationToken();
            $current = $this->mutationAuthorities[$id] ?? null;
            if ($expected === null || $current === null || !hash_equals($current->toOpaqueString(), $expected->toOpaqueString())) {
                throw new EntityMutationConflictException('default', $entity->getEntityTypeId(), $id);
            }
        }
        $this->deleted[] = $id;
        unset($this->store[$id], $this->workingCopies[$id], $this->mutationAuthorities[$id]);
    }

    public function setCurrentRevision(string $entityId, int $revisionId, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): EntityInterface
    {
        $this->setCurrentCalls[] = [$entityId, $revisionId];

        return $this->store[$entityId] ?? new ToolTestEntity(['id' => $entityId]);
    }

    public function rollback(string $entityId, int $targetRevisionId, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): EntityInterface
    {
        $this->rollbackCalls[] = [$entityId, $targetRevisionId];

        return new ToolTestEntity(['id' => $entityId, 'revision_id' => 99]);
    }

    public function listRevisions(string $entityId): array
    {
        return $this->revisions;
    }

    // Unused by the entity tools under test.

    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array
    {
        return [];
    }

    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array
    {
        return array_values($this->store);
    }

    public function getQuery(): \Waaseyaa\Entity\Storage\EntityQueryInterface
    {
        throw new \LogicException('getQuery() not implemented in this test double');
    }

    public function exists(string $id): bool
    {
        return isset($this->store[$id]);
    }

    public function count(array $criteria = []): int
    {
        return count($this->store);
    }

    public function loadRevision(string $entityId, int $revisionId): ?EntityInterface
    {
        return null;
    }

    public function loadPublishedRevision(string $entityId): ?EntityInterface
    {
        return null;
    }

    public function setPublishedRevision(string $entityId, int $revisionId, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): EntityInterface
    {
        return $this->store[$entityId] ?? new ToolTestEntity(['id' => $entityId]);
    }

    public function saveMany(array $entities, bool $validate = true): array
    {
        return [];
    }

    public function deleteMany(array $entities): int
    {
        return 0;
    }

    public function findTranslations(EntityInterface $entity): array
    {
        return [];
    }

    // Two-axis translation surface (EntityRepositoryInterface, b1) — this fixture
    // is single-axis only and never exercises it.

    public function saveTranslation(string $entityId, string $langcode, array $values, ?string $log = null, ?\Waaseyaa\Entity\Concurrency\EntityMutationToken $expected = null): int
    {
        throw new \BadMethodCallException('two-axis translation is not supported by ' . self::class);
    }

    public function loadTranslation(string $entityId, string $langcode): ?EntityInterface
    {
        throw new \BadMethodCallException('two-axis translation is not supported by ' . self::class);
    }

    public function listTranslationRevisions(string $entityId, string $langcode): array
    {
        throw new \BadMethodCallException('two-axis translation is not supported by ' . self::class);
    }

    private function attachMutationAuthority(EntityInterface $entity): void
    {
        if (!$entity instanceof EntityBase || $entity->id() === null) {
            return;
        }
        $id = (string) $entity->id();
        $authority = $this->mutationAuthorities[$id] ?? $entity->mutationToken() ?? EntityMutationToken::issue(
            'ai-tool-test',
            'default',
            $entity->getEntityTypeId(),
            $id,
            1,
        );
        $this->mutationAuthorities[$id] = $authority;
        $entity->_hydrateMutationToken($authority);
    }
}

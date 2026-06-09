<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Fixtures;

use Waaseyaa\Entity\EntityInterface;
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

    public function seed(EntityInterface $entity): void
    {
        $this->store[(string) $entity->id()] = $entity;
    }

    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface
    {
        return $this->store[$id] ?? null;
    }

    public function save(EntityInterface $entity, bool $validate = true): int
    {
        $this->saved[] = $entity;
        $this->store[(string) $entity->id()] = $entity;

        return 1;
    }

    public function delete(EntityInterface $entity): void
    {
        $this->deleted[] = (string) $entity->id();
        unset($this->store[(string) $entity->id()]);
    }

    public function setCurrentRevision(string $entityId, int $revisionId): EntityInterface
    {
        $this->setCurrentCalls[] = [$entityId, $revisionId];

        return $this->store[$entityId] ?? new ToolTestEntity(['id' => $entityId]);
    }

    public function rollback(string $entityId, int $targetRevisionId): EntityInterface
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

    public function setPublishedRevision(string $entityId, int $revisionId): EntityInterface
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

    public function saveTranslation(string $entityId, string $langcode, array $values, ?string $log = null): int
    {
        throw new \BadMethodCallException('two-axis translation is not supported by ' . self::class);
    }

    public function saveTranslationRevision(string $entityId, string $langcode, array $values, ?string $log = null): int
    {
        throw new \BadMethodCallException('two-axis translation is not supported by ' . self::class);
    }

    public function saveTranslationRevisions(string $entityId, array $byLangcode, ?string $log = null): array
    {
        throw new \BadMethodCallException('two-axis translation is not supported by ' . self::class);
    }

    public function loadTranslation(string $entityId, string $langcode): ?EntityInterface
    {
        throw new \BadMethodCallException('two-axis translation is not supported by ' . self::class);
    }

    public function loadTranslationRevision(string $entityId, string $langcode, int $revisionId): ?EntityInterface
    {
        throw new \BadMethodCallException('two-axis translation is not supported by ' . self::class);
    }

    public function loadTranslationTip(string $entityId, string $langcode): ?EntityInterface
    {
        throw new \BadMethodCallException('two-axis translation is not supported by ' . self::class);
    }

    public function listTranslationRevisions(string $entityId, string $langcode): array
    {
        throw new \BadMethodCallException('two-axis translation is not supported by ' . self::class);
    }

    public function translationLangcodes(string $entityId): array
    {
        throw new \BadMethodCallException('two-axis translation is not supported by ' . self::class);
    }
}

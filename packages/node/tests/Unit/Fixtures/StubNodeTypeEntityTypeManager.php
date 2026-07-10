<?php

declare(strict_types=1);

namespace Waaseyaa\Node\Tests\Unit\Fixtures;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Node\NodeType;

/**
 * Minimal `EntityTypeManagerInterface` test double for
 * {@see \Waaseyaa\Node\Tests\Unit\Listener\NodeRevisionDefaultListenerTest}.
 *
 * Serves a single configurable `node_type` repository whose `find()` result
 * is provided by the caller — every other method throws, so a test that
 * accidentally exercises an unconfigured path fails loudly instead of
 * silently returning a plausible-looking default.
 *
 * @internal Test double for Node package tests.
 */
final class StubNodeTypeEntityTypeManager implements EntityTypeManagerInterface
{
    /**
     * @param array<string, ?NodeType> $nodeTypesByBundle Bundle machine name => NodeType (or null to simulate "not found").
     */
    public function __construct(
        private readonly array $nodeTypesByBundle = [],
        private readonly bool $hasNodeTypeDefinition = true,
    ) {}

    public function hasDefinition(string $entityTypeId): bool
    {
        return $entityTypeId === 'node_type' && $this->hasNodeTypeDefinition;
    }

    public function getRepository(string $entityTypeId): EntityRepositoryInterface
    {
        if ($entityTypeId !== 'node_type') {
            throw new \BadMethodCallException("Unexpected getRepository('{$entityTypeId}').");
        }

        return new class ($this->nodeTypesByBundle) implements EntityRepositoryInterface {
            /** @param array<string, ?NodeType> $nodeTypesByBundle */
            public function __construct(private readonly array $nodeTypesByBundle) {}

            public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface
            {
                if (!array_key_exists($id, $this->nodeTypesByBundle)) {
                    throw new \OutOfBoundsException("Unconfigured bundle lookup: '{$id}'.");
                }

                return $this->nodeTypesByBundle[$id];
            }

            public function create(array $values = []): EntityInterface
            {
                throw new \BadMethodCallException('Not implemented.');
            }

            public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array
            {
                throw new \BadMethodCallException('Not implemented.');
            }

            public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array
            {
                throw new \BadMethodCallException('Not implemented.');
            }

            public function getQuery(): EntityQueryInterface
            {
                throw new \BadMethodCallException('Not implemented.');
            }

            public function save(EntityInterface $entity, bool $validate = true): int
            {
                throw new \BadMethodCallException('Not implemented.');
            }

            public function delete(EntityInterface $entity): void
            {
                throw new \BadMethodCallException('Not implemented.');
            }

            public function exists(string $id): bool
            {
                throw new \BadMethodCallException('Not implemented.');
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
                throw new \BadMethodCallException('Not implemented.');
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
        };
    }

    public function getDefinition(string $entityTypeId): EntityTypeInterface
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function getDefinitions(): array
    {
        throw new \BadMethodCallException('Not implemented.');
    }

    public function getStorage(string $entityTypeId): EntityStorageInterface
    {
        throw new \BadMethodCallException('Not implemented.');
    }
}

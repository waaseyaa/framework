<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Tests\Fixtures;

use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

/**
 * A tiny EntityTypeManager exposing a single entity type + repository, for
 * exercising the entity tools in isolation.
 */
final class SingleTypeEntityTypeManager implements EntityTypeManagerInterface
{
    public function __construct(
        private readonly EntityTypeInterface $definition,
        private readonly EntityRepositoryInterface $repository,
    ) {}

    public function getDefinition(string $entityTypeId): EntityTypeInterface
    {
        if ($entityTypeId !== $this->definition->id()) {
            throw new \InvalidArgumentException('Unknown entity type: ' . $entityTypeId);
        }

        return $this->definition;
    }

    public function getDefinitions(): array
    {
        return [$this->definition->id() => $this->definition];
    }

    public function hasDefinition(string $entityTypeId): bool
    {
        return $entityTypeId === $this->definition->id();
    }

    public function getRepository(string $entityTypeId): EntityRepositoryInterface
    {
        return $this->repository;
    }

    public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array
    {
        return [];
    }

    public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}

    public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void {}

    public function getStorage(string $entityTypeId): EntityStorageInterface
    {
        throw new \BadMethodCallException('getStorage is not used by the entity tools.');
    }
}

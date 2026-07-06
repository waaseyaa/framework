<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Tests\Support;

use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

/**
 * Decorates an {@see EntityTypeManagerInterface} and counts calls to
 * `getDefinitions()`/`resolveFieldDefinitions()` — the two methods
 * `AnchorRegistry::catalog()` reads once per catalog build (the latter is the
 * immediate predecessor of each `SchemaPresenter::present()` call, which is
 * `final` and cannot be mocked/spied directly per house convention).
 *
 * Used to prove `AnchorRegistry::catalog()` is memoized per instance: a
 * rebuilt-every-call regression would multiply these counts by the number of
 * `catalog()`/`isValid()`/`anchorIds()` invocations instead of holding steady.
 */
final class CountingEntityTypeManager implements EntityTypeManagerInterface
{
    public int $getDefinitionsCallCount = 0;

    public int $resolveFieldDefinitionsCallCount = 0;

    public function __construct(
        private readonly EntityTypeManagerInterface $inner,
    ) {}

    public function getDefinitions(): array
    {
        $this->getDefinitionsCallCount++;

        return $this->inner->getDefinitions();
    }

    public function getDefinition(string $entityTypeId): EntityTypeInterface
    {
        return $this->inner->getDefinition($entityTypeId);
    }

    public function hasDefinition(string $entityTypeId): bool
    {
        return $this->inner->hasDefinition($entityTypeId);
    }

    public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array
    {
        $this->resolveFieldDefinitionsCallCount++;

        return $this->inner->resolveFieldDefinitions($entityTypeId, $bundle);
    }

    public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void
    {
        $this->inner->registerEntityType($type, $registrant);
    }

    public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void
    {
        $this->inner->registerCoreEntityType($type, $registrant);
    }

    public function getStorage(string $entityTypeId): EntityStorageInterface
    {
        return $this->inner->getStorage($entityTypeId);
    }

    public function getRepository(string $entityTypeId): EntityRepositoryInterface
    {
        return $this->inner->getRepository($entityTypeId);
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Tests\Support;

use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

/**
 * Minimal in-memory EntityTypeManager for Wayfinding anchor tests: only the two
 * methods the AnchorRegistry uses (getDefinitions, resolveFieldDefinitions) plus
 * getDefinition/hasDefinition are real; storage/repository access is unsupported.
 */
final class InMemoryEntityTypeManager implements EntityTypeManagerInterface
{
    /**
     * @param array<string, EntityTypeInterface>   $definitions
     * @param array<string, array<string, mixed>>  $fieldDefinitions field definitions keyed by entity type id
     */
    public function __construct(
        private readonly array $definitions,
        private readonly array $fieldDefinitions = [],
    ) {}

    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    public function getDefinition(string $entityTypeId): EntityTypeInterface
    {
        return $this->definitions[$entityTypeId]
            ?? throw new \RuntimeException("Unknown entity type: {$entityTypeId}");
    }

    public function hasDefinition(string $entityTypeId): bool
    {
        return isset($this->definitions[$entityTypeId]);
    }

    public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array
    {
        return $this->fieldDefinitions[$entityTypeId] ?? [];
    }

    public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void
    {
        throw new \LogicException('not supported in test');
    }

    public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void
    {
        throw new \LogicException('not supported in test');
    }

    public function getStorage(string $entityTypeId): EntityStorageInterface
    {
        throw new \LogicException('not supported in test');
    }

    public function getRepository(string $entityTypeId): EntityRepositoryInterface
    {
        throw new \LogicException('not supported in test');
    }
}

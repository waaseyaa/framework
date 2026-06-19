<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Support;

use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

/**
 * Minimal {@see EntityTypeManagerInterface} for the Wayfinding MCP tool tests:
 * serves a fixed repository map (for the trail tools' {@see \Waaseyaa\Wayfinding\Trail\TrailStore})
 * and a fixed definitions map (for the emit tool's {@see \Waaseyaa\Wayfinding\Anchor\AnchorRegistry}).
 * Everything else is out of scope and throws.
 */
final class FakeEntityTypeManager implements EntityTypeManagerInterface
{
    /**
     * @param array<string, EntityRepositoryInterface> $repositories
     * @param array<string, EntityTypeInterface> $definitions
     */
    public function __construct(
        private readonly array $repositories = [],
        private readonly array $definitions = [],
    ) {}

    public function getRepository(string $entityTypeId): EntityRepositoryInterface
    {
        return $this->repositories[$entityTypeId]
            ?? throw new \BadMethodCallException("No fake repository for '{$entityTypeId}'.");
    }

    /** @return array<string, EntityTypeInterface> */
    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    public function getDefinition(string $entityTypeId): EntityTypeInterface
    {
        return $this->definitions[$entityTypeId]
            ?? throw new \BadMethodCallException("No fake definition for '{$entityTypeId}'.");
    }

    public function hasDefinition(string $entityTypeId): bool
    {
        return isset($this->definitions[$entityTypeId]);
    }

    /** @return array<string, \Waaseyaa\Field\FieldDefinitionInterface> */
    public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array
    {
        return [];
    }

    public function getStorage(string $entityTypeId): EntityStorageInterface
    {
        throw new \BadMethodCallException('getStorage() is not supported by the fake.');
    }

    public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void
    {
        throw new \BadMethodCallException('registerEntityType() is not supported by the fake.');
    }

    public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void
    {
        throw new \BadMethodCallException('registerCoreEntityType() is not supported by the fake.');
    }
}

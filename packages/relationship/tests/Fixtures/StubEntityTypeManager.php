<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Fixtures;

use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

/**
 * Configurable EntityTypeManager stub.
 *
 * @internal Test double for Relationship package tests.
 */
class StubEntityTypeManager implements EntityTypeManagerInterface
{
    /**
     * @param list<string> $knownTypes Entity type IDs that hasDefinition() returns true for.
     * @param ?EntityStorageInterface $storage Returned by getStorage(). Default: new StubEntityStorage().
     * @param ?\Closure(string): bool $hasDefinitionOverride Overrides hasDefinition() when set.
     */
    public function __construct(
        private readonly array $knownTypes = [],
        private readonly ?EntityStorageInterface $storage = null,
        private readonly ?\Closure $hasDefinitionOverride = null,
    ) {}

    public function getDefinition(string $entityTypeId): EntityTypeInterface
    {
        return new class ($entityTypeId) implements EntityTypeInterface {
            public function __construct(private readonly string $id) {}
            public function id(): string { return $this->id; }
            public function getLabel(): string { return $this->id; }
            public function getClass(): string { return ''; }
            public function getStorageClass(): string { return ''; }
            public function getKeys(): array { return ['id' => 'id', 'uuid' => 'uuid']; }
            public function isRevisionable(): bool { return false; }
            public function getRevisionDefault(): bool { return false; }
            public function isTranslatable(): bool { return false; }
            public function getBundleEntityType(): ?string { return null; }
            public function getConstraints(): array { return []; }
            public function getFieldDefinitions(): array { return []; }
            public function getPrimaryStorageBackend(): ?string { return null; }
            public function getGroup(): ?string { return null; }
            public function getDescription(): ?string { return null; }
            public function getTenancy(): ?array { return null; }
        };
    }

    public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array
    {
        return $this->getDefinition($entityTypeId)->getFieldDefinitions();
    }

    public function hasDefinition(string $entityTypeId): bool
    {
        if ($this->hasDefinitionOverride !== null) {
            return ($this->hasDefinitionOverride)($entityTypeId);
        }

        return in_array($entityTypeId, $this->knownTypes, true);
    }

    public function getStorage(string $entityTypeId): EntityStorageInterface
    {
        return $this->storage ?? new StubEntityStorage();
    }

    public function getRepository(string $entityTypeId): EntityRepositoryInterface
    {
        return new StubEntityRepository($this->getStorage($entityTypeId));
    }

    public function getDefinitions(): array
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
}

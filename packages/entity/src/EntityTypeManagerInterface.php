<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

interface EntityTypeManagerInterface
{
    public function getDefinition(string $entityTypeId): EntityTypeInterface;

    /** @throws \DomainException If the entity type ID uses the reserved `core.` namespace. */
    public function registerEntityType(EntityTypeInterface $type): void;

    public function registerCoreEntityType(EntityTypeInterface $type): void;

    /** @return array<string, EntityTypeInterface> */
    public function getDefinitions(): array;

    public function hasDefinition(string $entityTypeId): bool;

    public function getStorage(string $entityTypeId): Storage\EntityStorageInterface;
}

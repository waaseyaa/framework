<?php

declare(strict_types=1);

namespace Aurora\Entity;

interface EntityTypeManagerInterface
{
    public function getDefinition(string $entityTypeId): EntityTypeInterface;

    /** @return array<string, EntityTypeInterface> */
    public function getDefinitions(): array;

    public function hasDefinition(string $entityTypeId): bool;

    public function getStorage(string $entityTypeId): Storage\EntityStorageInterface;
}

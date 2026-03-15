<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityTypeInterface;

/**
 * Factory for creating and caching entity storage instances.
 *
 * Creates SqlEntityStorage instances on demand and caches them
 * by entity type ID so the same storage is reused for repeated
 * requests for the same entity type.
 */
final class EntityStorageFactory
{
    /** @var array<string, SqlEntityStorage> */
    private array $storages = [];

    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * Returns the storage handler for the given entity type.
     *
     * Creates and caches the storage instance on first access.
     */
    public function getStorage(EntityTypeInterface $entityType): SqlEntityStorage
    {
        $entityTypeId = $entityType->id();

        if (!isset($this->storages[$entityTypeId])) {
            $this->storages[$entityTypeId] = new SqlEntityStorage(
                $entityType,
                $this->database,
                $this->eventDispatcher,
            );
        }

        return $this->storages[$entityTypeId];
    }
}

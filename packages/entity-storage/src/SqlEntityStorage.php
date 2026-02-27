<?php

declare(strict_types=1);

namespace Aurora\EntityStorage;

use Aurora\Database\DatabaseInterface;
use Aurora\Entity\EntityConstants;
use Aurora\Entity\EntityInterface;
use Aurora\Entity\EntityTypeInterface;
use Aurora\Entity\Event\EntityEvent;
use Aurora\Entity\Event\EntityEvents;
use Aurora\Entity\Storage\EntityQueryInterface;
use Aurora\Entity\Storage\EntityStorageInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * SQL-based entity storage implementation.
 *
 * Stores entities in SQL tables using the aurora/database-legacy package.
 * Supports CRUD operations and dispatches entity lifecycle events.
 *
 * For v0.1.0:
 * - Flat table schema (all fields in one table)
 * - No revision support
 * - No translation support
 */
final class SqlEntityStorage implements EntityStorageInterface
{
    private readonly string $tableName;
    private readonly string $idKey;
    private readonly string $uuidKey;

    /** @var array<string, string> */
    private readonly array $entityKeys;

    public function __construct(
        private readonly EntityTypeInterface $entityType,
        private readonly DatabaseInterface $database,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
        $this->tableName = $this->entityType->id();
        $keys = $this->entityType->getKeys();
        $this->idKey = $keys['id'] ?? 'id';
        $this->uuidKey = $keys['uuid'] ?? 'uuid';
        $this->entityKeys = $keys;
    }

    public function create(array $values = []): EntityInterface
    {
        $class = $this->entityType->getClass();

        /** @var EntityInterface $entity */
        $entity = new $class(
            values: $values,
            entityTypeId: $this->entityType->id(),
            entityKeys: $this->entityKeys,
        );

        return $entity;
    }

    public function load(int|string $id): ?EntityInterface
    {
        $result = $this->database->select($this->tableName)
            ->fields($this->tableName)
            ->condition($this->idKey, $id)
            ->execute();

        $row = null;
        foreach ($result as $r) {
            $row = (array) $r;
            break;
        }

        if ($row === null) {
            return null;
        }

        return $this->mapRowToEntity($row);
    }

    /**
     * @param array<int|string> $ids
     * @return array<int|string, EntityInterface>
     */
    public function loadMultiple(array $ids = []): array
    {
        if (empty($ids)) {
            return [];
        }

        $result = $this->database->select($this->tableName)
            ->fields($this->tableName)
            ->condition($this->idKey, $ids, 'IN')
            ->execute();

        $entities = [];
        foreach ($result as $row) {
            $row = (array) $row;
            $entity = $this->mapRowToEntity($row);
            $entityId = $entity->id();
            if ($entityId !== null) {
                $entities[$entityId] = $entity;
            }
        }

        return $entities;
    }

    public function save(EntityInterface $entity): int
    {
        $isNew = $entity->isNew();
        $values = $entity->toArray();

        // Dispatch PRE_SAVE event.
        $this->eventDispatcher->dispatch(
            new EntityEvent($entity),
            EntityEvents::PRE_SAVE->value,
        );

        if ($isNew) {
            // Remove id key if null (auto-increment will handle it).
            $insertValues = [];
            foreach ($values as $key => $value) {
                if ($key === $this->idKey && $value === null) {
                    continue;
                }
                $insertValues[$key] = $value;
            }

            $id = $this->database->insert($this->tableName)
                ->fields(array_keys($insertValues))
                ->values($insertValues)
                ->execute();

            // Set the ID on the entity after insert.
            if (method_exists($entity, 'set')) {
                $entity->set($this->idKey, (int) $id);
            }

            // Mark entity as no longer new.
            if (method_exists($entity, 'enforceIsNew')) {
                $entity->enforceIsNew(false);
            }

            $result = EntityConstants::SAVED_NEW;
        } else {
            // Build update fields excluding the ID.
            $updateFields = [];
            foreach ($values as $key => $value) {
                if ($key === $this->idKey) {
                    continue;
                }
                $updateFields[$key] = $value;
            }

            $this->database->update($this->tableName)
                ->fields($updateFields)
                ->condition($this->idKey, $entity->id())
                ->execute();

            $result = EntityConstants::SAVED_UPDATED;
        }

        // Dispatch POST_SAVE event.
        $this->eventDispatcher->dispatch(
            new EntityEvent($entity),
            EntityEvents::POST_SAVE->value,
        );

        return $result;
    }

    /**
     * @param EntityInterface[] $entities
     */
    public function delete(array $entities): void
    {
        if (empty($entities)) {
            return;
        }

        // Dispatch PRE_DELETE events.
        foreach ($entities as $entity) {
            $this->eventDispatcher->dispatch(
                new EntityEvent($entity),
                EntityEvents::PRE_DELETE->value,
            );
        }

        // Collect IDs for deletion.
        $ids = [];
        foreach ($entities as $entity) {
            $id = $entity->id();
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        if (!empty($ids)) {
            $this->database->delete($this->tableName)
                ->condition($this->idKey, $ids, 'IN')
                ->execute();
        }

        // Dispatch POST_DELETE events.
        foreach ($entities as $entity) {
            $this->eventDispatcher->dispatch(
                new EntityEvent($entity),
                EntityEvents::POST_DELETE->value,
            );
        }
    }

    public function getQuery(): EntityQueryInterface
    {
        return new SqlEntityQuery($this->entityType, $this->database);
    }

    public function getEntityTypeId(): string
    {
        return $this->entityType->id();
    }

    /**
     * Maps a database row to an entity object.
     *
     * @param array<string, mixed> $row
     */
    private function mapRowToEntity(array $row): EntityInterface
    {
        $class = $this->entityType->getClass();

        // Cast the ID to int if it is numeric.
        if (isset($row[$this->idKey]) && is_numeric($row[$this->idKey])) {
            $row[$this->idKey] = (int) $row[$this->idKey];
        }

        /** @var EntityInterface $entity */
        $entity = new $class(
            values: $row,
            entityTypeId: $this->entityType->id(),
            entityKeys: $this->entityKeys,
        );

        // Loaded entities are not new.
        if (method_exists($entity, 'enforceIsNew')) {
            $entity->enforceIsNew(false);
        }

        return $entity;
    }
}

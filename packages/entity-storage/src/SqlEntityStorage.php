<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityConstants;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\Event\DefaultEntityEventFactory;
use Waaseyaa\Entity\Event\EntityEventFactoryInterface;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * SQL-based entity storage implementation.
 *
 * Stores entities in SQL tables using the waaseyaa/database-legacy package.
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

    /** @var array<string, string> */
    private readonly array $entityKeys;

    /** @var array<string, bool> Column existence cache (column name => exists in table). */
    private array $columnCache = [];

    private readonly LoggerInterface $logger;

    private readonly EntityEventFactoryInterface $eventFactory;

    public function __construct(
        private readonly EntityTypeInterface $entityType,
        private readonly DatabaseInterface $database,
        private readonly EventDispatcherInterface $eventDispatcher,
        ?LoggerInterface $logger = null,
        ?EntityEventFactoryInterface $eventFactory = null,
    ) {
        $this->tableName = $this->entityType->id();
        $keys = $this->entityType->getKeys();
        $this->idKey = $keys['id'] ?? 'id';
        $this->entityKeys = $keys;
        $this->logger = $logger ?? new NullLogger();
        $this->eventFactory = $eventFactory ?? new DefaultEntityEventFactory();
    }

    public function create(array $values = []): EntityInterface
    {
        $class = $this->entityType->getClass();
        $entity = $this->instantiateEntity($class, $values);

        if (method_exists($entity, 'enforceIsNew')) {
            $entity->enforceIsNew();
        }

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

        // Auto-populate timestamp fields.
        $this->populateTimestamps($entity, $isNew);

        $values = $entity->toArray();

        // Split values into schema columns and extra data.
        $dbValues = $this->splitForStorage($values);

        // Dispatch PRE_SAVE event.
        $this->eventDispatcher->dispatch(
            $this->eventFactory->create($entity),
            EntityEvents::PRE_SAVE->value,
        );

        if ($isNew) {
            // Remove id key if null (auto-increment will handle it).
            $insertValues = [];
            foreach ($dbValues as $key => $value) {
                if ($key === $this->idKey && $value === null) {
                    continue;
                }
                $insertValues[$key] = $value;
            }

            // Config entities (no uuid key) must have an explicit non-empty ID.
            if (!isset($this->entityKeys['uuid']) && (!isset($insertValues[$this->idKey]) || $insertValues[$this->idKey] === '')) {
                throw new \InvalidArgumentException(sprintf(
                    'Config entity "%s" requires a non-empty string ID in the "%s" field.',
                    $this->entityType->id(),
                    $this->idKey,
                ));
            }

            $id = $this->database->insert($this->tableName)
                ->fields(array_keys($insertValues))
                ->values($insertValues)
                ->execute();

            // Set the auto-generated ID only when ID was not in the insert
            // (auto-increment). Config entities and entities with pre-set IDs
            // already have their ID and should not be overwritten.
            if (!isset($insertValues[$this->idKey]) && method_exists($entity, 'set')) {
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
            foreach ($dbValues as $key => $value) {
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
            $this->eventFactory->create($entity),
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
                $this->eventFactory->create($entity),
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
                $this->eventFactory->create($entity),
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

        // Merge extra data from the _data JSON column back into values.
        if (isset($row['_data'])) {
            try {
                $extra = json_decode((string) $row['_data'], associative: true, flags: \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->logger->warning(sprintf('Corrupt _data JSON for %s entity %s: %s', $this->tableName, $row[$this->idKey] ?? '?', $e->getMessage()));
                $extra = [];
            }
            unset($row['_data']);
            $row = array_merge($row, $extra);
        }

        /** @var EntityInterface $entity */
        $entity = $this->instantiateEntity($class, $row);

        // Loaded entities are not new.
        if (method_exists($entity, 'enforceIsNew')) {
            $entity->enforceIsNew(false);
        }

        return $entity;
    }

    /**
     * Instantiate an entity, adapting to its constructor signature.
     *
     * Entity subclasses like User and Node define their own constructors
     * that only accept $values and hardcode entityTypeId/entityKeys.
     * This method detects the constructor shape and passes only what
     * the class accepts.
     *
     * @param class-string $class
     * @param array<string, mixed> $values
     */
    private function instantiateEntity(string $class, array $values): EntityInterface
    {
        $ref = new \ReflectionClass($class);
        $constructor = $ref->getConstructor();
        $hasEntityTypeId = false;

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $param) {
                if ($param->getName() === 'entityTypeId') {
                    $hasEntityTypeId = true;
                    break;
                }
            }
        }

        if ($hasEntityTypeId) {
            return new $class(
                values: $values,
                entityTypeId: $this->entityType->id(),
                entityKeys: $this->entityKeys,
            );
        }

        return new $class(values: $values);
    }

    /**
     * Split entity values into schema columns + JSON _data blob.
     *
     * Values whose keys match actual table columns are stored directly.
     * All other values are JSON-encoded into the _data column.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function splitForStorage(array $values): array
    {
        $schema = $this->database->schema();
        $dbValues = [];
        $extraData = [];

        foreach ($values as $key => $value) {
            if ($key === '_data') {
                continue;
            }
            if ($this->columnExists($key, $schema)) {
                $dbValues[$key] = $value;
            } else {
                $extraData[$key] = $value;
            }
        }

        $dbValues['_data'] = json_encode($extraData, \JSON_THROW_ON_ERROR);

        return $dbValues;
    }

    /**
     * Auto-populate timestamp fields on save.
     *
     * Sets `created` to current time on new entities (if not already set).
     * Always updates `changed` to current time.
     */
    private function populateTimestamps(EntityInterface $entity, bool $isNew): void
    {
        $fieldDefs = $this->entityType->getFieldDefinitions();
        $now = time();

        foreach ($fieldDefs as $fieldName => $def) {
            if (($def['type'] ?? null) !== 'timestamp') {
                continue;
            }

            if ($fieldName === 'created' && $isNew && (int) ($entity->get('created') ?? 0) === 0) {
                $entity->set('created', $now);
            } elseif ($fieldName === 'changed') {
                $entity->set('changed', $now);
            }
        }
    }

    /**
     * Check if a column exists in the entity table (with caching).
     */
    private function columnExists(string $column, \Waaseyaa\Database\SchemaInterface $schema): bool
    {
        if (!isset($this->columnCache[$column])) {
            $this->columnCache[$column] = $schema->fieldExists($this->tableName, $column);
        }
        return $this->columnCache[$column];
    }
}

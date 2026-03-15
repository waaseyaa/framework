<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Entity\EntityConstants;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\Driver\EntityStorageDriverInterface;

/**
 * Entity repository implementation.
 *
 * High-level layer that handles entity hydration, event dispatch,
 * and language fallback. Delegates raw I/O to a storage driver.
 */
final class EntityRepository implements EntityRepositoryInterface
{
    /** @var string[] Default language fallback chain. */
    private array $fallbackChain = ['en'];

    public function __construct(
        private readonly EntityTypeInterface $entityType,
        private readonly EntityStorageDriverInterface $driver,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * Set the language fallback chain.
     *
     * @param string[] $chain Language codes in priority order.
     */
    public function setFallbackChain(array $chain): void
    {
        $this->fallbackChain = $chain;
    }

    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface
    {
        $entityTypeId = $this->entityType->id();

        if ($langcode !== null && $fallback) {
            // Try the requested language first, then each fallback language.
            $languagesToTry = array_unique(array_merge([$langcode], $this->fallbackChain));

            foreach ($languagesToTry as $tryLang) {
                $row = $this->driver->read($entityTypeId, $id, $tryLang);
                if ($row !== null) {
                    return $this->hydrate($row);
                }
            }

            // Final fallback: try without language.
            $row = $this->driver->read($entityTypeId, $id);
            return $row !== null ? $this->hydrate($row) : null;
        }

        $row = $this->driver->read($entityTypeId, $id, $langcode);

        if ($row === null) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array
    {
        $entityTypeId = $this->entityType->id();
        $rows = $this->driver->findBy($entityTypeId, $criteria, $orderBy, $limit);

        $entities = [];
        foreach ($rows as $row) {
            $entities[] = $this->hydrate($row);
        }

        return $entities;
    }

    public function save(EntityInterface $entity): int
    {
        $isNew = $entity->isNew();
        $entityTypeId = $this->entityType->id();

        // Dispatch PRE_SAVE event.
        $this->eventDispatcher->dispatch(
            new EntityEvent($entity),
            EntityEvents::PRE_SAVE->value,
        );

        $values = $entity->toArray();
        $id = (string) ($entity->id() ?? '');

        $this->driver->write($entityTypeId, $id, $values);

        // Mark entity as no longer new.
        if ($isNew && method_exists($entity, 'enforceIsNew')) {
            $entity->enforceIsNew(false);
        }

        $result = $isNew ? EntityConstants::SAVED_NEW : EntityConstants::SAVED_UPDATED;

        // Dispatch POST_SAVE event.
        $this->eventDispatcher->dispatch(
            new EntityEvent($entity),
            EntityEvents::POST_SAVE->value,
        );

        return $result;
    }

    public function delete(EntityInterface $entity): void
    {
        $entityTypeId = $this->entityType->id();
        $id = (string) $entity->id();

        // Dispatch PRE_DELETE event.
        $this->eventDispatcher->dispatch(
            new EntityEvent($entity),
            EntityEvents::PRE_DELETE->value,
        );

        $this->driver->remove($entityTypeId, $id);

        // Dispatch POST_DELETE event.
        $this->eventDispatcher->dispatch(
            new EntityEvent($entity),
            EntityEvents::POST_DELETE->value,
        );
    }

    public function exists(string $id): bool
    {
        return $this->driver->exists($this->entityType->id(), $id);
    }

    public function count(array $criteria = []): int
    {
        return $this->driver->count($this->entityType->id(), $criteria);
    }

    /**
     * Hydrate a raw row into an entity object.
     *
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): EntityInterface
    {
        $class = $this->entityType->getClass();
        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';

        // Cast the ID to int if it is numeric.
        if (isset($row[$idKey]) && is_numeric($row[$idKey])) {
            $row[$idKey] = (int) $row[$idKey];
        }

        // Merge extra data from the _data JSON column back into values.
        if (isset($row['_data'])) {
            $extra = json_decode((string) $row['_data'], associative: true) ?: [];
            unset($row['_data']);
            $row = array_merge($row, $extra);
        }

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

        $keys = $this->entityType->getKeys();

        if ($hasEntityTypeId) {
            return new $class(
                values: $values,
                entityTypeId: $this->entityType->id(),
                entityKeys: $keys,
            );
        }

        return new $class(values: $values);
    }
}

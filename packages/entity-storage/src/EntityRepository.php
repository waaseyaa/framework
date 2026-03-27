<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityConstants;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\Event\DefaultEntityEventFactory;
use Waaseyaa\Entity\Event\EntityEventFactoryInterface;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\Validation\EntityValidationException;
use Waaseyaa\Entity\Validation\EntityValidator;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\EntityStorage\Driver\EntityStorageDriverInterface;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;

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

    private readonly EntityEventFactoryInterface $eventFactory;

    public function __construct(
        private readonly EntityTypeInterface $entityType,
        private readonly EntityStorageDriverInterface $driver,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ?RevisionableStorageDriver $revisionDriver = null,
        private readonly ?DatabaseInterface $database = null,
        ?EntityEventFactoryInterface $eventFactory = null,
        private readonly ?EntityValidator $validator = null,
    ) {
        $this->eventFactory = $eventFactory ?? new DefaultEntityEventFactory();
    }

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

    public function save(EntityInterface $entity, bool $validate = true): int
    {
        return $this->doSave($entity, validate: $validate);
    }

    public function delete(EntityInterface $entity): void
    {
        $this->doDelete($entity);
    }

    public function saveMany(array $entities, bool $validate = true): array
    {
        if ($entities === []) {
            return [];
        }

        if ($this->database === null) {
            throw new \LogicException('saveMany() requires a database connection for transaction support.');
        }

        $unitOfWork = new UnitOfWork($this->database, $this->eventDispatcher);

        return $unitOfWork->transaction(function () use ($entities, $validate, $unitOfWork): array {
            $results = [];
            foreach ($entities as $entity) {
                $results[] = $this->doSave($entity, $unitOfWork, $validate);
            }

            return $results;
        });
    }

    public function deleteMany(array $entities): int
    {
        if ($entities === []) {
            return 0;
        }

        if ($this->database === null) {
            throw new \LogicException('deleteMany() requires a database connection for transaction support.');
        }

        $unitOfWork = new UnitOfWork($this->database, $this->eventDispatcher);

        return $unitOfWork->transaction(function () use ($entities, $unitOfWork): int {
            foreach ($entities as $entity) {
                $this->doDelete($entity, $unitOfWork);
            }

            return count($entities);
        });
    }

    private function doSave(EntityInterface $entity, ?UnitOfWork $unitOfWork = null, bool $validate = true): int
    {
        $isNew = $entity->isNew();
        $entityTypeId = $this->entityType->id();

        $originalEntity = null;
        if (!$isNew) {
            $id = (string) $entity->id();
            $originalEntity = $this->find($id);
        }

        if ($validate && $this->validator !== null) {
            $constraints = $this->entityType->getConstraints();
            if ($constraints !== []) {
                $violations = $this->validator->validate($entity, $constraints);
                if ($violations->count() > 0) {
                    throw new EntityValidationException($violations);
                }
            }
        }

        if ($entity instanceof EntityBase) {
            $entity->preSave($isNew);
        }

        $this->dispatchEvent(
            $this->eventFactory->create($entity, $originalEntity),
            EntityEvents::PRE_SAVE->value,
            $unitOfWork,
        );

        $values = $entity->toArray();
        $id = (string) ($entity->id() ?? '');
        $createRevision = $this->shouldCreateRevision($entity, $isNew);

        // Wrap revision + base table writes in a transaction (invariant #4).
        // Skip if already inside a UnitOfWork transaction.
        $transaction = ($unitOfWork === null) ? $this->database?->transaction() : null;
        try {
            if ($createRevision && $this->revisionDriver !== null) {
                $log = ($entity instanceof RevisionableInterface) ? $entity->getRevisionLog() : null;
                $revisionId = $this->revisionDriver->writeRevision($id, $values, $log);
                $values['revision_id'] = $revisionId;
                if ($entity instanceof ContentEntityInterface) {
                    $revisionKey = $this->entityType->getKeys()['revision'] ?? 'revision_id';
                    $entity->set($revisionKey, $revisionId);
                }
            } elseif (!$createRevision && !$isNew && $this->revisionDriver !== null && $entity instanceof RevisionableInterface) {
                $currentRevisionId = $entity->getRevisionId();
                if ($currentRevisionId !== null) {
                    $this->revisionDriver->updateRevision($id, $currentRevisionId, $values);
                }
            }

            $this->driver->write($entityTypeId, $id, $values);
            $transaction?->commit();
        } catch (\Throwable $e) {
            $transaction?->rollBack();
            throw $e;
        }

        if ($isNew && method_exists($entity, 'enforceIsNew')) {
            $entity->enforceIsNew(false);
        }

        $result = $isNew ? EntityConstants::SAVED_NEW : EntityConstants::SAVED_UPDATED;

        $this->dispatchEvent(
            $this->eventFactory->create($entity, $originalEntity),
            EntityEvents::POST_SAVE->value,
            $unitOfWork,
        );

        if ($createRevision && $this->revisionDriver !== null) {
            $this->dispatchEvent(
                $this->eventFactory->create($entity),
                EntityEvents::REVISION_CREATED->value,
                $unitOfWork,
            );
        }

        if ($entity instanceof EntityBase) {
            $entity->postSave($isNew);
        }

        return $result;
    }

    private function doDelete(EntityInterface $entity, ?UnitOfWork $unitOfWork = null): void
    {
        $entityTypeId = $this->entityType->id();
        $id = (string) $entity->id();

        if ($entity instanceof EntityBase) {
            $entity->preDelete();
        }

        $this->dispatchEvent(
            $this->eventFactory->create($entity),
            EntityEvents::PRE_DELETE->value,
            $unitOfWork,
        );

        if ($this->revisionDriver !== null && $this->entityType->isRevisionable()) {
            $this->revisionDriver->deleteAllRevisions($id);
        }

        $this->driver->remove($entityTypeId, $id);

        $this->dispatchEvent(
            $this->eventFactory->create($entity),
            EntityEvents::POST_DELETE->value,
            $unitOfWork,
        );

        if ($entity instanceof EntityBase) {
            $entity->postDelete();
        }
    }

    private function dispatchEvent(object $event, string $eventName, ?UnitOfWork $unitOfWork = null): void
    {
        if ($unitOfWork !== null) {
            $unitOfWork->bufferEvent($event, $eventName);
        } else {
            $this->eventDispatcher->dispatch($event, $eventName);
        }
    }

    public function exists(string $id): bool
    {
        return $this->driver->exists($this->entityType->id(), $id);
    }

    public function count(array $criteria = []): int
    {
        return $this->driver->count($this->entityType->id(), $criteria);
    }

    public function loadRevision(string $entityId, int $revisionId): ?EntityInterface
    {
        if ($this->revisionDriver === null) {
            throw new \LogicException('Revision driver not configured for entity type ' . $this->entityType->id());
        }

        $row = $this->revisionDriver->readRevision($entityId, $revisionId);
        if ($row === null) {
            return null;
        }

        // Inject the entity ID back (revision table uses entity_id, not the id key).
        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';
        $row[$idKey] = $row['entity_id'];

        // Determine if this revision is the current default.
        $baseRow = $this->driver->read($this->entityType->id(), $entityId);
        $currentRevId = $baseRow !== null ? (int) ($baseRow['revision_id'] ?? 0) : 0;
        $latestRevId = $this->revisionDriver->getLatestRevisionId($entityId);
        $row['is_default_revision'] = ($revisionId === $currentRevId);
        $row['is_latest_revision'] = ($revisionId === $latestRevId);

        return $this->hydrate($row);
    }

    public function rollback(string $entityId, int $targetRevisionId): EntityInterface
    {
        if ($this->revisionDriver === null) {
            throw new \LogicException('Revision driver not configured for entity type ' . $this->entityType->id());
        }

        // Load the target revision.
        $targetRow = $this->revisionDriver->readRevision($entityId, $targetRevisionId);
        if ($targetRow === null) {
            throw new \InvalidArgumentException(
                "Revision {$targetRevisionId} does not exist for entity {$entityId}.",
            );
        }

        // Remove revision metadata from the row — we're creating a new revision.
        unset($targetRow['revision_id'], $targetRow['revision_created'], $targetRow['revision_log'], $targetRow['entity_id']);

        // Wrap in transaction (invariant #4: atomic pointer update).
        $transaction = $this->database?->transaction();
        try {
            $log = "Reverted to revision {$targetRevisionId}";
            $newRevisionId = $this->revisionDriver->writeRevision($entityId, $targetRow, $log);

            // Update the base table pointer.
            $keys = $this->entityType->getKeys();
            $idKey = $keys['id'] ?? 'id';
            $targetRow[$idKey] = $entityId;
            $targetRow['revision_id'] = $newRevisionId;
            $this->driver->write($this->entityType->id(), $entityId, $targetRow);

            $transaction?->commit();
        } catch (\Throwable $e) {
            $transaction?->rollBack();
            throw $e;
        }

        // Load the new entity via loadRevision to include revision metadata.
        $entity = $this->loadRevision($entityId, $newRevisionId);

        $this->dispatchEvent(
            $this->eventFactory->create($entity),
            EntityEvents::REVISION_CREATED->value,
        );
        $this->dispatchEvent(
            $this->eventFactory->create($entity),
            EntityEvents::REVISION_REVERTED->value,
        );

        return $entity;
    }

    /**
     * Determine if a new revision should be created for this save.
     */
    private function shouldCreateRevision(EntityInterface $entity, bool $isNew): bool
    {
        if (!$this->entityType->isRevisionable()) {
            // Invariant #9: type gating.
            if ($entity instanceof RevisionableInterface && $entity->isNewRevision() === true) {
                throw new \LogicException(
                    'Cannot create revision for non-revisionable entity type ' . $this->entityType->id(),
                );
            }
            return false;
        }

        // First save always creates revision 1.
        if ($isNew) {
            return true;
        }

        // Caller override takes precedence.
        if ($entity instanceof RevisionableInterface) {
            $override = $entity->isNewRevision();
            if ($override !== null) {
                return $override;
            }
        }

        // Fall back to entity type default.
        return $this->entityType->getRevisionDefault();
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
            try {
                $extra = json_decode((string) $row['_data'], associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $extra = [];
            }
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

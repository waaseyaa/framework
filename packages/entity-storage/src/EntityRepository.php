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
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\Entity\TranslatableInterface;
use Waaseyaa\Entity\Validation\EntityTypeValidationConstraints;
use Waaseyaa\Entity\Validation\EntityValidationException;
use Waaseyaa\Entity\Validation\EntityValidator;
use Waaseyaa\EntityStorage\Bundle\BundleSubtableGateway;
use Waaseyaa\EntityStorage\Driver\EntityStorageDriverInterface;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Event\AbortOperationException;
use Waaseyaa\EntityStorage\Event\AfterSaveEvent;
use Waaseyaa\EntityStorage\Event\BeforeSaveEvent;
use Waaseyaa\I18n\LanguageManagerInterface;

/**
 * Entity repository implementation.
 *
 * High-level layer that handles entity hydration, event dispatch,
 * and language fallback. Delegates raw I/O to a storage driver.
 * @api
 */
final class EntityRepository implements EntityRepositoryInterface
{
    /** @var string[] Default language fallback chain. */
    private array $fallbackChain = ['en'];

    private readonly EntityEventFactoryInterface $eventFactory;

    private readonly \Waaseyaa\Foundation\Log\LoggerInterface $logger;

    /** Lazily-resolved bundle subtable gateway; false until first resolution. */
    private BundleSubtableGateway|false|null $bundleGatewayInstance = false;

    public function __construct(
        private readonly EntityTypeInterface $entityType,
        private readonly EntityStorageDriverInterface $driver,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ?RevisionableStorageDriver $revisionDriver = null,
        private readonly ?DatabaseInterface $database = null,
        ?EntityEventFactoryInterface $eventFactory = null,
        private readonly ?EntityValidator $validator = null,
        // WP02 coordinator slot — reserved for field-level multi-backend fan-out.
        // Null until WP10 activates per-field routing; WP04 wires lifecycle events.
        // Must remain the last parameter to avoid breaking existing call sites.
        private readonly ?EntityStorageCoordinator $coordinator = null,
        // M-006 WP10 — optional language manager wire-up (C-004 optional DI).
        // When supplied AND $readActiveLanguage is true, find() walks the
        // active-language → defaultLangcode handoff after hydrating the
        // default-language entity. Absence yields default-langcode reads
        // always (CLI / queue / non-HTTP contexts).
        private readonly ?LanguageManagerInterface $languageManager = null,
        private readonly bool $readActiveLanguage = false,
        // Optional field registry so validation resolves a content type's
        // bundle-scoped field definitions (and their constraints), not just the
        // entity-type base fields. Null in bare bootstraps falls back to the
        // class-declared fields.
        private readonly ?FieldDefinitionRegistryInterface $fieldRegistry = null,
        ?\Waaseyaa\Foundation\Log\LoggerInterface $logger = null,
    ) {
        $this->eventFactory = $eventFactory ?? new DefaultEntityEventFactory();
        $this->logger = $logger ?? new \Waaseyaa\Foundation\Log\NullLogger();
    }

    /**
     * Lazily resolve the bundle subtable gateway for this entity type, or null
     * when bundle persistence does not apply (no database, no registry, or no
     * bundle fields registered for the type).
     */
    private function bundleGateway(): ?BundleSubtableGateway
    {
        if ($this->bundleGatewayInstance !== false) {
            return $this->bundleGatewayInstance;
        }

        if ($this->database === null || $this->fieldRegistry === null) {
            return $this->bundleGatewayInstance = null;
        }

        $gateway = new BundleSubtableGateway(
            $this->database,
            $this->fieldRegistry,
            $this->entityType,
            $this->logger,
        );

        return $this->bundleGatewayInstance = $gateway->hasBundleFields() ? $gateway : null;
    }

    /**
     * Return the coordinator for field-level multi-backend fan-out, if configured.
     *
     * @api
     * @internal Exposed for WP04/WP10 integration; callers outside entity-storage
     *   should not rely on this method — use the repository's high-level CRUD API.
     */
    public function getCoordinator(): ?EntityStorageCoordinator
    {
        return $this->coordinator;
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

        $entity = $this->hydrate($row);

        // M-006 WP10 — LanguageManager handoff (FR-040, C-004).
        //
        // When a language manager is wired AND opt-in is enabled AND the
        // caller did not pin an explicit $langcode, swap the active
        // translation to the LanguageManager's current language whenever the
        // entity carries a translation for it. We materialise the full
        // translation map via `findTranslations()` so the in-memory entity
        // knows which langcodes are available. Default-language reads (and
        // all opt-out paths) skip this branch so CLI / queue / non-HTTP
        // contexts remain deterministic.
        if (
            $langcode === null
            && $this->readActiveLanguage
            && $this->languageManager !== null
            && $entity instanceof TranslatableInterface
            && $this->entityType->isTranslatable()
        ) {
            $active = $this->languageManager->getCurrentLanguage()->id;
            $defaultLc = $entity->defaultLangcode();
            if ($active !== $defaultLc) {
                $allTranslations = $this->findTranslations($entity);
                if (isset($allTranslations[$active])) {
                    return $allTranslations[$active];
                }
            }
        }

        return $entity;
    }

    public function findMany(array $ids, ?string $langcode = null, bool $fallback = false): array
    {
        if ($ids === []) {
            return [];
        }

        $entityTypeId = $this->entityType->id();
        $orderedKeys = [];
        foreach ($ids as $id) {
            $sid = (string) $id;
            if ($sid === '') {
                continue;
            }
            if (!in_array($sid, $orderedKeys, true)) {
                $orderedKeys[] = $sid;
            }
        }

        if ($orderedKeys === []) {
            return [];
        }

        if ($langcode !== null && $fallback) {
            $entities = [];
            foreach ($orderedKeys as $id) {
                $entity = $this->find($id, $langcode, true);
                if ($entity !== null) {
                    $entities[] = $entity;
                }
            }

            return $entities;
        }

        $rowsById = $this->driver->readMultiple($entityTypeId, $orderedKeys, $langcode);
        $entities = [];
        foreach ($orderedKeys as $id) {
            $row = $rowsById[$id] ?? null;
            if ($row !== null) {
                $entities[] = $this->hydrate($row);
            }
        }

        return $entities;
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

    /**
     * Save (insert or update) an entity.
     *
     * Dispatches {@see BeforeSaveEvent} before the write and
     * {@see AfterSaveEvent} after the write succeeds. The optional
     * {@see SaveContext} parameter lets callers thread per-save flags
     * (e.g. `SaveContext::asImport()` for migration platform writes —
     * FR-022, GitHub #1449) through to subscribers without a second
     * dispatch site.
     *
     * @param EntityInterface $entity   The entity to save.
     * @param bool            $validate Whether to run pre-save validation.
     * @param ?SaveContext    $context  Optional per-save context. `null`
     *     yields {@see SaveContext::default()} — preserves pre-#1449 behaviour.
     *
     * @return int SAVED_NEW or SAVED_UPDATED (see {@see EntityConstants}).
     *
     * @throws \Waaseyaa\Entity\Validation\EntityValidationException If validation fails.
     * @throws AbortOperationException If a BeforeSaveEvent subscriber aborts.
     */
    public function save(EntityInterface $entity, bool $validate = true, ?SaveContext $context = null): int
    {
        return $this->doSave($entity, validate: $validate, saveContext: $context);
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

    /**
     * Resolve the field definitions used for validation: the entity type's
     * class-declared base fields, plus registry core fields, plus the saved
     * entity's bundle fields. Mirrors {@see \Waaseyaa\Entity\EntityTypeManager::resolveFieldDefinitions()}
     * but is reachable from the storage layer (which holds the registry, not the
     * manager). Falls back to class fields when no registry is wired.
     *
     * @return array<string, \Waaseyaa\Field\FieldDefinitionInterface>
     */
    private function resolveValidationFieldDefinitions(EntityInterface $entity): array
    {
        $fields = $this->entityType->getFieldDefinitions();
        if ($this->fieldRegistry === null) {
            return $fields;
        }

        $entityTypeId = $this->entityType->id();
        foreach ($this->fieldRegistry->coreFieldsFor($entityTypeId) as $name => $definition) {
            $fields[$name] = $definition;
        }

        $bundle = $entity->bundle();
        if ($bundle !== '' && $bundle !== $entityTypeId) {
            foreach ($this->fieldRegistry->bundleFieldsFor($entityTypeId, $bundle) as $name => $definition) {
                $fields[$name] = $definition;
            }
        }

        return $fields;
    }

    private function doSave(
        EntityInterface $entity,
        ?UnitOfWork $unitOfWork = null,
        bool $validate = true,
        ?SaveContext $saveContext = null,
    ): int {
        $isNew = $entity->isNew();
        $entityTypeId = $this->entityType->id();
        $resolvedContext = $saveContext ?? SaveContext::default();

        if ($validate && $this->validator !== null) {
            $constraints = EntityTypeValidationConstraints::forEntityType(
                $this->entityType,
                $this->resolveValidationFieldDefinitions($entity),
            );
            if ($constraints !== []) {
                $violations = $this->validator->validate($entity, $constraints);
                if ($violations->count() > 0) {
                    throw new EntityValidationException($violations);
                }
            }
        }

        $originalEntity = null;
        if (!$isNew) {
            $id = (string) $entity->id();
            $originalEntity = $this->find($id);
        }

        if ($entity instanceof EntityBase) {
            $entity->preSave($isNew);
        }

        $this->dispatchEvent(
            $this->eventFactory->create($entity, $originalEntity),
            EntityEvents::PRE_SAVE->value,
            $unitOfWork,
        );

        $createRevision = $this->shouldCreateRevision($entity, $isNew);

        // GitHub #1449: Coordinator-level lifecycle event. The repository is
        // now the single dispatch site for BeforeSaveEvent / AfterSaveEvent;
        // callers (e.g. `\Waaseyaa\Migration\Plugin\Destination\EntityDestination`)
        // no longer self-dispatch. Subscribers may abort via
        // AbortOperationException; no write occurs and AfterSaveEvent does
        // NOT fire.
        $this->dispatchEvent(
            new BeforeSaveEvent($entity, $resolvedContext, $createRevision),
            BeforeSaveEvent::class,
            $unitOfWork,
        );

        $values = $entity->toArray();
        $id = (string) ($entity->id() ?? '');

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

            // Bundle-aware write: pull this content type's column-stored bundle
            // fields out of the base row so they persist in the per-bundle
            // subtable (real typed columns) instead of the base `_data` blob.
            // FieldStorage::Data bundle fields stay in the base row. If the
            // subtable is somehow absent, fold the values back into the base row
            // (never a silent drop) and log.
            $baseValues = $values;
            $bundleValues = [];
            $bundleName = null;
            $gateway = $this->bundleGateway();
            if ($gateway !== null) {
                [$baseValues, $bundleValues, $bundleName] = $gateway->partition($entity, $values);
                if ($bundleValues !== [] && $bundleName !== null && !$gateway->subtableExists($bundleName)) {
                    $gateway->logMissingSubtableOnSave($bundleName, \count($bundleValues));
                    $baseValues = $values;
                    $bundleValues = [];
                    $bundleName = null;
                }
            }

            $writtenId = $this->driver->write($entityTypeId, $id, $baseValues);

            if ($gateway !== null && $bundleValues !== [] && $bundleName !== null) {
                $persistId = ($id !== '') ? $id : $writtenId;
                $gateway->upsert($bundleName, $persistId, $bundleValues);
            }

            $transaction?->commit();
        } catch (\Throwable $e) {
            $transaction?->rollBack();
            throw $e;
        }

        // Back-fill auto-assigned ids so POST_SAVE subscribers see the real pk.
        if ($isNew && $id === '' && $writtenId !== '') {
            $idKey = $this->entityType->getKeys()['id'] ?? 'id';
            $entity->set($idKey, $writtenId);
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
                $this->eventFactory->create($entity, $originalEntity),
                EntityEvents::REVISION_CREATED->value,
                $unitOfWork,
            );
        }

        // GitHub #1449: AfterSaveEvent fires after all writes succeed.
        // Mirrors EntityStorageCoordinator behaviour: AfterSaveEvent does
        // NOT fire when the transaction rolls back (the throw above exits
        // before this point).
        $this->dispatchEvent(
            new AfterSaveEvent($entity, $resolvedContext, $createRevision),
            AfterSaveEvent::class,
            $unitOfWork,
        );

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
            $this->eventFactory->create($entity, $entity),
            EntityEvents::PRE_DELETE->value,
            $unitOfWork,
        );

        if ($this->revisionDriver !== null && $this->entityType->isRevisionable()) {
            $this->revisionDriver->deleteAllRevisions($id);
        }

        $this->driver->remove($entityTypeId, $id);

        $this->dispatchEvent(
            $this->eventFactory->create($entity, $entity),
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
     * Load every translation of $entity in a single driver round-trip (FR-041, NFR-005).
     *
     * Non-translatable types short-circuit to an empty array — the driver is
     * not consulted (no wasted query). Translatable types dispatch to the
     * driver, then materialise one entity per langcode. The translation-data
     * map is built once and shared across every returned instance: each entity
     * receives the same map via `_setTranslationData()` so PHP copy-on-write
     * keeps the payload single-copy in memory until a caller mutates it
     * (NFR-003).
     */
    public function findTranslations(EntityInterface $entity): array
    {
        if (!$this->entityType->isTranslatable()) {
            return [];
        }
        if (!$entity instanceof TranslatableInterface) {
            return [];
        }

        $id = (string) ($entity->id() ?? '');
        if ($id === '') {
            return [];
        }

        $defaultLc = $entity->defaultLangcode();
        $rows = $this->driver->findTranslations($this->entityType->id(), $id, $defaultLc);

        if ($rows === []) {
            return [];
        }

        // Build the shared translation-data map (langcode → field values).
        // Copy-on-write keeps it single-copy until a caller mutates it.
        $translationData = $rows;

        $result = [];
        foreach ($rows as $lc => $row) {
            $instance = $this->hydrate($row);
            if ($instance instanceof TranslatableInterface
                && \method_exists($instance, '_setTranslationData')
            ) {
                $instance->_setTranslationData($translationData, $defaultLc);
                // Stamp the active langcode per row. _setTranslationData clears
                // activeLangcode to null (so it falls back to defaultLangcode);
                // we restore the per-row langcode via getTranslation() which
                // returns a clone with the active langcode set.
                if ($lc !== $defaultLc && $instance->hasTranslation($lc)) {
                    $instance = $instance->getTranslation($lc);
                }
            }
            $result[$lc] = $instance;
        }

        return $result;
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

        // Bundle-aware read: merge the per-bundle subtable columns (e.g. page's
        // body/blocks) back onto the row so the loaded entity carries its full
        // content-type field set.
        $gateway = $this->bundleGateway();
        if ($gateway !== null) {
            $bundleKey = $keys['bundle'] ?? null;
            $bundle = $bundleKey !== null ? ($row[$bundleKey] ?? null) : null;
            $rowId = $row[$idKey] ?? null;
            if (\is_string($bundle) && $bundle !== '' && $rowId !== null && $gateway->subtableExists($bundle)) {
                foreach ($gateway->read($bundle, $rowId) as $bundleFieldName => $bundleFieldValue) {
                    if ($bundleFieldName !== $idKey) {
                        $row[$bundleFieldName] = $bundleFieldValue;
                    }
                }
            }
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
        return new Hydration\EntityInstantiator($this->entityType)->instantiate($class, $values);
    }
}

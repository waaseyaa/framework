<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\DateTime\EntityClockInterface;
use Waaseyaa\Entity\DateTime\TimestampFieldConvention;
use Waaseyaa\Entity\DateTime\UtcEntityClock;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityConstants;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\Event\DefaultEntityEventFactory;
use Waaseyaa\Entity\Event\EntityEventFactoryInterface;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\TranslatableInterface;
use Waaseyaa\EntityStorage\Backend\ReservedBackendIds;
use Waaseyaa\EntityStorage\Bundle\BundleSubtableGateway;
use Waaseyaa\EntityStorage\Hydration\SqlColumnTranslationHydrator;
use Waaseyaa\EntityStorage\Schema\TranslationSchemaHandler;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * SQL-backed entity storage driver.
 *
 * Persists entities through the {@see DatabaseInterface} query builder
 * (waaseyaa/database-legacy) and dispatches the entity lifecycle events on
 * {@see EntityEvents}. Implements the full {@see EntityStorageInterface} CRUD
 * surface: create, load, loadByKey, loadMultiple, save, delete and getQuery.
 *
 * Schema model:
 * - A flat base table keyed by the entity's id key. Core fields map to real
 *   columns; the remaining field values are folded into a single JSON `_data`
 *   blob (see {@see splitForStorage()}).
 * - Optional per-bundle subtables, merged into the loaded row on read via the
 *   {@see BundleSubtableGateway} (see {@see mergeBundleSubtableRow()}).
 *
 * Translation (two strategies, selected per entity type):
 * - Default "sql-blob" strategy keeps per-langcode field values inside the base
 *   row's `_data` blob via {@see TranslationSchemaHandler}.
 * - Optional "sql-column" strategy stores translations in a dedicated
 *   translation table with real columns (see {@see saveSqlColumnTranslatable()}
 *   / {@see loadSqlColumnTranslatable()}), hydrated by
 *   {@see SqlColumnTranslationHydrator}.
 *
 * Cross-cutting: created/changed timestamps are populated through the injected
 * {@see EntityClockInterface}; reads run through an access-aware
 * {@see EntityQueryInterface} (optional {@see EntityAccessHandler}); list
 * queries are served from {@see SqlEntityQueryResultCache}.
 *
 * Revisions are out of scope for this class — revisionable entities are handled
 * by the RevisionableStorageDriver, not here.
 */
final class SqlEntityStorage implements EntityStorageInterface
{
    private readonly string $tableName;
    private readonly string $idKey;
    private readonly ?string $bundleKey;

    /** @var array<string, string> */
    private readonly array $entityKeys;

    /** @var array<string, bool> Column existence cache (column name => exists in table). */
    private array $columnCache = [];

    /** Lazily-resolved bundle subtable gateway; false until first resolution. */
    private BundleSubtableGateway|false|null $bundleGatewayInstance = false;

    private readonly LoggerInterface $logger;

    private readonly EntityEventFactoryInterface $eventFactory;

    private readonly SqlEntityQueryResultCache $queryResultCache;

    private readonly EntityClockInterface $clock;

    private readonly ?FieldDefinitionRegistryInterface $fieldRegistry;

    private readonly ?EntityAccessHandler $accessHandler;

    /**
     * Lazy resolver for the access handler, used when the handler is built
     * after storage (the kernel populates its EntityAccessHandler during boot,
     * while storage instances are created on first use — sometimes mid-boot and
     * cached). Resolving at {@see getQuery()} time rather than construction time
     * guarantees getQuery() sees the real, policy-laden handler at runtime even
     * for a storage that was built before discovery completed.
     *
     * @var ?\Closure(): ?EntityAccessHandler
     */
    private readonly ?\Closure $accessHandlerResolver;

    public function __construct(
        private readonly EntityTypeInterface $entityType,
        private readonly DatabaseInterface $database,
        private readonly EventDispatcherInterface $eventDispatcher,
        ?FieldDefinitionRegistryInterface $fieldRegistry = null,
        ?LoggerInterface $logger = null,
        ?EntityEventFactoryInterface $eventFactory = null,
        ?SqlEntityQueryResultCache $queryResultCache = null,
        ?EntityClockInterface $clock = null,
        ?EntityAccessHandler $accessHandler = null,
        ?\Closure $accessHandlerResolver = null,
    ) {
        $this->tableName = $this->entityType->id();
        $keys = $this->entityType->getKeys();
        $this->idKey = $keys['id'] ?? 'id';
        $this->bundleKey = $keys['bundle'] ?? null;
        $this->entityKeys = $keys;
        $this->logger = $logger ?? new NullLogger();
        $this->eventFactory = $eventFactory ?? new DefaultEntityEventFactory();
        $this->queryResultCache = $queryResultCache ?? new SqlEntityQueryResultCache();
        $this->clock = $clock ?? new UtcEntityClock();
        $this->fieldRegistry = $fieldRegistry;
        $this->accessHandler = $accessHandler;
        $this->accessHandlerResolver = $accessHandlerResolver;
    }

    public function create(array $values = []): EntityInterface
    {
        $values = new Hydration\EntityInstantiator($this->entityType)->applyFieldDefinitionDefaults($values);

        $class = $this->entityType->getClass();
        $entity = $this->instantiateEntity($class, $values);

        if (method_exists($entity, 'enforceIsNew')) {
            $entity->enforceIsNew();
        }

        return $entity;
    }

    public function load(int|string $id): ?EntityInterface
    {
        if ($this->entityType->isTranslatable()) {
            if ($this->isSqlColumnBackend()) {
                return $this->loadSqlColumnTranslatable($id);
            }
            return $this->loadTranslatable($id);
        }

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

        $this->mergeBundleSubtableRow($row);

        return $this->mapRowToEntity($row);
    }

    /**
     * Load a translatable entity: collect every (id, langcode) row, identify
     * the default-langcode row as canonical, hydrate from that row, and hand
     * the per-langcode `_data` map to the entity via _setTranslationData().
     *
     * Implements FR-020..FR-023 read path for sql-blob.
     */
    private function loadTranslatable(int|string $id): ?EntityInterface
    {
        $result = $this->database->select($this->tableName)
            ->fields($this->tableName)
            ->condition($this->idKey, $id)
            ->execute();

        $rows = [];
        foreach ($result as $r) {
            $rows[] = (array) $r;
        }
        if ($rows === []) {
            return null;
        }

        // Pick canonical (default-langcode) row.
        $defaultLangcode = null;
        $defaultRow = null;
        $translationData = [];
        $langcodeKey = $this->entityKeys['langcode'] ?? 'langcode';
        foreach ($rows as $row) {
            $rowLc = isset($row[$langcodeKey]) ? (string) $row[$langcodeKey] : '';
            $rowDefault = isset($row['default_langcode']) ? (string) $row['default_langcode'] : $rowLc;
            $extra = $this->decodeRowData($row);
            $translationData[$rowLc] = $extra;
            if ($defaultLangcode === null) {
                $defaultLangcode = $rowDefault;
            }
            if ($rowLc === $rowDefault) {
                $defaultRow = $row;
            }
        }

        // Fall back to first row when no row's langcode equals default_langcode
        // (shouldn't happen in healthy data; defensive).
        if ($defaultRow === null) {
            $defaultRow = $rows[0];
            $defaultLangcode = isset($defaultRow[$langcodeKey]) ? (string) $defaultRow[$langcodeKey] : 'en';
        }

        $this->mergeBundleSubtableRow($defaultRow);
        $entity = $this->mapRowToEntity($defaultRow);

        // Hand the langcode -> values map to the trait so getTranslation()
        // works without an extra round-trip.
        if ($entity instanceof TranslatableInterface && \method_exists($entity, '_setTranslationData')) {
            $entity->_setTranslationData($translationData, $defaultLangcode);
        }

        return $entity;
    }

    /**
     * Decode a row's `_data` blob into an associative array; returns [] on
     * corrupt JSON (logged via the standard load-path warning).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decodeRowData(array $row): array
    {
        if (!isset($row['_data'])) {
            return [];
        }
        try {
            $decoded = json_decode((string) $row['_data'], associative: true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning(\sprintf(
                'Corrupt _data JSON for %s entity %s: %s',
                $this->tableName,
                isset($row[$this->idKey]) ? (string) $row[$this->idKey] : '?',
                $e->getMessage(),
            ));
            return [];
        }
        return \is_array($decoded) ? $decoded : [];
    }

    public function loadByKey(string $key, mixed $value): ?EntityInterface
    {
        // System-context lookup: identity resolution by a key (uuid, label,
        // bundle, etc.). The access check belongs to whoever calls
        // loadByKey(); the storage primitive itself bypasses query-layer
        // filtering. Mission sql-entity-query-access-checking-01KRYP15, WP02
        // (C-004) — `accessCheck(false)` is the documented system-context
        // bypass.
        $ids = $this->getQuery()
            ->accessCheck(false)
            ->condition($key, $value)
            ->range(0, 1)
            ->execute();

        if ($ids === []) {
            return null;
        }

        return $this->load(array_first($ids));
    }

    /**
     * @param array<int|string> $ids
     * @return array<int|string, EntityInterface>
     */
    public function loadMultiple(array $ids = []): array
    {
        $query = $this->database->select($this->tableName)
            ->fields($this->tableName);

        if (!empty($ids)) {
            $query = $query->condition($this->idKey, $ids, 'IN');
        }

        $result = $query->execute();

        $rows = [];
        foreach ($result as $r) {
            $rows[] = (array) $r;
        }

        if ($rows === []) {
            return [];
        }

        $this->mergeBundleSubtableRowsBatch($rows);

        $entities = [];
        foreach ($rows as $row) {
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

        // Dispatch PRE_SAVE event (before snapshotting so listeners can mutate the entity).
        $this->eventDispatcher->dispatch(
            $this->eventFactory->create($entity),
            EntityEvents::PRE_SAVE->value,
        );

        if ($this->entityType->isTranslatable() && $entity instanceof TranslatableInterface) {
            // T035 (matrix Case 8): translatable types require a non-empty
            // default_langcode. Without it the per-langcode storage layout
            // (composite PK on sql-blob, primary+translation rows on sql-column)
            // has no canonical row to write. Reject before any side effects.
            if ($entity->defaultLangcode() === '') {
                throw \Waaseyaa\Entity\Exception\EntityTranslationException::langcodeRequired();
            }
            $result = $this->isSqlColumnBackend()
                ? $this->saveSqlColumnTranslatable($entity, $isNew)
                : $this->saveTranslatable($entity, $isNew);
            $this->queryResultCache->invalidate($this->tableName);
            $this->dispatchPostSave($entity);
            return $result;
        }

        // Snapshot entity values AFTER PRE_SAVE so listener mutations are persisted.
        $values = $entity->toArray();

        // Partition values into base-row + bundle-subtable shapes via the single
        // bundle-persistence implementation (shared with EntityRepository), so the
        // two write paths cannot drift. Column-stored bundle fields are pulled out
        // so splitForStorage's _data fallback doesn't absorb them; FieldStorage::Data
        // bundle fields and mismatched-bundle fields are handled by the gateway.
        $baseValues = $values;
        $bundleValues = [];
        $currentBundle = null;
        $gateway = $this->bundleGateway();
        if ($gateway !== null) {
            [$baseValues, $bundleValues, $currentBundle] = $gateway->partition($entity, $values);
            if ($bundleValues !== [] && $currentBundle !== null && !$gateway->subtableExists($currentBundle)) {
                // Never a silent drop. The subtable is normally materialized when
                // the bundle fields are registered (EntityTypeManager::addBundleFields).
                // If it is genuinely absent, fold the column-bound values into the
                // base _data blob (no data loss) and log loudly; the _data merge on
                // load recovers them.
                $gateway->logMissingSubtableOnSave($currentBundle, \count($bundleValues));
                $baseValues = \array_merge($baseValues, $bundleValues);
                $bundleValues = [];
                $currentBundle = null;
            }
        }
        $writesSubtable = $bundleValues !== [] && $currentBundle !== null;

        $dbValues = $this->splitForStorage($baseValues);

        $txn = $writesSubtable ? $this->database->transaction() : null;
        try {
            if ($isNew) {
                $result = $this->insertBaseRow($entity, $dbValues);
            } else {
                $result = $this->updateBaseRow($entity, $dbValues);
            }

            if ($writesSubtable) {
                $entityId = $entity->id();
                if ($entityId !== null && $gateway !== null) {
                    /** @var string $currentBundle — narrowed by $writesSubtable. */
                    $gateway->upsert($currentBundle, $entityId, $bundleValues);
                }
            }

            $txn?->commit();
        } catch (\Throwable $e) {
            $txn?->rollBack();
            throw $e;
        }

        $this->queryResultCache->invalidate($this->tableName);

        // Dispatch POST_SAVE event.
        $this->eventDispatcher->dispatch(
            $this->eventFactory->create($entity),
            EntityEvents::POST_SAVE->value,
        );

        return $result;
    }

    /**
     * @param array<string, mixed> $dbValues
     */
    private function insertBaseRow(EntityInterface $entity, array $dbValues): int
    {
        $insertValues = [];
        foreach ($dbValues as $key => $value) {
            if ($key === $this->idKey && $value === null) {
                continue;
            }
            $insertValues[$key] = $value;
        }

        if (!isset($this->entityKeys['uuid']) && (!isset($insertValues[$this->idKey]) || $insertValues[$this->idKey] === '')) {
            throw new \InvalidArgumentException(\sprintf(
                'Config entity "%s" requires a non-empty string ID in the "%s" field.',
                $this->entityType->id(),
                $this->idKey,
            ));
        }

        $id = $this->database->insert($this->tableName)
            ->fields(\array_keys($insertValues))
            ->values($insertValues)
            ->execute();

        if (!isset($insertValues[$this->idKey]) && \method_exists($entity, 'set')) {
            $entity->set($this->idKey, (int) $id);
        }

        if (\method_exists($entity, 'enforceIsNew')) {
            $entity->enforceIsNew(false);
        }

        return EntityConstants::SAVED_NEW;
    }

    /**
     * @param array<string, mixed> $dbValues
     */
    private function updateBaseRow(EntityInterface $entity, array $dbValues): int
    {
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

        return EntityConstants::SAVED_UPDATED;
    }

    /**
     * Helper: dispatch POST_SAVE through the shared factory + dispatcher.
     *
     * Centralising the dispatch keeps phpstan's baseline count for the
     * EventDispatcherInterface::dispatch arguments.count signature stable.
     */
    private function dispatchPostSave(EntityInterface $entity): void
    {
        $this->eventDispatcher->dispatch(
            $this->eventFactory->create($entity),
            EntityEvents::POST_SAVE->value,
        );
    }

    /**
     * Save a translatable entity by writing per-langcode rows.
     *
     * Translatable fields land on the active-langcode row's `_data` blob.
     * Non-translatable fields land on the default-langcode row's `_data` blob
     * (FR-021, FR-024). Drains pending translation deletions from the trait.
     */
    private function saveTranslatable(EntityInterface $entity, bool $isNew): int
    {
        \assert($entity instanceof TranslatableInterface);

        $values = $entity->toArray();
        $defaultLc = $entity->defaultLangcode();
        $activeLc = $entity->activeLangcode();
        $langcodeKey = $this->entityKeys['langcode'] ?? 'langcode';

        // Field-by-field dispatch by translatability.
        $translatableFieldNames = $this->getTranslatableFieldNames();
        $nonTranslatableData = [];
        $translatableData = [];

        // System / identity / schema columns that live on every row.
        $systemKeys = [
            $this->idKey => true,
            ($this->entityKeys['uuid'] ?? 'uuid') => true,
            ($this->entityKeys['bundle'] ?? 'bundle') => true,
            ($this->entityKeys['label'] ?? 'label') => true,
            $langcodeKey => true,
            'default_langcode' => true,
        ];

        $rowColumns = [];
        foreach ($values as $key => $value) {
            if ($key === '_data') {
                continue;
            }
            if (isset($systemKeys[$key])) {
                $rowColumns[$key] = $value;
                continue;
            }
            if (isset($translatableFieldNames[$key])) {
                $translatableData[$key] = $value;
            } else {
                $nonTranslatableData[$key] = $value;
            }
        }

        $rowColumns['default_langcode'] = $defaultLc;

        $txn = $this->database->transaction();
        try {
            if ($isNew) {
                $resultCode = $this->insertTranslatableEntity(
                    $entity,
                    $rowColumns,
                    $defaultLc,
                    $activeLc,
                    $translatableData,
                    $nonTranslatableData,
                    $langcodeKey,
                );
            } else {
                $resultCode = $this->updateTranslatableEntity(
                    $entity,
                    $rowColumns,
                    $defaultLc,
                    $activeLc,
                    $translatableData,
                    $nonTranslatableData,
                    $langcodeKey,
                );
            }

            // Drain pending translation deletions (T trait helper from WP01).
            if (\method_exists($entity, '_takePendingTranslationDeletions')) {
                $pending = $entity->_takePendingTranslationDeletions();
                foreach ($pending as $lcToDelete) {
                    if ($lcToDelete === $defaultLc) {
                        continue;
                    }
                    $this->database->delete($this->tableName)
                        ->condition($this->idKey, $entity->id())
                        ->condition($langcodeKey, $lcToDelete)
                        ->execute();
                }
            }

            $txn->commit();
        } catch (\Throwable $e) {
            $txn->rollBack();
            throw $e;
        }

        return $resultCode;
    }

    /**
     * Whether this entity type is using the sql-column primary backend.
     *
     * Centralises the dispatch predicate used by load/save translatable
     * branches. Falls back to false (= sql-blob) when no explicit backend is
     * declared, preserving NFR-001 for legacy non-translatable types.
     */
    private function isSqlColumnBackend(): bool
    {
        return $this->entityType->getPrimaryStorageBackend() === ReservedBackendIds::SQL_COLUMN;
    }

    /**
     * Load a translatable entity stored under the sql-column backend (FR-028).
     *
     * Delegates to {@see SqlColumnTranslationHydrator} which issues a single
     * LEFT JOIN against `<table>` + `<table>__translation` and returns the
     * fully-hydrated entity with its translationData map populated.
     */
    private function loadSqlColumnTranslatable(int|string $id): ?EntityInterface
    {
        $hydrator = new SqlColumnTranslationHydrator(
            database: $this->database,
            entityType: $this->entityType,
            instantiator: new Hydration\EntityInstantiator($this->entityType),
        );
        return $hydrator->load($id);
    }

    /**
     * Save a translatable entity stored under the sql-column backend
     * (FR-026..FR-031). Routes writes by `FieldDefinition::isTranslatable()`:
     *
     *   - Translatable fields → `<table>__translation` keyed by
     *     (entity_id, langcode).
     *   - Non-translatable fields → `<table>` keyed by entity_id.
     *
     * INSERT path (FR-029): atomic primary-row + default-langcode translation
     * row in a single transaction; an additional active-langcode translation
     * row is inserted when the caller pre-staged `addTranslation()` before
     * first save.
     */
    private function saveSqlColumnTranslatable(EntityInterface $entity, bool $isNew): int
    {
        \assert($entity instanceof TranslatableInterface);

        $values = $entity->toArray();
        $defaultLc = $entity->defaultLangcode();
        $activeLc = $entity->activeLangcode();
        $langcodeKey = $this->entityKeys['langcode'] ?? 'langcode';

        $translatableFieldNames = $this->getTranslatableFieldNames();

        // System / identity / schema columns that live on the primary row.
        $systemKeys = [
            $this->idKey                                => true,
            ($this->entityKeys['uuid'] ?? 'uuid')       => true,
            ($this->entityKeys['bundle'] ?? 'bundle')   => true,
            ($this->entityKeys['label'] ?? 'label')     => true,
            $langcodeKey                                => true,
            'default_langcode'                          => true,
        ];

        $primaryColumns = [];
        $translatableData = [];
        foreach ($values as $key => $value) {
            if ($key === '_data') {
                continue;
            }
            if (isset($systemKeys[$key])) {
                $primaryColumns[$key] = $value;
                continue;
            }
            if (isset($translatableFieldNames[$key])) {
                $translatableData[$key] = $value;
            } else {
                // Non-translatable field column on the primary table.
                $primaryColumns[$key] = $value;
            }
        }

        $primaryColumns['default_langcode'] = $defaultLc;
        $primaryColumns[$langcodeKey] = $defaultLc;

        $translationTable = new TranslationSchemaHandler($this->database)
            ->translationTableName($this->tableName);

        $txn = $this->database->transaction();
        try {
            if ($isNew) {
                $resultCode = $this->insertSqlColumnTranslatable(
                    $entity,
                    $primaryColumns,
                    $translationTable,
                    $defaultLc,
                    $activeLc,
                    $translatableData,
                );
            } else {
                $resultCode = $this->updateSqlColumnTranslatable(
                    $entity,
                    $primaryColumns,
                    $translationTable,
                    $defaultLc,
                    $activeLc,
                    $translatableData,
                );
            }

            // Drain pending translation deletions: DELETE only the matching
            // (entity_id, langcode) row on the translation table. Primary row
            // is untouched.
            if (\method_exists($entity, '_takePendingTranslationDeletions')) {
                $pending = $entity->_takePendingTranslationDeletions();
                foreach ($pending as $lcToDelete) {
                    if ($lcToDelete === $defaultLc) {
                        continue;
                    }
                    $this->database->delete($translationTable)
                        ->condition('entity_id', $entity->id())
                        ->condition('langcode', $lcToDelete)
                        ->execute();
                }
            }

            $txn->commit();
        } catch (\Throwable $e) {
            $txn->rollBack();
            throw $e;
        }

        return $resultCode;
    }

    /**
     * INSERT path for sql-column translatable entities.
     *
     * Writes one primary row, one default-langcode translation row, and (when
     * `activeLc !== defaultLc`) an additional active-langcode translation
     * row. The active row covers the `addTranslation()` pre-stage case.
     *
     * @param array<string, mixed> $primaryColumns
     * @param array<string, mixed> $translatableData
     */
    private function insertSqlColumnTranslatable(
        EntityInterface $entity,
        array $primaryColumns,
        string $translationTable,
        string $defaultLc,
        string $activeLc,
        array $translatableData,
    ): int {
        // Allocate or accept a caller-supplied entity id. sql-column uses a
        // plain int id (no serial) so a single id sits on the primary row.
        if (($primaryColumns[$this->idKey] ?? null) === null || $primaryColumns[$this->idKey] === '') {
            unset($primaryColumns[$this->idKey]);
        }

        // Filter primary columns to those that actually exist on the table
        // (defensive — sql-column tables can carry per-field non-translatable
        // columns added at registration time).
        $primaryFiltered = $this->filterToExistingColumns($this->tableName, $primaryColumns);

        $id = $this->database->insert($this->tableName)
            ->fields(\array_keys($primaryFiltered))
            ->values($primaryFiltered)
            ->execute();

        // When the id wasn't pre-set, the driver-allocated id flows back to
        // the entity so subsequent translation rows reference it.
        if (!isset($primaryColumns[$this->idKey])) {
            $entityId = $id;
            $entity->set($this->idKey, $entityId);
        } else {
            $entityId = $primaryColumns[$this->idKey];
        }

        // INSERT default-langcode translation row carrying translatable values.
        $this->insertSqlColumnTranslationRow(
            $translationTable,
            (string) $entityId,
            $defaultLc,
            $activeLc === $defaultLc ? $translatableData : [],
        );

        // INSERT an additional active-langcode translation row when the caller
        // pre-staged addTranslation() before first save.
        if ($activeLc !== $defaultLc) {
            $this->insertSqlColumnTranslationRow(
                $translationTable,
                (string) $entityId,
                $activeLc,
                $translatableData,
            );
        }

        if (\method_exists($entity, 'enforceIsNew')) {
            $entity->enforceIsNew(false);
        }

        return EntityConstants::SAVED_NEW;
    }

    /**
     * UPDATE path for sql-column translatable entities.
     *
     * Dispatches updates by translatability:
     *   - Non-translatable column writes target the primary row only.
     *   - Translatable column writes target the active-langcode translation
     *     row only — never the primary row (FR-030).
     *
     * If the active translation row is missing (post-addTranslation save
     * before WP05 wired the write path), it is INSERTED.
     *
     * @param array<string, mixed> $primaryColumns
     * @param array<string, mixed> $translatableData
     */
    private function updateSqlColumnTranslatable(
        EntityInterface $entity,
        array $primaryColumns,
        string $translationTable,
        string $defaultLc,
        string $activeLc,
        array $translatableData,
    ): int {
        $entityId = $entity->id();

        // Primary table UPDATE: carries non-translatable column deltas. The
        // langcode column on the primary row pins to the default langcode.
        $primaryUpdate = $primaryColumns;
        unset($primaryUpdate[$this->idKey]);
        // Filter to columns that exist on the primary table to avoid pushing
        // translatable field names into it (they have no column there).
        $primaryUpdate = $this->filterToExistingColumns($this->tableName, $primaryUpdate);
        if ($primaryUpdate !== []) {
            $this->database->update($this->tableName)
                ->fields($primaryUpdate)
                ->condition($this->idKey, $entityId)
                ->execute();
        }

        // Translation table write at the active langcode.
        $existing = $this->fetchSqlColumnTranslationRow($translationTable, (string) $entityId, $activeLc);
        if ($existing === null) {
            // Active row missing — INSERT it. Carries translatable fields when
            // active != default; carries them too when active == default
            // (covers a backfill scenario for the default row).
            $this->insertSqlColumnTranslationRow(
                $translationTable,
                (string) $entityId,
                $activeLc,
                $translatableData,
            );
        } elseif ($translatableData !== []) {
            $update = $this->filterToExistingColumns($translationTable, $translatableData);
            if ($update !== []) {
                $this->database->update($translationTable)
                    ->fields($update)
                    ->condition('entity_id', $entityId)
                    ->condition('langcode', $activeLc)
                    ->execute();
            }
        }

        // Defensive: when active != default, ensure the default-langcode row
        // exists (it would be present from initial INSERT in healthy data).
        if ($activeLc !== $defaultLc) {
            $defaultExisting = $this->fetchSqlColumnTranslationRow($translationTable, (string) $entityId, $defaultLc);
            if ($defaultExisting === null) {
                $this->insertSqlColumnTranslationRow(
                    $translationTable,
                    (string) $entityId,
                    $defaultLc,
                    [],
                );
            }
        }

        return EntityConstants::SAVED_UPDATED;
    }

    /**
     * INSERT one row into `<table>__translation`.
     *
     * @param array<string, mixed> $translatableData
     */
    private function insertSqlColumnTranslationRow(
        string $translationTable,
        string $entityId,
        string $langcode,
        array $translatableData,
    ): void {
        $row = [
            'entity_id' => $entityId,
            'langcode'  => $langcode,
        ];
        foreach ($translatableData as $name => $value) {
            $row[$name] = $value;
        }
        $row = $this->filterToExistingColumns($translationTable, $row);
        $this->database->insert($translationTable)
            ->fields(\array_keys($row))
            ->values($row)
            ->execute();
    }

    /**
     * Probe for an existing translation row at (entity_id, langcode).
     *
     * @return array<string, mixed>|null
     */
    private function fetchSqlColumnTranslationRow(string $translationTable, string $entityId, string $langcode): ?array
    {
        $result = $this->database->select($translationTable)
            ->fields($translationTable)
            ->condition('entity_id', $entityId)
            ->condition('langcode', $langcode)
            ->execute();
        foreach ($result as $row) {
            return (array) $row;
        }
        return null;
    }

    /**
     * Filter a value bag to keys that actually correspond to columns on the
     * given table. Caches per-table column existence checks via the schema
     * helper to avoid repeated INFORMATION_SCHEMA round-trips.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function filterToExistingColumns(string $table, array $values): array
    {
        $schema = $this->database->schema();
        $filtered = [];
        foreach ($values as $key => $value) {
            $cacheKey = $table . '::' . $key;
            if (!isset($this->columnCache[$cacheKey])) {
                $this->columnCache[$cacheKey] = $schema->fieldExists($table, $key);
            }
            if ($this->columnCache[$cacheKey]) {
                $filtered[$key] = $value;
            }
        }
        return $filtered;
    }

    /**
     * INSERT a brand-new translatable entity: at minimum one default-langcode
     * row. If the active langcode differs from the default the caller is
     * mid-`addTranslation()`; we insert two rows in that pass.
     *
     * @param array<string, mixed> $rowColumns
     * @param array<string, mixed> $translatableData
     * @param array<string, mixed> $nonTranslatableData
     */
    private function insertTranslatableEntity(
        EntityInterface $entity,
        array $rowColumns,
        string $defaultLc,
        string $activeLc,
        array $translatableData,
        array $nonTranslatableData,
        string $langcodeKey,
    ): int {
        // Default-langcode row carries non-translatable values + (if active==default)
        // the translatable values too.
        $defaultData = $nonTranslatableData;
        if ($activeLc === $defaultLc) {
            $defaultData = \array_merge($defaultData, $translatableData);
        }

        $defaultRow = $rowColumns;
        $defaultRow[$langcodeKey] = $defaultLc;
        $defaultRow['_data'] = json_encode($defaultData, \JSON_THROW_ON_ERROR);

        // sql-blob translatable types use a plain int id (no serial), so we
        // allocate the next id explicitly under the transaction the caller
        // already opened — autoincrement on (id) alone would conflict with
        // the composite (id, langcode) primary key.
        if (($defaultRow[$this->idKey] ?? null) === null) {
            $defaultRow[$this->idKey] = $this->allocateTranslatableEntityId();
        }
        if ($entity instanceof EntityBase) {
            $entity->set($this->idKey, $defaultRow[$this->idKey]);
        }

        $this->database->insert($this->tableName)
            ->fields(\array_keys($defaultRow))
            ->values($defaultRow)
            ->execute();

        // If the active translation is not the default, INSERT the active row too.
        if ($activeLc !== $defaultLc) {
            $activeRow = $rowColumns;
            $activeRow[$this->idKey] = $entity->id();
            $activeRow[$langcodeKey] = $activeLc;
            $activeRow['_data'] = json_encode($translatableData, \JSON_THROW_ON_ERROR);
            $this->database->insert($this->tableName)
                ->fields(\array_keys($activeRow))
                ->values($activeRow)
                ->execute();
        }

        if ($entity instanceof EntityBase) {
            $entity->enforceIsNew(false);
        }

        return EntityConstants::SAVED_NEW;
    }

    /**
     * UPDATE an existing translatable entity. Routes non-translatable deltas
     * to the default-langcode row and translatable deltas to the active row,
     * inserting the active row if missing (post-addTranslation save).
     *
     * @param array<string, mixed> $rowColumns
     * @param array<string, mixed> $translatableData
     * @param array<string, mixed> $nonTranslatableData
     */
    private function updateTranslatableEntity(
        EntityInterface $entity,
        array $rowColumns,
        string $defaultLc,
        string $activeLc,
        array $translatableData,
        array $nonTranslatableData,
        string $langcodeKey,
    ): int {
        $entityId = $entity->id();

        // ---- Default-langcode row: merge non-translatable deltas onto existing _data ----
        $defaultExisting = $this->fetchTranslationRowData($entityId, $defaultLc, $langcodeKey);
        $defaultData = $defaultExisting === null ? [] : $defaultExisting;
        foreach ($nonTranslatableData as $k => $v) {
            $defaultData[$k] = $v;
        }
        if ($activeLc === $defaultLc) {
            // Translatable writes target the default row in this case.
            foreach ($translatableData as $k => $v) {
                $defaultData[$k] = $v;
            }
        }

        // Always write the default-langcode columns + data blob.
        $defaultRowCols = $rowColumns;
        $defaultRowCols[$langcodeKey] = $defaultLc;
        $defaultRowCols['_data'] = json_encode($defaultData, \JSON_THROW_ON_ERROR);
        unset($defaultRowCols[$this->idKey]);

        if ($defaultExisting === null) {
            // Default row missing (e.g. after a partial schema migration); INSERT it.
            $insertRow = $defaultRowCols;
            $insertRow[$this->idKey] = $entityId;
            $this->database->insert($this->tableName)
                ->fields(\array_keys($insertRow))
                ->values($insertRow)
                ->execute();
        } else {
            $this->database->update($this->tableName)
                ->fields($defaultRowCols)
                ->condition($this->idKey, $entityId)
                ->condition($langcodeKey, $defaultLc)
                ->execute();
        }

        // ---- Active-langcode row (if different): write translatable deltas ----
        if ($activeLc !== $defaultLc) {
            $activeExisting = $this->fetchTranslationRowData($entityId, $activeLc, $langcodeKey);
            if ($activeExisting === null) {
                // Post-addTranslation save: INSERT the new translation row.
                $activeRow = $rowColumns;
                $activeRow[$this->idKey] = $entityId;
                $activeRow[$langcodeKey] = $activeLc;
                $activeRow['_data'] = json_encode($translatableData, \JSON_THROW_ON_ERROR);
                $this->database->insert($this->tableName)
                    ->fields(\array_keys($activeRow))
                    ->values($activeRow)
                    ->execute();
            } else {
                $merged = $activeExisting;
                foreach ($translatableData as $k => $v) {
                    $merged[$k] = $v;
                }
                $activeRowCols = $rowColumns;
                $activeRowCols[$langcodeKey] = $activeLc;
                $activeRowCols['_data'] = json_encode($merged, \JSON_THROW_ON_ERROR);
                unset($activeRowCols[$this->idKey]);
                $this->database->update($this->tableName)
                    ->fields($activeRowCols)
                    ->condition($this->idKey, $entityId)
                    ->condition($langcodeKey, $activeLc)
                    ->execute();
            }
        }

        return EntityConstants::SAVED_UPDATED;
    }

    /**
     * Read the existing `_data` blob for a (entity_id, langcode) row.
     *
     * Returns `null` when the row does not exist. Returns `[]` when the row
     * exists but its blob is corrupt or empty (a deliberate "exists with no
     * fields" sentinel distinct from "missing").
     *
     * @return array<string, mixed>|null
     */
    private function fetchTranslationRowData(int|string|null $entityId, string $langcode, string $langcodeKey): ?array
    {
        if ($entityId === null) {
            return null;
        }
        $result = $this->database->select($this->tableName)
            ->fields($this->tableName, ['_data'])
            ->condition($this->idKey, $entityId)
            ->condition($langcodeKey, $langcode)
            ->execute();
        foreach ($result as $row) {
            $rowArr = (array) $row;
            if (!isset($rowArr['_data'])) {
                return [];
            }
            try {
                $decoded = json_decode((string) $rowArr['_data'], associative: true, flags: \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
            return \is_array($decoded) ? $decoded : [];
        }
        return null;
    }

    /**
     * Allocate the next entity id for a translatable sql-blob type.
     *
     * sql-blob translatable types emit a plain `int` id column (no serial)
     * because SQLite would otherwise impose a UNIQUE constraint on (id) that
     * conflicts with the composite (id, langcode) primary key. Allocation is
     * scoped to the surrounding save transaction so concurrent writers can't
     * race on the MAX(id) probe.
     */
    private function allocateTranslatableEntityId(): int
    {
        $sql = \sprintf(
            'SELECT COALESCE(MAX(%s), 0) AS max_id FROM %s',
            $this->database->quoteIdentifier($this->idKey),
            $this->database->quoteIdentifier($this->tableName),
        );
        foreach ($this->database->query($sql) as $row) {
            $arr = (array) $row;
            return (int) ($arr['max_id'] ?? 0) + 1;
        }
        return 1;
    }

    /** @var array<string, true>|null Cache of field names whose definition is translatable. */
    private ?array $translatableFieldCache = null;

    /**
     * Returns the set of translatable field names for this entity type,
     * keyed by name for O(1) lookup.
     *
     * @return array<string, true>
     */
    private function getTranslatableFieldNames(): array
    {
        if ($this->translatableFieldCache !== null) {
            return $this->translatableFieldCache;
        }
        $names = [];
        foreach ($this->entityType->getFieldDefinitions() as $name => $def) {
            if ($def->isTranslatable()) {
                $names[$name] = true;
            }
        }
        if ($this->fieldRegistry !== null) {
            foreach ($this->fieldRegistry->coreFieldsFor($this->entityType->id()) as $name => $def) {
                if ($def->isTranslatable()) {
                    $names[$name] = true;
                }
            }
        }
        $this->translatableFieldCache = $names;
        return $names;
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
            $this->queryResultCache->invalidate($this->tableName);
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
        $query = new SqlEntityQuery(
            $this->entityType,
            $this->database,
            $this->queryResultCache,
            $this->fieldRegistry,
        );

        // Wire the real access handler so getQuery() is fail-closed. Resolve it
        // lazily here (not at construction): the kernel builds its handler
        // during boot via discoverAccessPolicies(), but storage may be created
        // and cached earlier, so a build-time snapshot could pin an empty
        // handler. An explicit handler wins; otherwise the resolver supplies the
        // kernel's handler at query time (issue #1714).
        $handler = $this->accessHandler
            ?? ($this->accessHandlerResolver !== null ? ($this->accessHandlerResolver)() : null);
        if ($handler !== null) {
            $query = $query->withAccessHandler($handler);
        }

        return $query->withEntityLoader(
            /** @param list<int|string> $ids */
            fn(array $ids): array => $this->loadMultiple($ids),
        );
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

        // Decode json field values from JSON strings back to arrays.
        $jsonFields = $this->getJsonFieldNames();
        foreach ($jsonFields as $fieldName => $_) {
            if (isset($row[$fieldName]) && is_string($row[$fieldName])) {
                try {
                    $row[$fieldName] = json_decode($row[$fieldName], associative: true, depth: 512, flags: \JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    $this->logger->warning(sprintf('Corrupt JSON in field "%s" for %s entity %s: %s', $fieldName, $this->tableName, $row[$this->idKey] ?? '?', $e->getMessage()));
                    $row[$fieldName] = null;
                }
            }
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
        return new Hydration\EntityInstantiator($this->entityType)->instantiate($class, $values);
    }

    /**
     * Split entity values into schema columns + JSON _data blob.
     *
     * Values whose keys match actual table columns are stored directly.
     * All other values are JSON-encoded into the _data column.
     * Fields with type 'json' are JSON-encoded before storage.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function splitForStorage(array $values): array
    {
        $schema = $this->database->schema();
        $jsonFields = $this->getJsonFieldNames();
        $dataStoredFields = $this->getDataStoredCoreFieldNames();
        $dbValues = [];
        $extraData = [];

        foreach ($values as $key => $value) {
            if ($key === '_data') {
                continue;
            }
            // Honor explicit FieldStorage::Data: route to _data even if a
            // legacy column happens to exist. Keeps the registry's storage
            // hint authoritative over residual schema state.
            if (isset($dataStoredFields[$key])) {
                $extraData[$key] = $value;
                continue;
            }
            if ($this->columnExists($key, $schema)) {
                // Encode json field values that are arrays/objects to JSON strings.
                if (isset($jsonFields[$key]) && !is_string($value) && $value !== null) {
                    $value = json_encode($value, \JSON_THROW_ON_ERROR);
                }
                $dbValues[$key] = $value;
            } else {
                $extraData[$key] = $value;
            }
        }

        $dbValues['_data'] = json_encode($extraData, \JSON_THROW_ON_ERROR);

        return $dbValues;
    }

    /**
     * Returns the set of core field names whose registered storage hint is
     * FieldStorage::Data. Used by splitForStorage() to keep `_data`-routed
     * fields out of base columns even when legacy columns exist.
     *
     * @return array<string, true>
     */
    private function getDataStoredCoreFieldNames(): array
    {
        if ($this->fieldRegistry === null) {
            return [];
        }

        $names = [];
        foreach ($this->fieldRegistry->coreFieldsFor($this->entityType->id()) as $name => $definition) {
            if ($definition->getStored() === \Waaseyaa\Field\FieldStorage::Data) {
                $names[$name] = true;
            }
        }

        return $names;
    }

    /**
     * Auto-fills timestamp fields from definitions and optional casts (#1183).
     */
    private function populateTimestamps(EntityInterface $entity, bool $isNew): void
    {
        $fieldDefs = $this->entityType->getFieldDefinitions();
        $now = $this->clock->now();

        foreach ($fieldDefs as $fieldName => $def) {
            $meta = array_merge($def->getSettings(), [
                'type' => $def->getType(),
                'stored' => $def->getStored()->value,
            ]);
            if ($meta['type'] !== 'timestamp') {
                continue;
            }

            $role = TimestampFieldConvention::inferAutoPopulate($fieldName, $meta);
            if ($role === null) {
                continue;
            }

            if ($role === 'create') {
                if (!$isNew || !TimestampFieldConvention::isRawTimestampUnset($entity, $fieldName)) {
                    continue;
                }
            }

            $castSpec = $entity instanceof EntityBase ? $entity->getCastSpecForField($fieldName) : null;
            if (self::isDatetimeImmutableCastSpec($castSpec)) {
                $entity->set($fieldName, $now);

                continue;
            }

            $format = TimestampFieldConvention::resolveStorageFormat($fieldName, $meta);
            $scalar = $format === 'unix'
                ? $now->getTimestamp()
                : $now->format(\DateTimeInterface::ATOM);
            $entity->set($fieldName, $scalar);
        }
    }

    /**
     * @param string|array<string, mixed>|null $spec
     */
    private static function isDatetimeImmutableCastSpec(string|array|null $spec): bool
    {
        if ($spec === null) {
            return false;
        }

        $token = is_string($spec) ? $spec : (string) ($spec['type'] ?? '');

        return $token === 'datetime_immutable';
    }

    /** @var array<string, true>|null Cached json field names for this entity type. */
    private ?array $jsonFieldCache = null;

    /**
     * Returns field names whose type is 'json', keyed by name.
     *
     * @return array<string, true>
     */
    private function getJsonFieldNames(): array
    {
        if ($this->jsonFieldCache !== null) {
            return $this->jsonFieldCache;
        }

        $this->jsonFieldCache = [];
        foreach ($this->entityType->getFieldDefinitions() as $name => $def) {
            if ($def->getType() === 'json') {
                $this->jsonFieldCache[$name] = true;
            }
        }

        return $this->jsonFieldCache;
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

    /**
     * Lazily resolve the single bundle subtable gateway for this entity type, or
     * null when bundle persistence does not apply (no registry, or no bundle
     * fields registered). Shared implementation with EntityRepository, so the two
     * persistence paths cannot drift.
     */
    private function bundleGateway(): ?BundleSubtableGateway
    {
        if ($this->bundleGatewayInstance !== false) {
            return $this->bundleGatewayInstance;
        }

        if ($this->fieldRegistry === null) {
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
     * Merge the matching bundle subtable row into a single base-table row.
     *
     * No-op when the registry/bundleKey is unavailable, when the row lacks a
     * bundle/id, or when the subtable does not exist. Existing keys on the
     * base row win — bundle columns cannot shadow base columns.
     *
     * @param array<string, mixed> $row
     * @param-out array<string, mixed> $row
     */
    private function mergeBundleSubtableRow(array &$row): void
    {
        $gateway = $this->bundleGateway();
        if ($gateway === null || $this->bundleKey === null) {
            return;
        }

        $bundle = $row[$this->bundleKey] ?? null;
        if (!\is_string($bundle) || $bundle === '') {
            return;
        }

        $id = $row[$this->idKey] ?? null;
        if ($id === null) {
            return;
        }

        if (!$gateway->subtableExists($bundle)) {
            $gateway->logMissingSubtableOnLoad($bundle);
            return;
        }

        foreach ($gateway->read($bundle, $id) as $k => $v) {
            // Existing keys on the base row win: bundle columns cannot shadow
            // base columns.
            if (!\array_key_exists($k, $row)) {
                $row[$k] = $v;
            }
        }
    }

    /**
     * Batch variant of mergeBundleSubtableRow: groups rows by bundle and
     * performs one IN query per bundle rather than one lookup per row.
     *
     * @param list<array<string, mixed>> $rows
     * @param-out list<array<string, mixed>> $rows
     */
    private function mergeBundleSubtableRowsBatch(array &$rows): void
    {
        $gateway = $this->bundleGateway();
        if ($gateway === null || $this->bundleKey === null || $rows === []) {
            return;
        }

        $idsByBundle = [];
        $indexByBundleAndId = [];
        foreach ($rows as $i => $row) {
            $bundle = $row[$this->bundleKey] ?? null;
            $id = $row[$this->idKey] ?? null;
            if (!\is_string($bundle) || $bundle === '' || $id === null) {
                continue;
            }
            $idsByBundle[$bundle][] = $id;
            $indexByBundleAndId[$bundle][(string) $id] = $i;
        }

        foreach ($idsByBundle as $bundle => $ids) {
            if (!$gateway->subtableExists($bundle)) {
                $gateway->logMissingSubtableOnLoad($bundle);
                continue;
            }

            // One IN query per bundle (not one lookup per row).
            foreach ($gateway->readMany($bundle, $ids) as $subId => $values) {
                $rowIndex = $indexByBundleAndId[$bundle][$subId] ?? null;
                if ($rowIndex === null) {
                    continue;
                }
                foreach ($values as $k => $v) {
                    if (!\array_key_exists($k, $rows[$rowIndex])) {
                        $rows[$rowIndex][$k] = $v;
                    }
                }
            }
        }
    }
}

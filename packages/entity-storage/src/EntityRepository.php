<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
use Waaseyaa\Access\Context\AccountFieldReadScopeInterface;
use Waaseyaa\Access\EntityAccessHandler;
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
use Waaseyaa\Entity\FieldValueCanonicalizer;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\Entity\RevisionMetadata;
use Waaseyaa\Entity\Storage\EntityQueryInterface;
use Waaseyaa\Entity\TranslatableInterface;
use Waaseyaa\Entity\Validation\EntityTypeValidationConstraints;
use Waaseyaa\Entity\Validation\EntityValidationException;
use Waaseyaa\Entity\Validation\EntityValidator;
use Waaseyaa\EntityStorage\Bundle\BundleSubtableGateway;
use Waaseyaa\EntityStorage\Driver\EntityStorageDriverV2Interface;
use Waaseyaa\EntityStorage\Driver\LangcodePeerStorageDriverV2Interface;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriverV2;
use Waaseyaa\EntityStorage\Driver\RevisionableStorageDriverV2Interface;
use Waaseyaa\EntityStorage\Driver\StorageBoundary;
use Waaseyaa\EntityStorage\Driver\StorageRowReader;
use Waaseyaa\EntityStorage\Driver\StorageSnapshot;
use Waaseyaa\EntityStorage\Driver\StorageSnapshotFactory;
use Waaseyaa\EntityStorage\Event\AbortOperationException;
use Waaseyaa\EntityStorage\Event\AfterSaveEvent;
use Waaseyaa\EntityStorage\Event\BeforeRevisionPointerMoveEvent;
use Waaseyaa\EntityStorage\Event\BeforeSaveEvent;
use Waaseyaa\EntityStorage\Event\RevisionPointerMovedEvent;
use Waaseyaa\EntityStorage\Exception\RevisionConflictException;
use Waaseyaa\EntityStorage\Revision\RevisionPruningPolicy;
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
    private readonly EntityStorageDriverV2Interface $driver;

    private readonly StorageRowReader $storageRowReader;

    private readonly StorageSnapshotFactory $storageSnapshotFactory;

    /** @var \Closure(EntityBase): array<string, mixed> */
    private readonly \Closure $persistenceValueAuthority;

    /** @var array<class-string, true> */
    private array $legacyPersistenceDiagnosticEmitted = [];

    private readonly RevisionableStorageDriverV2Interface|null $revisionDriver;

    /** @var string[] Default language fallback chain. */
    private array $fallbackChain = ['en'];

    private readonly EntityEventFactoryInterface $eventFactory;

    private readonly \Waaseyaa\Foundation\Log\LoggerInterface $logger;

    private readonly Hydration\EntityInstantiator $entityInstantiator;

    /** Lazily-resolved bundle subtable gateway; false until first resolution. */
    private BundleSubtableGateway|false|null $bundleGatewayInstance = false;

    /**
     * Request-scoped acting-account context, the ambient source for
     * `revision_author` resolution (mission revision-audit-provenance-01KTWY5V,
     * FR-001/FR-002). Null = no ambient context (every save records a NULL
     * author unless a {@see SaveContext::withActorUid()} override applies).
     */
    private ?AccountContextInterface $accountContext = null;

    public function __construct(
        private readonly EntityTypeInterface $entityType,
        EntityStorageDriverV2Interface $driver,
        private readonly EventDispatcherInterface $eventDispatcher,
        RevisionableStorageDriver|RevisionableStorageDriverV2Interface|null $revisionDriver = null,
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
        // C-22: access-checked query surface. Mirrors SqlEntityStorage's two
        // access-handler slots so getQuery() is fail-closed and produces the
        // SAME access-filtered results as the storage engine. An explicit
        // handler wins; otherwise the resolver supplies the kernel's handler at
        // query time (the kernel builds it during boot via
        // discoverAccessPolicies(), after this repository may already be cached
        // — so resolve lazily, never snapshot at construction). Both null leave
        // getQuery() unfiltered — valid only for system-context callers.
        private readonly ?EntityAccessHandler $accessHandler = null,
        // @var ?\Closure(): ?EntityAccessHandler
        private readonly ?\Closure $accessHandlerResolver = null,
        ?StorageBoundary $storageBoundary = null,
        private readonly ?AccountFieldReadScopeInterface $fieldReadScope = null,
    ) {
        $this->eventFactory = $eventFactory ?? new DefaultEntityEventFactory();
        $this->logger = $logger ?? new \Waaseyaa\Foundation\Log\NullLogger();
        $storageBoundary ??= new StorageBoundary();
        $this->storageRowReader = $storageBoundary->repositoryRowReader();
        $this->storageSnapshotFactory = $storageBoundary->repositorySnapshotFactory();
        $persistenceValueAuthority = \Closure::bind(
            static fn(EntityBase $source): array => $source->valueContainer->rawValues(),
            null,
            EntityBase::class,
        );
        $this->persistenceValueAuthority = $persistenceValueAuthority;
        $this->revisionDriver = $revisionDriver instanceof RevisionableStorageDriver
            ? new RevisionableStorageDriverV2(
                $revisionDriver,
                $storageBoundary->driverRowFactory(),
                $storageBoundary->driverSnapshotReader(),
            )
            : $revisionDriver;
        $this->driver = $driver;
        $this->entityInstantiator = new Hydration\EntityInstantiator($this->entityType, $this->fieldRegistry);
    }

    /** @return array<string, mixed>|null */
    private function readDriverRow(string $entityType, string $id, ?string $langcode = null): ?array
    {
        $row = $this->driver->read($entityType, $id, $langcode);

        return $row === null ? null : $this->storageRowReader->read($row);
    }

    /**
     * @param list<int|string> $ids
     * @return array<int|string, array<string, mixed>>
     */
    private function readDriverRows(string $entityType, array $ids, ?string $langcode = null): array
    {
        return $this->storageRowReader->readSet($this->driver->readMultiple($entityType, $ids, $langcode));
    }

    /** @param array<string, mixed> $values */
    private function writeDriverRow(string $entityType, string $id, array $values): string
    {
        return $this->driver->write($entityType, $id, $this->storageSnapshotFactory->create($values));
    }

    /**
     * Repository-owned, non-exported persistence authority. First-party raw
     * values are reachable only through the private closure identity retained
     * by this repository; legacy third-party entities keep the diagnosed WP2
     * compatibility path until activation removes it.
     *
     * @return array<string, mixed>
     */
    private function extractPersistenceValues(EntityInterface $entity): array
    {
        if ($entity instanceof EntityBase) {
            return ($this->persistenceValueAuthority)($entity);
        }

        $class = $entity::class;
        if (!isset($this->legacyPersistenceDiagnosticEmitted[$class])) {
            $this->legacyPersistenceDiagnosticEmitted[$class] = true;
            $this->logger->notice('entity.deprecation', [
                'event' => 'legacy_persistence_to_array',
                'entity_class' => $class,
            ]);
        }

        return $entity->toArray();
    }

    /** @return array<string, mixed>|null */
    private function readRevisionRow(string $entityId, int $revisionId): ?array
    {
        $row = $this->revisionDriver?->readRevision($entityId, $revisionId);

        return $row === null ? null : $this->storageRowReader->read($row);
    }

    /** @return array<string, mixed>|null */
    private function readLangcodeRevisionRow(string $entityId, string $langcode, int $revisionId): ?array
    {
        $row = $this->revisionDriver?->readLangcodeRevision($entityId, $langcode, $revisionId);

        return $row === null ? null : $this->storageRowReader->read($row);
    }

    /** @param array<string, mixed> $values */
    private function writeRevisionRow(
        string $entityId,
        array $values,
        ?string $log,
        ?string $langcode = null,
        ?int $author = null,
    ): int {
        if ($this->revisionDriver === null) {
            throw new \LogicException('Revision driver not configured for entity type ' . $this->entityType->id());
        }

        return $this->revisionDriver->writeRevision(
            $entityId,
            $this->storageSnapshotFactory->create($values),
            $log,
            $langcode,
            $author,
        );
    }

    /** @param array<string, mixed> $values */
    private function updateRevisionRow(string $entityId, int $revisionId, array $values): void
    {
        if ($this->revisionDriver === null) {
            throw new \LogicException('Revision driver not configured for entity type ' . $this->entityType->id());
        }

        $this->revisionDriver->updateRevision(
            $entityId,
            $revisionId,
            $this->storageSnapshotFactory->create($values),
        );
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, string>|null $orderBy
     * @return array<int|string, array<string, mixed>>
     */
    private function findDriverRows(
        string $entityType,
        array $criteria = [],
        ?array $orderBy = null,
        ?int $limit = null,
    ): array {
        return $this->storageRowReader->readSet($this->driver->findBy($entityType, $criteria, $orderBy, $limit));
    }

    /** @return array<int|string, array<string, mixed>> */
    private function findDriverTranslations(string $entityType, string $id, ?string $defaultLangcode = null): array
    {
        return $this->storageRowReader->readSet($this->driver->findTranslations($entityType, $id, $defaultLangcode));
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

    /**
     * Attach the request-scoped acting-account context used to resolve
     * `revision_author` on every revision-creating operation (mission
     * revision-audit-provenance-01KTWY5V, FR-001/FR-002).
     *
     * Set by the kernel repository factory ({@see \Waaseyaa\Foundation\Kernel\AbstractKernel},
     * WP01 forward seam) — adding this method activates that seam. Direct
     * constructions (tests, consumers) may call it or rely on the null
     * default, which means "no ambient context": every save resolves a NULL
     * author unless a {@see SaveContext::withActorUid()} override applies.
     * Queue jobs and CLI runs that never set the context behave the same —
     * null author, by design (no special-casing; an upstream executor may
     * scope the context or callers may pass an explicit override).
     *
     * @api
     */
    public function setAccountContext(?AccountContextInterface $accountContext): void
    {
        $this->accountContext = $accountContext;
    }

    /**
     * Resolve the acting account for one revision-creating operation —
     * computed ONCE per operation, never per row (contract revision-author.md
     * clauses 1–3; NFR-001).
     *
     * Resolution order: explicit {@see SaveContext::withActorUid()} override
     * (including `withActorUid(null)`) → ambient account context account id →
     * null. `0` is returned if and only if the resolved actor IS the anonymous
     * account (id 0); null is never coerced to 0.
     */
    private function resolveActor(?SaveContext $context): ?int
    {
        if ($context !== null && $context->actorOverridden()) {
            return $context->actorUid();
        }

        $id = $this->accountContext?->current()?->id();

        // AccountInterface::id() is int|string; revision_author is an int uid.
        return $id === null ? null : (int) $id;
    }

    /**
     * Build the {@see RevisionMetadata} read model from a raw revision row and
     * attach it to entities implementing {@see RevisionableEntityInterface}
     * (contract revision-author.md clauses 7–10). SQL NULL author — including
     * every pre-mission row — hydrates `revisionAuthor: null`; `0` round-trips
     * as `0` (anonymous).
     *
     * @param array<string, mixed> $row
     */
    private function attachRevisionMetadata(EntityInterface $entity, array $row): void
    {
        if (!$entity instanceof RevisionableEntityInterface) {
            return;
        }
        if (!isset($row['revision_created']) || !method_exists($entity, 'setRevisionMetadata')) {
            return;
        }

        try {
            $createdAt = new \DateTimeImmutable((string) $row['revision_created']);
        } catch (\Exception) {
            // Unparseable timestamp on a legacy/foreign row: no metadata
            // rather than a crashed load.
            return;
        }

        $entity->setRevisionMetadata(new RevisionMetadata(
            revisionCreatedAt: $createdAt,
            revisionAuthor: isset($row['revision_author']) ? (int) $row['revision_author'] : null,
            revisionLog: ($row['revision_log'] ?? null) !== null ? (string) $row['revision_log'] : null,
        ));
    }

    public function create(array $values = []): EntityInterface
    {
        // Shared with SqlEntityStorage::create() via EntityInstantiator so a
        // fresh entity gets the same field defaults regardless of engine.
        $values = $this->entityInstantiator->applyFieldDefinitionDefaults($values);

        $class = $this->entityType->getClass();
        $entity = $this->entityInstantiator->instantiate($class, $values);

        if (method_exists($entity, 'enforceIsNew')) {
            $entity->enforceIsNew();
        }

        return $entity;
    }

    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface
    {
        $entityTypeId = $this->entityType->id();

        if ($langcode !== null && $fallback) {
            // Try the requested language first, then each fallback language.
            $languagesToTry = array_unique(array_merge([$langcode], $this->fallbackChain));

            foreach ($languagesToTry as $tryLang) {
                $row = $this->readDriverRow($entityTypeId, $id, $tryLang);
                if ($row !== null) {
                    return $this->hydrate($row);
                }
            }

            // Final fallback: try without language.
            $row = $this->readDriverRow($entityTypeId, $id);
            return $row !== null ? $this->hydrate($row) : null;
        }

        $row = $this->readDriverRow($entityTypeId, $id, $langcode);

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

        $rowsById = $this->readDriverRows($entityTypeId, $orderedKeys, $langcode);
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
        $rows = $this->findDriverRows($entityTypeId, $criteria, $orderBy, $limit);

        $entities = [];
        foreach ($rows as $row) {
            $entities[] = $this->hydrate($row);
        }

        return $entities;
    }

    /**
     * Build an access-checked entity query (C-22).
     *
     * Mirrors {@see SqlEntityStorage::getQuery()} exactly so the two engines'
     * query surfaces are interchangeable: same {@see SqlEntityQuery} build, same
     * lazily-resolved {@see EntityAccessHandler} threading, same id-keyed entity
     * loader. The query is fail-closed — an unbound account throws
     * {@see \Waaseyaa\EntityStorage\Exception\MissingQueryAccountException} on
     * `execute()` (see {@see SqlEntityQuery::execute()}).
     */
    public function getQuery(): EntityQueryInterface
    {
        if ($this->database === null) {
            throw new \RuntimeException(\sprintf(
                'EntityRepository for "%s" was constructed without a database; getQuery() requires one.',
                $this->entityType->id(),
            ));
        }

        $query = new SqlEntityQuery(
            $this->entityType,
            $this->database,
            null,
            $this->fieldRegistry,
            $this->fieldReadScope,
        );

        // Resolve the handler lazily (not at construction) — see the constructor
        // note on $accessHandler. An explicit handler wins; otherwise ask the
        // resolver at query time so a handler built after this repository is
        // still seen (mirrors SqlEntityStorage::getQuery(), issue #1714).
        $handler = $this->accessHandler
            ?? ($this->accessHandlerResolver !== null ? ($this->accessHandlerResolver)() : null);
        if ($handler !== null) {
            $query = $query->withAccessHandler($handler);
        }

        return $query->withContextualEntityLoader(new ContextualEntityLoader(
            $this->database,
            /** @param list<int|string> $ids */
            fn(array $ids): array => $this->hydrateByIdForQuery($ids),
        ));
    }

    /**
     * Hydrate candidate rows into an id-keyed map for the access-checked
     * query's per-row filter.
     *
     * Matches {@see SqlEntityStorage::loadMultiple()}'s shape — keyed by
     * `$entity->id()` — so `getQuery()` runs the identical per-row
     * `EntityAccessHandler::check()` the storage engine runs. Reuses the
     * repository's own {@see findMany()} hydration (no language handoff for the
     * base read) and re-keys the result.
     *
     * @param list<int|string> $ids
     *
     * @return array<int|string, EntityInterface>
     */
    private function hydrateByIdForQuery(array $ids): array
    {
        $entities = [];
        foreach ($this->findMany($ids) as $entity) {
            $entityId = $entity->id();
            if ($entityId !== null) {
                $entities[$entityId] = $entity;
            }
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
     * @throws \RuntimeException If a PRE_SAVE subscriber rejects the save (e.g. a workflow guard denial) — subscriber exceptions propagate to the caller.
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

    /**
     * Canonicalize declared boolean fields to native PHP bool.
     *
     * Entity-backed saves are already canonical through the sealed value
     * container. This shared pass keeps array-based translation, revision
     * restore, rollback, and backfill entry points on the same definition-level
     * contract before their snapshots reach storage.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function canonicalizeBooleanFieldValues(
        array $values,
        ?EntityInterface $entity = null,
        ?string $entityId = null,
    ): array {
        $definitions = $entity !== null
            ? $this->resolveValidationFieldDefinitions($entity)
            : $this->resolveArrayWriteFieldDefinitions($values, $entityId);

        foreach ($definitions as $name => $definition) {
            if (!\in_array(\strtolower($definition->getType()), ['bool', 'boolean'], true)
                || !\array_key_exists($name, $values)
                || $values[$name] === null
            ) {
                continue;
            }

            $values[$name] = FieldValueCanonicalizer::forType($definition->getType(), $values[$name]);
        }

        return $values;
    }

    /**
     * Resolve field definitions for repository entry points that accept value
     * arrays instead of an entity object (the translation write APIs).
     *
     * @param array<string, mixed> $values
     * @return array<string, \Waaseyaa\Field\FieldDefinitionInterface>
     */
    private function resolveArrayWriteFieldDefinitions(array $values, ?string $entityId): array
    {
        $fields = $this->entityType->getFieldDefinitions();
        if ($this->fieldRegistry === null) {
            return $fields;
        }

        $entityTypeId = $this->entityType->id();
        foreach ($this->fieldRegistry->coreFieldsFor($entityTypeId) as $name => $definition) {
            $fields[$name] = $definition;
        }

        $bundleKey = $this->entityType->getKeys()['bundle'] ?? null;
        $bundle = $bundleKey !== null ? (string) ($values[$bundleKey] ?? '') : '';
        if ($bundle === '' && $bundleKey !== null && $entityId !== null) {
            $baseRow = $this->readDriverRow($entityTypeId, $entityId);
            $bundle = (string) ($baseRow[$bundleKey] ?? '');
        }

        if ($bundle !== '') {
            foreach ($this->fieldRegistry->bundleFieldsFor($entityTypeId, $bundle) as $name => $definition) {
                $fields[$name] = $definition;
            }
        }

        return $fields;
    }

    /**
     * Coordinates a single entity save: optimistic-locking preconditions,
     * validation, the in-transaction guarded pointer claim, base + bundle +
     * revision writes, and the lifecycle event sequence.
     *
     * TECH-DEBT (audit D-20): this method is ~348 LOC mixing 5+ concerns.
     * Intended decomposition, deferred because every block is
     * byte-identity-sensitive (entity-storage-v2 spec §7/§12.4, FR-008) and
     * concurrency-critical (the guarded claim closes the TOCTOU race of
     * #1654/#1449): extract assertExpectationPreconditions(),
     * performGuardedPointerClaim(), writeDeferredInitialRevision(), and
     * allocateTranslatableBaseId() once a behavior-identity test harness pins
     * the save sequence. Tracked as tech-debt, not a remediation-pass change.
     */
    private function doSave(
        EntityInterface $entity,
        ?UnitOfWork $unitOfWork = null,
        bool $validate = true,
        ?SaveContext $saveContext = null,
    ): int {
        $isNew = $entity->isNew();
        $entityTypeId = $this->entityType->id();
        if (!$isNew && $this->revisionDriver !== null) {
            $this->revisionDriver->assertEntityMutationAllowed((string) $entity->id());
        }
        $resolvedContext = $saveContext ?? SaveContext::default();
        // Optimistic-locking expectation (mission optimistic-locking-01KTXCHY).
        // Null = no expectation: every conflict-detection branch below is
        // skipped and the save is byte-identical to the legacy path (FR-003,
        // NFR-001 — this read is the single null check the mission adds).
        $expectedRevisionId = $resolvedContext->expectedRevisionId();
        if ($expectedRevisionId !== null) {
            // Rejection matrix (FR-007, research D6): a stated expectation
            // that cannot be honored throws \LogicException — never silently
            // ignored, never downgraded to last-write-wins. Distinct,
            // greppable messages; surfaces (tool/API) translate them.
            if ($isNew) {
                throw new \LogicException(
                    'Cannot state a revision expectation for a new (unsaved) entity: '
                    . 'no current revision exists to compare against.',
                );
            }
            if (!$this->entityType->isRevisionable()) {
                throw new \LogicException(
                    "Cannot state a revision expectation: entity type '{$entityTypeId}' is not revisionable; "
                    . 'revision expectations require revision tracking.',
                );
            }
            if ($this->entityType->isTranslatable()) {
                throw new \LogicException(
                    "Cannot state a revision expectation: entity type '{$entityTypeId}' is translatable "
                    . '(two-axis revisionable + translatable). Per-language revision tips are a separate '
                    . 'concurrency domain — use the saveTranslation* workflow for per-language writes.',
                );
            }
            if ($this->database === null) {
                throw new \LogicException(
                    'Stating a revision expectation requires a database connection for the guarded '
                    . 'pointer claim and transaction support.',
                );
            }
            if ($this->revisionDriver === null) {
                // Without a revision driver no revision is ever written and
                // the guarded pointer-claim branch is unreachable — the
                // expectation would degrade to the TOCTOU-unsafe fail-fast
                // pre-check alone (FR-004/FR-007 silent downgrade). Reject.
                throw new \LogicException(
                    "Revision driver not configured for entity type '{$entityTypeId}': cannot state "
                    . 'a revision expectation — no revision can be written and no guarded pointer '
                    . 'claim exists.',
                );
            }
        }
        // Revision author — resolved ONCE per save operation (override →
        // ambient context → null) and passed to BOTH the immediate and the
        // deferred-id revision writes below (FR-001, clause 2).
        $actor = $this->resolveActor($resolvedContext);

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

            if ($expectedRevisionId !== null) {
                // Fail-fast pre-check (FR-001, contract §4): compare the
                // expectation against the already-loaded head — zero added
                // queries. Runs AFTER validation (contract §3: an invalid +
                // conflicted save reports the validation failure) and BEFORE
                // preSave()/PRE_SAVE/BeforeSaveEvent — a refused save
                // dispatches NOTHING and mutates nothing (contract §6). The
                // authoritative race closure is the guarded pointer claim
                // inside the transaction below.
                if ($originalEntity === null) {
                    // Row vanished behind the caller's back.
                    throw new RevisionConflictException($entityTypeId, $id, $expectedRevisionId, null);
                }
                // #1654: ContentEntityBase subclasses carry revision
                // capability via RevisionableEntityInterface +
                // RevisionableEntityTrait without declaring the legacy
                // RevisionableInterface, so an instanceof gate on the legacy
                // interface alone read the head as null and every stated
                // expectation conflicted. The trait provides getRevisionId();
                // duck-check it (same method_exists pattern as the set*
                // hydration sites in this class) so the real head pointer
                // is read.
                $currentRevisionId = null;
                if ($originalEntity instanceof RevisionableInterface) {
                    $currentRevisionId = $originalEntity->getRevisionId();
                } elseif ($originalEntity instanceof RevisionableEntityInterface
                    && method_exists($originalEntity, 'getRevisionId')
                ) {
                    $rid = $originalEntity->getRevisionId();
                    $currentRevisionId = \is_int($rid) ? $rid : null;
                }
                if ($currentRevisionId !== $expectedRevisionId) {
                    // $currentRevisionId === null here = persisted row with no
                    // revision pointer (pre-backfill) — unhonorable, surfaced
                    // as a conflict with a null current head (exception docblock).
                    throw new RevisionConflictException($entityTypeId, $id, $expectedRevisionId, $currentRevisionId);
                }
            }
        }

        if ($entity instanceof EntityBase) {
            $entity->preSave($isNew);
        }

        // PRE-write events dispatch IMMEDIATELY — even under a UnitOfWork
        // batch (audit-remediation batch 2026-07-02, WP2 review BLOCKER).
        // A PRE event announces intent: listeners that mutate the entity
        // (classification label resolution) or issue guarding DB writes
        // (the attachment active-invariant sibling demote) only work if
        // they run BEFORE the row is written. Post-commit buffering exists
        // so listeners never observe rolled-back work — an immediate PRE
        // listener's DB writes JOIN the batch transaction and roll back
        // with it, which satisfies that goal without breaking the
        // pre-write contract. (Buffered-PRE also broke saveMany batches
        // of attachment-style guards: both listeners fired post-commit
        // and cross-demoted each other's rows.) POST/AFTER events remain
        // buffered — see dispatchEvent().
        $this->dispatchEvent(
            $this->eventFactory->create($entity, $originalEntity),
            EntityEvents::PRE_SAVE->value,
        );

        // Default-revision discipline (CW-v1 option-1 forward-draft rebuild,
        // #1920 PR-1). Evaluated AFTER the PRE_SAVE dispatch above — that is
        // where the workflows save-path guard (wired in the next PR; this
        // save-path handling ships dormant here) sets the transient flag via
        // RevisionableEntityTrait::setDefaultRevisionDiscipline(). Duck-checked
        // (method_exists) the same way #1654 duck-checks getRevisionId() —
        // an entity carrying revision capability via RevisionableEntityInterface
        // + RevisionableEntityTrait without declaring the legacy
        // RevisionableInterface still gets the flag read correctly. Only
        // meaningful for an existing (non-new) revisionable entity with a
        // revision driver wired: a new entity has no base pointer to keep
        // stable, and an undriven/non-revisionable type has nowhere to write
        // a revision-only save.
        $disciplined = !$isNew
            && $this->revisionDriver !== null
            && $this->entityType->isRevisionable()
            && method_exists($entity, 'isDefaultRevisionDisciplined')
            && (bool) $entity->isDefaultRevisionDisciplined();

        if ($disciplined && $expectedRevisionId !== null) {
            // Rejection matrix extension (docs/specs/revision-system-unified.md
            // §3b): the guarded optimistic-locking claim is a base-pointer
            // UPDATE — structurally meaningless when default-revision
            // discipline forbids the base pointer from moving on this save.
            // Thrown before any write, same \LogicException convention as
            // the matrix above.
            throw new \LogicException(
                'Cannot state a revision expectation on a default-revision-disciplined save for entity '
                . "type '{$entityTypeId}' (id '" . (string) ($entity->id() ?? '') . "'): the guarded claim is a "
                . 'base-pointer UPDATE, which is structurally meaningless when the base pointer must not '
                . 'move under default-revision discipline.',
            );
        }

        $createRevision = $this->shouldCreateRevision($entity, $isNew);

        if ($expectedRevisionId !== null && (!$createRevision || $resolvedContext->withoutNewRevision)) {
            // Rejection matrix (FR-007): a non-revision-creating save never
            // moves the head pointer, so no unambiguous guarded claim exists
            // (a no-change UPDATE counts 0 affected rows on MySQL). This is a
            // \LogicException caller error, not a conflict, so running after
            // preSave()/PRE_SAVE is acceptable (contract §4 binds conflicts to
            // pre-event refusal; §11 binds rejections to "explicit" only).
            // SaveContext::withoutNewRevision is checked here too: this path
            // ignores the flag for revision suppression (the coordinator
            // honors it), but a caller pairing it with an expectation has
            // declared a non-revision-creating intent — reject, don't guess.
            throw new \LogicException(
                'Cannot state a revision expectation for a non-revision-creating save '
                . '(SaveContext::withoutNewRevision(), entity setNewRevision(false), or entity type '
                . 'revisionDefault: false): the head pointer would not move, so no unambiguous claim '
                . 'exists. Force a revision with setNewRevision(true) to get a checkable save.',
            );
        }

        // GitHub #1449: Coordinator-level lifecycle event. The repository is
        // now the single dispatch site for BeforeSaveEvent / AfterSaveEvent;
        // callers (e.g. `\Waaseyaa\Migration\Plugin\Destination\EntityDestination`)
        // no longer self-dispatch. Subscribers may abort via
        // AbortOperationException; no write occurs and AfterSaveEvent does
        // NOT fire. Dispatched IMMEDIATELY even under a UnitOfWork batch
        // (see the PRE_SAVE dispatch above for the full rationale): the
        // documented abort contract is unfulfillable when buffered
        // post-commit — the "abort" would fire after every batch row had
        // already committed (and its throw would then hit the committed
        // transaction's rollback path). Immediate dispatch makes a
        // mid-batch abort roll back the WHOLE batch, as the contract
        // requires.
        $this->dispatchEvent(
            new BeforeSaveEvent($entity, $resolvedContext, $createRevision),
            BeforeSaveEvent::class,
        );

        $values = $this->canonicalizeBooleanFieldValues(
            $this->extractPersistenceValues($entity),
            $entity,
        );
        // C-22 WP4 fix: read the id key from the entity's raw value bag
        // (toArray()), not the $entity->id() accessor. Some entity classes
        // (e.g. Waaseyaa\User\User::id(), which implements AccountInterface's
        // contract of "anonymous is 0, never null") override id() to coerce
        // an unset id into a non-null sentinel. `$entity->id() ?? ''` then
        // never sees the "not yet assigned" null, `$id` never becomes '',
        // and the new-entity id-backfill branches below (and
        // SqlStorageDriver::write()'s own empty-id branch) never fire —
        // silently leaving a freshly-inserted entity's in-memory id at the
        // sentinel (e.g. a newly created User staying at uid 0) instead of
        // the real assigned id. The raw $values bag has no such override.
        $idKeyForNewId = $this->entityType->getKeys()['id'] ?? 'id';
        $id = (string) ($values[$idKeyForNewId] ?? '');

        // Translatable entity types widen the base-table primary key to
        // (id, langcode), so the id column is a plain int shared across a row's
        // language peers rather than an autoincrement serial. The database will
        // not assign it, so allocate the next id here for a new entity. For
        // single-axis (non-translatable) types this is skipped and the serial
        // autoincrement id is used unchanged.
        if ($isNew && $id === '' && $this->entityType->isTranslatable()) {
            $idKey = $this->entityType->getKeys()['id'] ?? 'id';
            $nextId = $this->nextTranslatableBaseId($idKey);
            $id = (string) $nextId;
            $values[$idKey] = $nextId;
            if ($entity instanceof ContentEntityInterface) {
                if ($entity instanceof EntityBase) {
                    $entity->_hydrateStructuralId($nextId);
                } else {
                    $entity->set($idKey, $nextId);
                }
            }
        }

        // Wrap revision + base table writes in a transaction (invariant #4).
        // Skip if already inside a UnitOfWork transaction.
        $transaction = ($unitOfWork === null) ? $this->database?->transaction() : null;
        // A new entity with an auto-assigned id does not know its id until the
        // base row is inserted below. A community-scoped revision also requires
        // that stamped base row as its ownership anchor, even for an explicit
        // id. Defer either case until after the base insert in this transaction.
        $deferRevision = $createRevision
            && $this->revisionDriver !== null
            && ($id === '' || ($isNew && $this->revisionDriver->requiresBaseAnchor()));

        // Default-revision discipline (continued): whether this save's
        // base-row write happens at all. Undisciplined saves always write
        // the base row (today's behavior, byte-identical). A disciplined
        // revision-creating save never does (revision-only). A disciplined
        // in-place save writes the base row only when the revision being
        // updated IS the live published pointer — read from $originalEntity,
        // already loaded above (zero extra queries), never from the revision
        // row being (re)written.
        $writeBase = true;
        $originalPublishedRevisionId = null;
        if ($disciplined && $originalEntity !== null) {
            $rawOriginal = $this->extractPersistenceValues($originalEntity);
            $originalPublishedRevisionId = isset($rawOriginal['published_revision_id'])
                ? (int) $rawOriginal['published_revision_id']
                : null;
        }

        try {
            if ($createRevision && $this->revisionDriver !== null && !$deferRevision) {
                $log = $entity instanceof RevisionableInterface && isset($values['revision_log'])
                    ? (string) $values['revision_log']
                    : null;
                $revisionId = $this->writeRevisionRow($id, $values, $log, author: $actor);

                if ($expectedRevisionId !== null) {
                    // Authoritative guarded pointer claim (FR-004, contract
                    // §7–9): inside the SAME transaction as the revision write
                    // above and the full base write below — separating them
                    // would reopen the race. The SET always changes the value
                    // ($revisionId is freshly allocated, never equal to the
                    // expectation), so 0 affected rows means "predicate did
                    // not match" on every backend. The community scope
                    // condition is deliberately omitted: the claim is keyed on
                    // id + pointer, and the pre-loaded original entity already
                    // passed scope. (The deferred-revision branch is
                    // unreachable here: new entities were rejected above, and
                    // non-new entities always carry an id.)
                    // $this->database is non-null on every claim path — the
                    // no-database rejection at the top of doSave() guarantees
                    // it (PHPStan narrows the readonly property through that
                    // gate, so no re-check is needed here).
                    $revisionKey = $this->entityType->getKeys()['revision'] ?? 'revision_id';
                    $idKeyName = $this->entityType->getKeys()['id'] ?? 'id';
                    $claimed = $this->database->update($entityTypeId)
                        ->fields([$revisionKey => $revisionId])
                        ->condition($idKeyName, $id)
                        ->condition($revisionKey, $expectedRevisionId)
                        ->execute();
                    if ($claimed !== 1) {
                        // A competing writer moved the head between the
                        // pre-check and the claim. Roll back the whole
                        // transaction (the freshly written revision row
                        // included — no orphan revisions), re-read the
                        // now-current head, and throw the conflict carrying
                        // it. $transaction is nulled FIRST so the generic
                        // catch below cannot double-rollback (DBALTransaction
                        // throws on a second rollBack()); every other
                        // throwable keeps the existing catch semantics
                        // byte-identical.
                        $claimTransaction = $transaction;
                        $transaction = null;
                        $claimTransaction?->rollBack();

                        $currentRow = $this->readDriverRow($entityTypeId, $id);
                        $currentHead = null;
                        if ($currentRow !== null && (int) ($currentRow[$revisionKey] ?? 0) > 0) {
                            $currentHead = (int) $currentRow[$revisionKey];
                        }

                        throw new RevisionConflictException($entityTypeId, $id, $expectedRevisionId, $currentHead);
                    }
                }

                if ($disciplined) {
                    // Revision-only save (CW-v1 option-1, §2.1): the base
                    // row must not advance past the published pointer, so
                    // $values keeps its PRE-save revision_id and the base
                    // write below is skipped entirely. The in-memory entity
                    // still gets its new tip id (next line, unconditional).
                    $writeBase = false;
                } else {
                    $values['revision_id'] = $revisionId;
                }
                if ($entity instanceof ContentEntityInterface) {
                    $revisionKey = $this->entityType->getKeys()['revision'] ?? 'revision_id';
                    if ($entity instanceof EntityBase) {
                        $entity->_hydrateStructuralRevision(
                            $revisionId,
                            tip: true,
                            default: !$disciplined,
                        );
                    } else {
                        $entity->set($revisionKey, $revisionId);
                    }
                }
            } elseif (!$createRevision && !$isNew && $this->revisionDriver !== null && $entity instanceof RevisionableInterface) {
                $currentRevisionId = $entity->getRevisionId();
                if ($currentRevisionId !== null) {
                    $this->updateRevisionRow($id, $currentRevisionId, $values);
                }
                if ($disciplined) {
                    // In-place edit under discipline (§2.1): reaches the base
                    // row only when the revision just updated IS the live
                    // published pointer (the promote-flow status-flip save,
                    // or a sanctioned in-place edit of the published
                    // revision). An in-place edit of a diverged (non-
                    // published) tip stays revision-only. Compare as ints;
                    // no base write when the pointer is unset/null.
                    $writeBase = $originalPublishedRevisionId !== null
                        && $currentRevisionId !== null
                        && $originalPublishedRevisionId === $currentRevisionId;
                }
            } elseif ($disciplined && !$createRevision) {
                // Disciplined in-place save the legacy-interface branch above
                // did not take (a trait-only RevisionableEntityInterface class
                // — such entities get no in-place revision update today,
                // pre-existing). Same published-pointer rule, resolved via the
                // trait's own revisionId(); FAIL CLOSED when unresolvable:
                // under discipline the base row serves the published revision,
                // and a revision-side no-op is strictly safer than leaking
                // draft values into the served row.
                $traitRevisionId = ($entity instanceof RevisionableEntityInterface && \is_int($entity->revisionId()))
                    ? $entity->revisionId()
                    : null;
                $writeBase = $originalPublishedRevisionId !== null
                    && $traitRevisionId !== null
                    && $originalPublishedRevisionId === $traitRevisionId;
            }

            // Bundle-aware write: pull this content type's column-stored bundle
            // fields out of the base row so they persist in the per-bundle
            // subtable (real typed columns) instead of the base `_data` blob.
            // FieldStorage::Data bundle fields stay in the base row. If the
            // subtable is somehow absent, fold the values back into the base row
            // (never a silent drop) and log.
            //
            // Skipped entirely under default-revision discipline when
            // $writeBase is false (§2.1): the subtable columns are
            // per-entity, so writing them here would leak draft values into
            // query joins — the revision row already snapshots the full
            // pre-partition bag above.
            $writtenId = $id;
            if ($writeBase) {
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

                $writtenId = $this->writeDriverRow($entityTypeId, $id, $baseValues);

                if ($gateway !== null && $bundleValues !== [] && $bundleName !== null) {
                    $persistId = ($id !== '') ? $id : $writtenId;
                    $gateway->upsert($bundleName, $persistId, $bundleValues);
                }
            }

            if ($deferRevision && $writtenId !== '') {
                // The base row now exists with a real id. Write revision 1 keyed
                // on it, then point the base row at it by updating only the
                // revision-pointer column (leaving the _data blob untouched).
                $log = $entity instanceof RevisionableInterface && isset($values['revision_log'])
                    ? (string) $values['revision_log']
                    : null;
                $revisionId = $this->writeRevisionRow($writtenId, $values, $log, author: $actor);
                $revisionKey = $this->entityType->getKeys()['revision'] ?? 'revision_id';
                $idKeyName = $this->entityType->getKeys()['id'] ?? 'id';
                $this->database?->update($entityTypeId)
                    ->fields([$revisionKey => $revisionId])
                    ->condition($idKeyName, $writtenId)
                    ->execute();
                if ($entity instanceof ContentEntityInterface) {
                    if ($entity instanceof EntityBase) {
                        $entity->_hydrateStructuralRevision($revisionId, tip: true, default: true);
                    } else {
                        $entity->set($revisionKey, $revisionId);
                    }
                }
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
            if ($entity instanceof EntityBase) {
                $entity->_hydrateStructuralId(is_numeric($writtenId) ? (int) $writtenId : $writtenId);
            }
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
        if ($this->revisionDriver !== null) {
            $this->revisionDriver->assertEntityMutationAllowed((string) $entity->id());
        }

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

    /**
     * Dispatch a lifecycle event, buffering it until after commit when a
     * UnitOfWork batch is in flight.
     *
     * Buffering is now POST/AFTER-only: doSave()'s PRE_SAVE and
     * BeforeSaveEvent dispatch sites deliberately do NOT pass $unitOfWork
     * (audit-remediation batch 2026-07-02, WP2 review) — pre-write events
     * fire immediately inside the batch transaction so guarding listeners
     * run before the write and a BeforeSaveEvent abort rolls back the
     * whole batch. FOLLOW-UP (delete-path symmetry): doDelete() still
     * buffers PRE_DELETE under deleteMany(), so batch deletes bypass
     * pre-delete guards (e.g. RelationshipDeleteGuardListener — documented
     * in #1852 as single-delete-only). Aligning the delete path with the
     * save path's immediate-PRE semantics is deliberately out of scope
     * here; it changes #1852's documented behavior and needs its own
     * blast-radius pass.
     */
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

        $row = $this->readRevisionRow($entityId, $revisionId);
        if ($row === null) {
            return null;
        }

        // Inject the entity ID back (revision table uses entity_id, not the id key).
        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';
        $row[$idKey] = $row['entity_id'];

        // Determine if this revision is the current default.
        $baseRow = $this->readDriverRow($this->entityType->id(), $entityId);
        $currentRevId = $baseRow !== null ? (int) ($baseRow['revision_id'] ?? 0) : 0;
        $latestRevId = $this->revisionDriver->getLatestRevisionId($entityId);
        $row['is_default_revision'] = ($revisionId === $currentRevId);
        $row['is_latest_revision'] = ($revisionId === $latestRevId);

        $entity = $this->hydrate($row);

        // Propagate the new-contract revision state onto the entity so
        // RevisionableEntityInterface::revisionId() / isCurrentRevision() are
        // accurate for a historical revision (the trait defaults to "current").
        if ($entity instanceof RevisionableEntityInterface) {
            if (method_exists($entity, 'setRevisionId')) {
                $entity->setRevisionId($revisionId);
            }
            if (method_exists($entity, 'setIsCurrentRevision')) {
                $entity->setIsCurrentRevision($revisionId === $currentRevId);
            }
            // Hydrate the per-revision metadata read model (author/created/log)
            // from the raw row (FR-001 readback, clauses 7–9). listRevisions()
            // inherits this via loadRevision().
            $this->attachRevisionMetadata($entity, $row);
        }

        return $entity;
    }

    /**
     * Load the entity's working copy: the tip revision when it has diverged
     * from the base row's `revision_id` pointer, otherwise {@see find()}.
     *
     * @see EntityRepositoryInterface::loadWorkingCopy() for the full contract.
     */
    public function loadWorkingCopy(string $id): ?EntityInterface
    {
        if ($this->revisionDriver === null) {
            return $this->find($id);
        }

        $latestRevisionId = $this->revisionDriver->getLatestRevisionId($id);
        if ($latestRevisionId === null) {
            return $this->find($id);
        }

        $baseRow = $this->readDriverRow($this->entityType->id(), $id);
        $baseRevisionId = $baseRow !== null ? (int) ($baseRow['revision_id'] ?? 0) : 0;

        if ($latestRevisionId > $baseRevisionId) {
            return $this->loadRevision($id, $latestRevisionId);
        }

        return $this->find($id);
    }

    public function rollback(string $entityId, int $targetRevisionId): EntityInterface
    {
        if ($this->revisionDriver === null) {
            throw new \LogicException('Revision driver not configured for entity type ' . $this->entityType->id());
        }
        $this->revisionDriver->assertEntityMutationAllowed($entityId);

        // Revert authorship (clause 4): the NEW revision is authored by whoever
        // performs the revert — resolved once, from the ambient context (no
        // SaveContext on this path). The target revision row is never modified.
        $actor = $this->resolveActor(null);

        // Load the target revision.
        $targetRow = $this->readRevisionRow($entityId, $targetRevisionId);
        if ($targetRow === null) {
            throw new \InvalidArgumentException(
                "Revision {$targetRevisionId} does not exist for entity {$entityId}.",
            );
        }

        // Bypass-choke-point pre-event (CW-v1 WP-2 task 2.4, #1920): dispatched
        // BEFORE any write, so a throwing subscriber (the forthcoming workflow
        // pointer-move guard, task 2.5) leaves storage completely untouched.
        // $toRevisionId is null: the new revision's id is assigned by
        // writeRevision() below, not knowable yet.
        $priorBaseRow = $this->readDriverRow($this->entityType->id(), $entityId);
        $fromRevisionId = null;
        if ($priorBaseRow !== null && (int) ($priorBaseRow['revision_id'] ?? 0) > 0) {
            $fromRevisionId = (int) $priorBaseRow['revision_id'];
        }
        $beforeEvent = new BeforeRevisionPointerMoveEvent(
            entityTypeId: $this->entityType->id(),
            entityId: $entityId,
            operation: 'rollback',
            fromRevisionId: $fromRevisionId,
            toRevisionId: null,
            actorUid: $actor,
            revisionValues: $targetRow,
        );
        $this->dispatchEvent($beforeEvent, BeforeRevisionPointerMoveEvent::class);

        // Remove revision metadata from the row — we're creating a new revision.
        // revision_author included: the old revision's author must not leak
        // onto the new revision or into the base row.
        unset($targetRow['revision_id'], $targetRow['revision_created'], $targetRow['revision_log'], $targetRow['revision_author'], $targetRow['entity_id']);

        // Invariant (WP-2 rework, review finding #4 containment): revision-restore
        // operations restore CONTENT; they never move the published pointer or
        // flip status — those belong exclusively to TransitionService (CW-v1
        // decision 2). The target revision's frozen published_revision_id/status
        // snapshot must not overwrite the live base row's values. Reuse the base
        // row already read above ($priorBaseRow) rather than re-reading.
        foreach (['published_revision_id', 'status'] as $pointerKey) {
            if ($priorBaseRow !== null && array_key_exists($pointerKey, $priorBaseRow)) {
                $targetRow[$pointerKey] = $priorBaseRow[$pointerKey];
            } else {
                unset($targetRow[$pointerKey]);
            }
        }
        $targetRow = $this->canonicalizeBooleanFieldValues($targetRow, entityId: $entityId);

        // Wrap in transaction (invariant #4: atomic pointer update).
        $transaction = $this->database?->transaction();
        try {
            $log = "Reverted to revision {$targetRevisionId}";
            $newRevisionId = $this->writeRevisionRow($entityId, $targetRow, $log, author: $actor);

            if (!$beforeEvent->defaultRevisionSemantics()) {
                // Update the base table pointer. Skipped under
                // default-revision discipline (CW-v1 option-1, §2.3): the
                // restored content becomes a new tip revision ONLY — the
                // base row (which holds the PUBLISHED revision under
                // discipline) is fully untouched. Restoring old content into
                // the working copy is a draft operation; it goes live via
                // the normal promotion path (setPublishedRevision()).
                $keys = $this->entityType->getKeys();
                $idKey = $keys['id'] ?? 'id';
                $targetRow[$idKey] = $entityId;
                $targetRow['revision_id'] = $newRevisionId;
                $this->writeDriverRow($this->entityType->id(), $entityId, $targetRow);
            }

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
     * List an entity's revisions, newest first, each hydrated with revision
     * metadata (revision_id, revision_created, revision_log, is_default_revision,
     * is_latest_revision). The high-level companion to loadRevision()/rollback().
     *
     * @return list<EntityInterface>
     */
    public function listRevisions(string $entityId): array
    {
        if ($this->revisionDriver === null) {
            throw new \LogicException('Revision driver not configured for entity type ' . $this->entityType->id());
        }

        $revisionIds = $this->revisionDriver->getRevisionIds($entityId);
        rsort($revisionIds);

        $revisions = [];
        foreach ($revisionIds as $revisionId) {
            $entity = $this->loadRevision($entityId, $revisionId);
            if ($entity !== null) {
                $revisions[] = $entity;
            }
        }

        return $revisions;
    }

    /**
     * Make an existing revision the current/default revision by moving the
     * base-table pointer in place — WITHOUT creating a new revision.
     *
     * Use this to "switch" the published revision (e.g. revert a bad edit back
     * to a known-good revision while keeping linear history untouched). Prefer
     * rollback() when the revert should itself be recorded as a fresh revision
     * at the head of history.
     */
    public function setCurrentRevision(string $entityId, int $revisionId): EntityInterface
    {
        if ($this->revisionDriver === null) {
            throw new \LogicException('Revision driver not configured for entity type ' . $this->entityType->id());
        }
        $this->revisionDriver->assertEntityMutationAllowed($entityId);

        $row = $this->readRevisionRow($entityId, $revisionId);
        if ($row === null) {
            throw new \InvalidArgumentException(
                "Revision {$revisionId} does not exist for entity {$entityId}.",
            );
        }

        // Pointer-move actor + prior pointer for the transition event (FR-006).
        $actor = $this->resolveActor(null);
        $priorBaseRow = $this->readDriverRow($this->entityType->id(), $entityId);
        $fromRevisionId = null;
        if ($priorBaseRow !== null && (int) ($priorBaseRow['revision_id'] ?? 0) > 0) {
            $fromRevisionId = (int) $priorBaseRow['revision_id'];
        }

        // Bypass-choke-point pre-event (CW-v1 WP-2 task 2.4, #1920): dispatched
        // BEFORE any write. $toRevisionId is the caller-supplied target
        // revision id — already known, unlike rollback()'s freshly-assigned one.
        $this->dispatchEvent(
            new BeforeRevisionPointerMoveEvent(
                entityTypeId: $this->entityType->id(),
                entityId: $entityId,
                operation: 'revert',
                fromRevisionId: $fromRevisionId,
                toRevisionId: $revisionId,
                actorUid: $actor,
                revisionValues: $row,
            ),
            BeforeRevisionPointerMoveEvent::class,
        );

        // Re-point the base table at this revision's values. Strip revision-table
        // bookkeeping columns; the base table tracks the current revision via the
        // revision_id pointer column.
        unset($row['revision_created'], $row['revision_log'], $row['revision_author'], $row['entity_id']);

        // Invariant (WP-2 rework, review finding #4 containment): revision-restore
        // operations restore CONTENT; they never move the published pointer or
        // flip status — those belong exclusively to TransitionService (CW-v1
        // decision 2). The target revision's frozen published_revision_id/status
        // snapshot must not overwrite the live base row's values. Reuse the base
        // row already read above ($priorBaseRow) rather than re-reading.
        foreach (['published_revision_id', 'status'] as $pointerKey) {
            if ($priorBaseRow !== null && array_key_exists($pointerKey, $priorBaseRow)) {
                $row[$pointerKey] = $priorBaseRow[$pointerKey];
            } else {
                unset($row[$pointerKey]);
            }
        }
        $row = $this->canonicalizeBooleanFieldValues($row, entityId: $entityId);

        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';
        $row[$idKey] = $entityId;
        $row['revision_id'] = $revisionId;

        $transaction = $this->database?->transaction();
        try {
            $this->writeDriverRow($this->entityType->id(), $entityId, $row);
            $transaction?->commit();
        } catch (\Throwable $e) {
            $transaction?->rollBack();
            throw $e;
        }

        $entity = $this->loadRevision($entityId, $revisionId);

        $this->dispatchEvent(
            $this->eventFactory->create($entity),
            EntityEvents::REVISION_REVERTED->value,
        );

        // Typed pointer-transition event (FR-006, research D4) — dispatched by
        // FQCN AFTER the pointer transaction committed (a rolled-back move
        // throws above and produces no event), alongside — not replacing —
        // the legacy REVISION_REVERTED dispatch.
        $this->dispatchEvent(
            new RevisionPointerMovedEvent(
                entityTypeId: $this->entityType->id(),
                entityId: $entityId,
                operation: 'revert',
                fromRevisionId: $fromRevisionId,
                toRevisionId: $revisionId,
                actorUid: $actor,
            ),
            RevisionPointerMovedEvent::class,
        );

        return $entity;
    }

    /**
     * Load the entity's published revision, or null when nothing is published.
     *
     * Reads the base-table `published_revision_id` pointer (separate from the
     * current/latest `revision_id` pointer) and hydrates that revision. Returns
     * null when the pointer is NULL/absent — an unpublished entity, or a base
     * table predating the published-pointer column — so the call is safe and
     * backward-compatible on any revisionable entity type.
     */
    public function loadPublishedRevision(string $entityId): ?EntityInterface
    {
        if ($this->revisionDriver === null) {
            throw new \LogicException('Revision driver not configured for entity type ' . $this->entityType->id());
        }

        $baseRow = $this->readDriverRow($this->entityType->id(), $entityId);
        if ($baseRow === null) {
            return null;
        }

        $publishedRevisionId = $baseRow['published_revision_id'] ?? null;
        if ($publishedRevisionId === null || (int) $publishedRevisionId <= 0) {
            return null;
        }

        return $this->loadRevision($entityId, (int) $publishedRevisionId);
    }

    /**
     * Promote an existing revision to be the published revision by moving ONLY
     * the base-table `published_revision_id` pointer — the current/latest
     * revision (the working draft) and the entity's field values are untouched,
     * so the live view and an in-progress draft can differ. Publishing an older
     * revision is how a live-view rollback works.
     */
    public function setPublishedRevision(string $entityId, int $revisionId): EntityInterface
    {
        if ($this->revisionDriver === null) {
            throw new \LogicException('Revision driver not configured for entity type ' . $this->entityType->id());
        }
        $this->revisionDriver->assertEntityMutationAllowed($entityId);

        // Validate the target revision exists for this entity.
        $targetRow = $this->readRevisionRow($entityId, $revisionId);
        if ($targetRow === null) {
            throw new \InvalidArgumentException(
                "Revision {$revisionId} does not exist for entity {$entityId}.",
            );
        }

        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';

        // Pointer-move actor + prior published pointer for the transition
        // event (FR-006). Null when previously unpublished.
        $actor = $this->resolveActor(null);

        // Bypass-choke-point pre-event (CW-v1 WP-2 task 2.4, #1920): dispatched
        // BEFORE any write. This is a plain read (not the write itself), done
        // ahead of the transaction purely to report fromRevisionId on the
        // pre-event; the transaction below still re-reads the prior pointer
        // fresh (unchanged) so the POST RevisionPointerMovedEvent's reported
        // transition matches exactly what this call committed.
        $earlyBaseRow = $this->readDriverRow($this->entityType->id(), $entityId);
        $earlyFromRevisionId = null;
        if ($earlyBaseRow !== null && (int) ($earlyBaseRow['published_revision_id'] ?? 0) > 0) {
            $earlyFromRevisionId = (int) $earlyBaseRow['published_revision_id'];
        }
        $beforeEvent = new BeforeRevisionPointerMoveEvent(
            entityTypeId: $this->entityType->id(),
            entityId: $entityId,
            operation: 'publish',
            fromRevisionId: $earlyFromRevisionId,
            toRevisionId: $revisionId,
            actorUid: $actor,
            revisionValues: $targetRow,
        );
        $this->dispatchEvent($beforeEvent, BeforeRevisionPointerMoveEvent::class);

        $fromRevisionId = null;

        $transaction = $this->database?->transaction();
        try {
            // Read the prior pointer inside the transaction so the from→to
            // transition the event reports is the one this move performed.
            $priorBaseRow = $this->readDriverRow($this->entityType->id(), $entityId);
            if ($priorBaseRow !== null && (int) ($priorBaseRow['published_revision_id'] ?? 0) > 0) {
                $fromRevisionId = (int) $priorBaseRow['published_revision_id'];
            }

            if ($beforeEvent->defaultRevisionSemantics()) {
                // Default-revision discipline (CW-v1 option-1, §2.2):
                // promotion becomes a COMPLETE primitive — a subscriber
                // (the workflows pointer-move guard, next PR) set the flag
                // on the pre-event above. The base row is written from the
                // TARGET revision's values (content, workflow_state, and its
                // own stored status — the whole snapshot, minus bookkeeping)
                // so it can never diverge from the pointer it claims to
                // serve, and BOTH pointers move to the target in the same
                // transaction. Bookkeeping keys stripped exactly like
                // rollback()/setCurrentRevision() strip them; the target
                // revision row itself is never mutated. Column-stored bundle
                // fields are partitioned and upserted from this same target
                // snapshot in the transaction; otherwise the subtable's old
                // value would override the promoted `_data` value on read.
                $targetRow = $this->canonicalizeBooleanFieldValues($targetRow, entityId: $entityId);
                $outgoingRow = $targetRow;
                $bundleValues = [];
                $bundleName = null;
                $gateway = $this->bundleGateway();
                if ($gateway !== null) {
                    $targetEntity = $this->instantiateEntity($this->entityType->getClass(), $targetRow);
                    [$outgoingRow, $bundleValues, $bundleName] = $gateway->partition($targetEntity, $targetRow);
                    if ($bundleValues !== [] && $bundleName !== null && !$gateway->subtableExists($bundleName)) {
                        $gateway->logMissingSubtableOnSave($bundleName, \count($bundleValues));
                        $outgoingRow = $targetRow;
                        $bundleValues = [];
                        $bundleName = null;
                    }
                }
                unset(
                    $outgoingRow['revision_id'],
                    $outgoingRow['revision_created'],
                    $outgoingRow['revision_log'],
                    $outgoingRow['revision_author'],
                    $outgoingRow['entity_id'],
                );
                $outgoingRow[$idKey] = $entityId;
                $outgoingRow['revision_id'] = $revisionId;
                $outgoingRow['published_revision_id'] = $revisionId;
                $this->writeDriverRow($this->entityType->id(), $entityId, $outgoingRow);
                if ($gateway !== null && $bundleValues !== [] && $bundleName !== null) {
                    $gateway->upsert($bundleName, $entityId, $bundleValues);
                }
            } elseif ($this->database !== null) {
                // Targeted single-column update: touch only the published pointer.
                $this->database->update($this->entityType->id())
                    ->fields(['published_revision_id' => $revisionId])
                    ->condition($idKey, $entityId)
                    ->execute();
            } else {
                // Driver-only fallback (no DatabaseInterface wired): round-trip
                // the base row, flipping just the published pointer.
                if ($priorBaseRow === null) {
                    throw new \InvalidArgumentException("Entity {$entityId} does not exist.");
                }
                $baseRow = $priorBaseRow;
                $baseRow['published_revision_id'] = $revisionId;
                $baseRow = $this->canonicalizeBooleanFieldValues($baseRow, entityId: $entityId);
                $this->writeDriverRow($this->entityType->id(), $entityId, $baseRow);
            }
            $transaction?->commit();
        } catch (\Throwable $e) {
            $transaction?->rollBack();
            throw $e;
        }

        $entity = $this->loadPublishedRevision($entityId);
        if ($entity === null) {
            throw new \LogicException(
                "Failed to load published revision {$revisionId} for entity {$entityId} after publishing.",
            );
        }

        $this->dispatchEvent(
            $this->eventFactory->create($entity),
            EntityEvents::REVISION_REVERTED->value,
        );

        // Typed pointer-transition event (FR-006, research D4) — by FQCN,
        // AFTER the pointer transaction committed (a rolled-back move throws
        // above and produces no event), alongside the legacy dispatch.
        $this->dispatchEvent(
            new RevisionPointerMovedEvent(
                entityTypeId: $this->entityType->id(),
                entityId: $entityId,
                operation: 'publish',
                fromRevisionId: $fromRevisionId,
                toRevisionId: $revisionId,
                actorUid: $actor,
            ),
            RevisionPointerMovedEvent::class,
        );

        return $entity;
    }

    // ---------------------------------------------------------------------
    // Translation axis (two-axis: revisionable + translatable)
    //
    // Optional second axis. A revisionable + translatable entity keeps per-
    // language revision history in `<entity>__translation__revision` with
    // INDEPENDENT sequencing per (entity, langcode): editing one language does
    // not bump another's revision count. These methods are additive and only
    // valid on a two-axis type; the single-axis revision path above is untouched.
    // ---------------------------------------------------------------------

    /**
     * Write a new revision of one language's content, returning its per-language
     * revision id (independent of other languages and of the single-axis sequence).
     *
     * @param array<string, mixed> $values Field values for this language.
     */
    public function saveTranslationRevision(string $entityId, string $langcode, array $values, ?string $log = null): int
    {
        $driver = $this->assertTwoAxis(__FUNCTION__);
        $driver->assertEntityMutationAllowed($entityId);

        // Author resolved once per operation (FR-001; ambient context — this
        // path carries no SaveContext).
        $actor = $this->resolveActor(null);

        // Bypass-choke-point pre-event (CW-v1 WP-2 task 2.4, #1920): dispatched
        // BEFORE the write. No transaction wraps this single-language write, so
        // a throwing subscriber simply prevents writeRevision() from running.
        $this->dispatchEvent(
            new BeforeRevisionPointerMoveEvent(
                entityTypeId: $this->entityType->id(),
                entityId: $entityId,
                operation: 'translation_save',
                fromRevisionId: $driver->getLatestLangcodeRevisionId($entityId, $langcode),
                toRevisionId: null,
                actorUid: $actor,
                revisionValues: $values,
            ),
            BeforeRevisionPointerMoveEvent::class,
        );

        $values = $this->canonicalizeBooleanFieldValues($values, entityId: $entityId);
        $revisionId = $this->writeRevisionRow($entityId, $values, $log, $langcode, $actor);

        $entity = $this->loadTranslationRevision($entityId, $langcode, $revisionId);
        if ($entity !== null) {
            $this->dispatchEvent($this->eventFactory->create($entity), EntityEvents::REVISION_CREATED->value);
        }

        return $revisionId;
    }

    /**
     * Atomic multi-language write: one revision per langcode in a single
     * transaction, all-or-nothing. Other languages' sequences are independent.
     *
     * @param array<string, array<string, mixed>> $byLangcode langcode => field values
     * @return array<string, int> langcode => new per-language revision id
     */
    public function saveTranslationRevisions(string $entityId, array $byLangcode, ?string $log = null): array
    {
        $driver = $this->assertTwoAxis(__FUNCTION__);
        $driver->assertEntityMutationAllowed($entityId);
        if ($byLangcode === []) {
            throw new \InvalidArgumentException('saveTranslationRevisions requires at least one langcode.');
        }

        // Author resolved ONCE for the whole multi-language operation — every
        // language row carries the same value (FR-001; never per langcode).
        $actor = $this->resolveActor(null);

        $transaction = $this->database?->transaction();
        $created = [];
        try {
            foreach ($byLangcode as $langcode => $values) {
                // Bypass-choke-point pre-event (CW-v1 WP-2 task 2.4, #1920):
                // dispatched BEFORE this langcode's write. A throwing
                // subscriber propagates out of the try block below, rolling
                // back the WHOLE batch — including any earlier langcode
                // already written in this same transaction — mirroring
                // BeforeSaveEvent's mid-batch abort contract in saveMany().
                $this->dispatchEvent(
                    new BeforeRevisionPointerMoveEvent(
                        entityTypeId: $this->entityType->id(),
                        entityId: $entityId,
                        operation: 'translation_save',
                        fromRevisionId: $driver->getLatestLangcodeRevisionId($entityId, $langcode),
                        toRevisionId: null,
                        actorUid: $actor,
                        revisionValues: $values,
                    ),
                    BeforeRevisionPointerMoveEvent::class,
                );

                $values = $this->canonicalizeBooleanFieldValues($values, entityId: $entityId);
                $created[$langcode] = $this->writeRevisionRow($entityId, $values, $log, $langcode, $actor);
            }
            $transaction?->commit();
        } catch (\Throwable $e) {
            $transaction?->rollBack();
            throw $e;
        }

        foreach ($created as $langcode => $revisionId) {
            $entity = $this->loadTranslationRevision($entityId, $langcode, $revisionId);
            if ($entity !== null) {
                $this->dispatchEvent($this->eventFactory->create($entity), EntityEvents::REVISION_CREATED->value);
            }
        }

        return $created;
    }

    /**
     * Load a specific per-language revision, or null when it does not exist.
     */
    public function loadTranslationRevision(string $entityId, string $langcode, int $revisionId): ?EntityInterface
    {
        $driver = $this->assertTwoAxis(__FUNCTION__);

        $row = $this->readLangcodeRevisionRow($entityId, $langcode, $revisionId);
        if ($row === null) {
            return null;
        }

        return $this->hydrateTranslationRow($driver, $entityId, $langcode, $revisionId, $row);
    }

    /**
     * Load the tip (latest revision) of one language, or null when that language
     * has no revisions yet.
     */
    public function loadTranslationTip(string $entityId, string $langcode): ?EntityInterface
    {
        $driver = $this->assertTwoAxis(__FUNCTION__);

        $latest = $driver->getLatestLangcodeRevisionId($entityId, $langcode);
        if ($latest === null) {
            return null;
        }

        return $this->loadTranslationRevision($entityId, $langcode, $latest);
    }

    /**
     * One language's revisions, newest first.
     *
     * @return list<EntityInterface>
     */
    public function listTranslationRevisions(string $entityId, string $langcode): array
    {
        $driver = $this->assertTwoAxis(__FUNCTION__);

        $ids = $driver->getLangcodeRevisionIds($entityId, $langcode);
        rsort($ids);

        $out = [];
        foreach ($ids as $rid) {
            $entity = $this->loadTranslationRevision($entityId, $langcode, $rid);
            if ($entity !== null) {
                $out[] = $entity;
            }
        }

        return $out;
    }

    /**
     * Langcodes this entity carries a translation revision for, ascending.
     *
     * @return string[]
     */
    public function translationLangcodes(string $entityId): array
    {
        $driver = $this->assertTwoAxis(__FUNCTION__);

        return $driver->getLangcodesWithRevisions($entityId);
    }

    /**
     * Unified two-axis save of one language's content: in a single transaction,
     * upsert the peer `(id, langcode)` base row that holds this language's
     * current values AND record a per-language revision. The peer row and its
     * history move together, so a language is a true peer (its own base row and
     * its own independent revision sequence), not an overlay on another
     * language's row. The default-language row and any non-translatable fields
     * are untouched.
     *
     * This is the single repository entry point for editing a translation; the
     * `(id, langcode)` row is created on first save for a new language.
     *
     * @param array<string, mixed> $values This language's field values.
     * @return int The new per-language revision id.
     */
    public function saveTranslation(string $entityId, string $langcode, array $values, ?string $log = null): int
    {
        $driver = $this->assertTwoAxis(__FUNCTION__);
        $driver->assertEntityMutationAllowed($entityId);
        if ($this->database === null) {
            throw new \LogicException(
                'saveTranslation requires a database connection for entity type ' . $this->entityType->id(),
            );
        }

        $values = $this->canonicalizeBooleanFieldValues($values, entityId: $entityId);
        [$peerDriver, $defaultLangcode, $peerSnapshot] = $this->prepareLangcodePeerWrite(
            $entityId,
            $langcode,
            $values,
        );

        // Author resolved once per operation (FR-001).
        $actor = $this->resolveActor(null);

        $transaction = $this->database->transaction();
        try {
            // Refuse foreign, ownerless, and conflicting exact peers before an
            // event subscriber can observe the attempted pointer move. The
            // write repeats this check inside the same transaction as defense
            // in depth against direct capability callers.
            $peerDriver->assertLangcodePeerMutationAllowed(
                $this->entityType->id(),
                $entityId,
                $langcode,
                $defaultLangcode,
                $peerSnapshot,
            );

            // Bypass-choke-point pre-event (CW-v1 WP-2 task 2.4, #1920):
            // dispatched BEFORE the peer-row upsert or the revision write. A
            // throwing subscriber propagates out of this try block, rolling
            // back the transaction before either write is committed.
            $this->dispatchEvent(
                new BeforeRevisionPointerMoveEvent(
                    entityTypeId: $this->entityType->id(),
                    entityId: $entityId,
                    operation: 'translation_save',
                    fromRevisionId: $driver->getLatestLangcodeRevisionId($entityId, $langcode),
                    toRevisionId: null,
                    actorUid: $actor,
                    revisionValues: $values,
                ),
                BeforeRevisionPointerMoveEvent::class,
            );

            $peerDriver->writeLangcodePeer(
                $this->entityType->id(),
                $entityId,
                $langcode,
                $defaultLangcode,
                $peerSnapshot,
            );
            $revisionId = $this->writeRevisionRow($entityId, $values, $log, $langcode, $actor);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $entity = $this->loadTranslation($entityId, $langcode);
        if ($entity !== null) {
            $this->dispatchEvent($this->eventFactory->create($entity), EntityEvents::REVISION_CREATED->value);
        }

        return $revisionId;
    }

    /**
     * Load the current value of one language from its peer `(id, langcode)` base
     * row, or null when that language has no row yet.
     */
    public function loadTranslation(string $entityId, string $langcode): ?EntityInterface
    {
        $this->assertTwoAxis(__FUNCTION__);

        $row = $this->readDriverRow($this->entityType->id(), $entityId, $langcode);
        if ($row === null) {
            return null;
        }

        // A two-axis read is exact-or-nothing: if a driver-level language
        // fallback returned a different language's row (e.g. the default), treat
        // this language as untranslated rather than surfacing the wrong content.
        $langKey = $this->entityType->getKeys()['langcode'] ?? 'langcode';
        $rowLangcode = $row[$langKey] ?? null;
        if ($rowLangcode !== null && (string) $rowLangcode !== $langcode) {
            return null;
        }

        $entity = $this->hydrate($row);
        if ($entity instanceof EntityBase) {
            $driver = $this->assertTwoAxis(__FUNCTION__);
            $known = $driver->getLangcodesWithRevisions($entityId);
            if (!in_array($langcode, $known, true)) {
                $known[] = $langcode;
            }
            sort($known);
            $default = isset($row['default_langcode']) && (string) $row['default_langcode'] !== ''
                ? (string) $row['default_langcode']
                : $known[0];
            $entity->_hydrateStructuralLanguages($langcode, $default, $known);
            $revisionKey = $this->entityType->getKeys()['revision'] ?? 'revision_id';
            $revisionId = $row[$revisionKey] ?? null;
            if (is_int($revisionId) || is_string($revisionId)) {
                $latest = $driver->getLatestLangcodeRevisionId($entityId, $langcode);
                $entity->_hydrateStructuralRevision($revisionId, (int) $revisionId === $latest, true);
            }
        }

        return $entity;
    }

    /**
     * Upsert the peer `(id, langcode)` base row carrying this language's values.
     *
     * For a blob-primary entity, non-system values ride the `_data` blob; the
     * label column mirrors the label field when supplied. A new peer row copies
     * the shared identity (`uuid`) from the default row so the partial-unique
     * UUID index (which only constrains default-langcode rows) is satisfied.
     *
     * @param array<string, mixed> $values
     * @return array{LangcodePeerStorageDriverV2Interface, string, StorageSnapshot}
     */
    private function prepareLangcodePeerWrite(string $entityId, string $langcode, array $values): array
    {
        $table = $this->entityType->id();
        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';
        $langKey = $keys['langcode'] ?? 'langcode';
        $labelKey = $keys['label'] ?? 'label';
        $values[$idKey] = $entityId;
        $values[$langKey] = $langcode;
        if ($labelKey !== '' && isset($values[$labelKey])) {
            $values[$labelKey] = (string) $values[$labelKey];
        }
        $defaultRow = $this->readDriverRow($table, $entityId);
        if (isset($keys['uuid'])) {
            if ($defaultRow !== null && isset($defaultRow[$keys['uuid']])) {
                $values[$keys['uuid']] = $defaultRow[$keys['uuid']];
            }
        }
        $defaultLangKey = $keys['default_langcode'] ?? 'default_langcode';
        $defaultLangcode = $defaultRow[$defaultLangKey] ?? $defaultRow[$langKey] ?? null;
        if (!is_string($defaultLangcode) || $defaultLangcode === '') {
            throw new \LogicException(sprintf(
                'Langcode peer writes require a canonical default-language row for entity type "%s" and id "%s".',
                $table,
                $entityId,
            ));
        }
        $values[$defaultLangKey] = $defaultLangcode;

        if (!$this->driver instanceof LangcodePeerStorageDriverV2Interface) {
            throw new \LogicException(sprintf(
                'Storage driver for entity type "%s" does not support langcode peer writes.',
                $table,
            ));
        }
        return [
            $this->driver,
            $defaultLangcode,
            $this->storageSnapshotFactory->create($values),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateTranslationRow(
        RevisionableStorageDriverV2Interface $driver,
        string $entityId,
        string $langcode,
        int $revisionId,
        array $row,
    ): EntityInterface {
        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';
        $langKey = $keys['langcode'] ?? 'langcode';

        $row[$idKey] = $row['entity_id'] ?? $entityId;
        $row[$langKey] = $langcode;
        $row['revision_id'] = $revisionId;
        $latest = $driver->getLatestLangcodeRevisionId($entityId, $langcode);
        $row['is_latest_revision'] = ($revisionId === $latest);
        $baseRow = $this->readDriverRow($this->entityType->id(), $entityId, $langcode);
        $baseRevisionId = $baseRow === null ? null : (int) ($baseRow['revision_id'] ?? 0);
        $row['is_default_revision'] = ($baseRevisionId !== null && $baseRevisionId > 0 && $revisionId === $baseRevisionId);

        $entity = $this->hydrate($row);
        if ($entity instanceof EntityBase) {
            $known = $driver->getLangcodesWithRevisions($entityId);
            sort($known);
            $default = isset($row['default_langcode']) && (string) $row['default_langcode'] !== ''
                ? (string) $row['default_langcode']
                : ($known[0] ?? $langcode);
            $entity->_hydrateStructuralLanguages($langcode, $default, $known);
            $entity->_hydrateStructuralRevision(
                $revisionId,
                $revisionId === $latest,
                $row['is_default_revision'],
            );
        }

        if ($entity instanceof RevisionableEntityInterface) {
            if (method_exists($entity, 'setRevisionId')) {
                $entity->setRevisionId($revisionId);
            }
            if (method_exists($entity, 'setIsCurrentRevision')) {
                $entity->setIsCurrentRevision($revisionId === $latest);
            }
            // Same metadata hydration as the single-axis loadRevision() path
            // (clauses 7–9) — translation-revision rows carry the same
            // revision_created / revision_author / revision_log columns.
            $this->attachRevisionMetadata($entity, $row);
        }

        return $entity;
    }

    private function assertTwoAxis(string $method): RevisionableStorageDriverV2Interface
    {
        if ($this->revisionDriver === null) {
            throw new \LogicException('Revision driver not configured for entity type ' . $this->entityType->id());
        }
        if (!$this->entityType->isRevisionable() || !$this->entityType->isTranslatable()) {
            throw new \LogicException(\sprintf(
                'EntityRepository::%s requires a revisionable + translatable (two-axis) entity type; '
                . '"%s" is revisionable=%s translatable=%s.',
                $method,
                $this->entityType->id(),
                $this->entityType->isRevisionable() ? 'true' : 'false',
                $this->entityType->isTranslatable() ? 'true' : 'false',
            ));
        }

        return $this->revisionDriver;
    }

    /**
     * Allocate the next base-table id for a new translatable entity type.
     *
     * Translatable types share one int id across language-peer rows (PK
     * `(id, langcode)`), so the database does not autoincrement it; `MAX(id)+1`
     * over the base table yields the next entity id. Single-axis types never
     * call this — they use the serial autoincrement id.
     */
    private function nextTranslatableBaseId(string $idKey): int
    {
        if ($this->database === null) {
            return 1;
        }

        foreach ($this->database->query(
            'SELECT MAX(' . $idKey . ') AS max_id FROM ' . $this->entityType->id(),
        ) as $row) {
            $row = (array) $row;

            return ((int) ($row['max_id'] ?? 0)) + 1;
        }

        return 1;
    }

    /**
     * Prune an entity's revision history per a retention policy so revision
     * tables do not grow unbounded — opt-in (the framework never auto-prunes on
     * save, FR-039). Keeps the newest N revisions and NEVER deletes the current
     * revision (FR-038, enforced via {@see RevisionPruningPolicy::candidateExcluded()})
     * OR the published revision (FR-038's guard extended to the published
     * pointer, #1920 WP-2 rework task 5 / review finding #6 — deleting it would
     * silently flip the entity into never-published semantics). The published
     * check stays inline here rather than in
     * {@see RevisionPruningPolicy::candidateExcluded()} because that method is
     * `@api` public surface and its signature must not change.
     *
     * A no-op policy ({@see RevisionPruningPolicy::default()}) returns a disabled
     * report and deletes nothing. Drive this from a CLI/scheduled task with a
     * policy such as {@see RevisionPruningPolicy::keepLastUniform()}.
     */
    public function pruneRevisions(string $entityId, RevisionPruningPolicy $policy): RevisionPruningReport
    {
        if ($this->revisionDriver === null) {
            throw new \LogicException('Revision driver not configured for entity type ' . $this->entityType->id());
        }
        $this->revisionDriver->assertEntityMutationAllowed($entityId);

        if ($policy->isNoOp()) {
            return RevisionPruningReport::disabled();
        }

        $revisionIds = array_map('intval', $this->revisionDriver->getRevisionIds($entityId));
        sort($revisionIds); // oldest -> newest
        $total = count($revisionIds);

        // The current (default) revision is immortal regardless of policy.
        $baseRow = $this->readDriverRow($this->entityType->id(), $entityId);
        $currentRevisionId = $baseRow !== null ? (int) ($baseRow['revision_id'] ?? 0) : 0;

        // The published revision is immortal too (FR-038 extension, #1920 task
        // 5). `?? null` tolerates pre-WP-2 base tables that lack the
        // `published_revision_id` column entirely — the key is simply absent
        // from the row array read above.
        $publishedRevisionId = $baseRow['published_revision_id'] ?? null;
        $publishedRevisionId = $publishedRevisionId !== null ? (int) $publishedRevisionId : null;

        // The LATEST revision is immortal too (CW-v1 option-1 PR-1, #1920).
        // Under default-revision discipline the base `revision_id` pointer
        // stops tracking the tip (it stays equal to `published_revision_id`
        // — see doSave()), so the working copy (the latest revision) is no
        // longer covered by the current-revision guard above. Without this,
        // a prune during a review window could destroy an in-progress draft.
        // `array_slice(..., -1)` on the sorted-ascending id list is cheaper
        // than a driver round-trip and always agrees with
        // `getLatestRevisionId()` (both read MAX(revision_id)).
        $latestRevisionId = $revisionIds !== [] ? $revisionIds[$total - 1] : null;

        $keep = $policy->keepLastNFor(RevisionPruningPolicy::DEFAULT_LANGCODE_KEY);
        if ($keep === null) {
            // No keep-count constraint applies to this entity — nothing to prune.
            return new RevisionPruningReport(candidatesFound: 0, pruned: 0, retained: $total);
        }

        $newestKept = $keep > 0 ? array_slice($revisionIds, -$keep) : [];

        $candidatesFound = 0;
        $pruned = 0;
        foreach ($revisionIds as $vid) {
            if (in_array($vid, $newestKept, true)) {
                continue;
            }
            ++$candidatesFound;
            if ($policy->candidateExcluded($vid, $currentRevisionId)) {
                continue; // never delete the current revision
            }
            if ($publishedRevisionId !== null && $vid === $publishedRevisionId) {
                continue; // never delete the published revision (FR-038 extension, #1920 task 5)
            }
            if ($latestRevisionId !== null && $vid === $latestRevisionId) {
                continue; // never delete the latest/working-copy revision (CW-v1 option-1, #1920 PR-1)
            }
            $this->revisionDriver->deleteRevision($entityId, $vid);
            ++$pruned;
        }

        return new RevisionPruningReport(
            candidatesFound: $candidatesFound,
            pruned: $pruned,
            retained: $total - $pruned,
        );
    }

    /**
     * Backfill an initial revision for every existing row that has none, and
     * point the base table at it. Idempotent — rows that already have a
     * revision are skipped.
     *
     * Run this once after flipping an EntityType to revisionable: true (and
     * after its revision table exists, e.g. via schema:sync) so pre-existing
     * content gets a baseline revision and full history from then on. This is
     * what the `revisions:enable` command drives.
     *
     * @return int Number of rows backfilled.
     */
    public function backfillInitialRevisions(?string $log = null): int
    {
        if ($this->revisionDriver === null) {
            throw new \LogicException('Revision driver not configured for entity type ' . $this->entityType->id());
        }
        if (!$this->entityType->isRevisionable()) {
            throw new \LogicException(
                'Cannot backfill revisions: entity type ' . $this->entityType->id() . ' is not revisionable.',
            );
        }

        $log ??= 'Initial revision (backfilled)';
        $count = 0;

        // Same resolution as every other revision-creating path — resolved
        // once per invocation, NOT an override site (research D2): a CLI
        // backfill with no ambient context records null, correctly.
        $actor = $this->resolveActor(null);

        foreach ($this->findBy([]) as $entity) {
            $id = (string) ($entity->id() ?? '');
            if ($id === '') {
                continue;
            }
            if ($this->revisionDriver->getLatestRevisionId($id) !== null) {
                continue; // already has revision history
            }

            $values = $this->canonicalizeBooleanFieldValues(
                $this->extractPersistenceValues($entity),
                $entity,
            );
            $transaction = $this->database?->transaction();
            try {
                $revisionId = $this->writeRevisionRow($id, $values, $log, author: $actor);
                $values['revision_id'] = $revisionId;
                $this->writeDriverRow($this->entityType->id(), $id, $values);
                $transaction?->commit();
            } catch (\Throwable $e) {
                $transaction?->rollBack();
                throw $e;
            }

            ++$count;
        }

        return $count;
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
        $rows = $this->findDriverTranslations($this->entityType->id(), $id, $defaultLc);

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
            if ($instance instanceof EntityBase) {
                $known = array_keys($rows);
                sort($known);
                $instance->_hydrateStructuralLanguages($lc, $defaultLc, $known);
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
        return $this->entityInstantiator->instantiate($class, $values);
    }
}

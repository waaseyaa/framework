<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Psr\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Access\Context\AccountContextInterface;
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
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\Entity\RevisionMetadata;
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
    /** @var string[] Default language fallback chain. */
    private array $fallbackChain = ['en'];

    private readonly EntityEventFactoryInterface $eventFactory;

    private readonly \Waaseyaa\Foundation\Log\LoggerInterface $logger;

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
                $currentRevisionId = ($originalEntity instanceof RevisionableInterface)
                    ? $originalEntity->getRevisionId()
                    : null;
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

        $this->dispatchEvent(
            $this->eventFactory->create($entity, $originalEntity),
            EntityEvents::PRE_SAVE->value,
            $unitOfWork,
        );

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
        // NOT fire.
        $this->dispatchEvent(
            new BeforeSaveEvent($entity, $resolvedContext, $createRevision),
            BeforeSaveEvent::class,
            $unitOfWork,
        );

        $values = $entity->toArray();
        $id = (string) ($entity->id() ?? '');

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
                $entity->set($idKey, $nextId);
            }
        }

        // Wrap revision + base table writes in a transaction (invariant #4).
        // Skip if already inside a UnitOfWork transaction.
        $transaction = ($unitOfWork === null) ? $this->database?->transaction() : null;
        // A new entity with an auto-assigned id does not know its id until the
        // base row is inserted below. Writing the revision now would key it
        // under entity_id '' (an orphan listRevisions never sees), so defer the
        // revision write until after the base insert.
        $deferRevision = $createRevision && $this->revisionDriver !== null && $id === '';

        try {
            if ($createRevision && $this->revisionDriver !== null && !$deferRevision) {
                $log = ($entity instanceof RevisionableInterface) ? $entity->getRevisionLog() : null;
                $revisionId = $this->revisionDriver->writeRevision($id, $values, $log, author: $actor);

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

                        $currentRow = $this->driver->read($entityTypeId, $id);
                        $currentHead = null;
                        if ($currentRow !== null && (int) ($currentRow[$revisionKey] ?? 0) > 0) {
                            $currentHead = (int) $currentRow[$revisionKey];
                        }

                        throw new RevisionConflictException($entityTypeId, $id, $expectedRevisionId, $currentHead);
                    }
                }

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

            if ($deferRevision && $writtenId !== '') {
                // The base row now exists with a real id. Write revision 1 keyed
                // on it, then point the base row at it by updating only the
                // revision-pointer column (leaving the _data blob untouched).
                $log = ($entity instanceof RevisionableInterface) ? $entity->getRevisionLog() : null;
                $revisionId = $this->revisionDriver->writeRevision($writtenId, $values, $log, author: $actor);
                $revisionKey = $this->entityType->getKeys()['revision'] ?? 'revision_id';
                $idKeyName = $this->entityType->getKeys()['id'] ?? 'id';
                $this->database?->update($entityTypeId)
                    ->fields([$revisionKey => $revisionId])
                    ->condition($idKeyName, $writtenId)
                    ->execute();
                if ($entity instanceof ContentEntityInterface) {
                    $entity->set($revisionKey, $revisionId);
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

    public function rollback(string $entityId, int $targetRevisionId): EntityInterface
    {
        if ($this->revisionDriver === null) {
            throw new \LogicException('Revision driver not configured for entity type ' . $this->entityType->id());
        }

        // Revert authorship (clause 4): the NEW revision is authored by whoever
        // performs the revert — resolved once, from the ambient context (no
        // SaveContext on this path). The target revision row is never modified.
        $actor = $this->resolveActor(null);

        // Load the target revision.
        $targetRow = $this->revisionDriver->readRevision($entityId, $targetRevisionId);
        if ($targetRow === null) {
            throw new \InvalidArgumentException(
                "Revision {$targetRevisionId} does not exist for entity {$entityId}.",
            );
        }

        // Remove revision metadata from the row — we're creating a new revision.
        // revision_author included: the old revision's author must not leak
        // onto the new revision or into the base row.
        unset($targetRow['revision_id'], $targetRow['revision_created'], $targetRow['revision_log'], $targetRow['revision_author'], $targetRow['entity_id']);

        // Wrap in transaction (invariant #4: atomic pointer update).
        $transaction = $this->database?->transaction();
        try {
            $log = "Reverted to revision {$targetRevisionId}";
            $newRevisionId = $this->revisionDriver->writeRevision($entityId, $targetRow, $log, author: $actor);

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

        $row = $this->revisionDriver->readRevision($entityId, $revisionId);
        if ($row === null) {
            throw new \InvalidArgumentException(
                "Revision {$revisionId} does not exist for entity {$entityId}.",
            );
        }

        // Pointer-move actor + prior pointer for the transition event (FR-006).
        $actor = $this->resolveActor(null);
        $priorBaseRow = $this->driver->read($this->entityType->id(), $entityId);
        $fromRevisionId = null;
        if ($priorBaseRow !== null && (int) ($priorBaseRow['revision_id'] ?? 0) > 0) {
            $fromRevisionId = (int) $priorBaseRow['revision_id'];
        }

        // Re-point the base table at this revision's values. Strip revision-table
        // bookkeeping columns; the base table tracks the current revision via the
        // revision_id pointer column.
        unset($row['revision_created'], $row['revision_log'], $row['revision_author'], $row['entity_id']);
        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';
        $row[$idKey] = $entityId;
        $row['revision_id'] = $revisionId;

        $transaction = $this->database?->transaction();
        try {
            $this->driver->write($this->entityType->id(), $entityId, $row);
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

        $baseRow = $this->driver->read($this->entityType->id(), $entityId);
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

        // Validate the target revision exists for this entity.
        if ($this->revisionDriver->readRevision($entityId, $revisionId) === null) {
            throw new \InvalidArgumentException(
                "Revision {$revisionId} does not exist for entity {$entityId}.",
            );
        }

        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';

        // Pointer-move actor + prior published pointer for the transition
        // event (FR-006). Null when previously unpublished.
        $actor = $this->resolveActor(null);
        $fromRevisionId = null;

        $transaction = $this->database?->transaction();
        try {
            // Read the prior pointer inside the transaction so the from→to
            // transition the event reports is the one this move performed.
            $priorBaseRow = $this->driver->read($this->entityType->id(), $entityId);
            if ($priorBaseRow !== null && (int) ($priorBaseRow['published_revision_id'] ?? 0) > 0) {
                $fromRevisionId = (int) $priorBaseRow['published_revision_id'];
            }

            if ($this->database !== null) {
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
                $this->driver->write($this->entityType->id(), $entityId, $baseRow);
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

        // Author resolved once per operation (FR-001; ambient context — this
        // path carries no SaveContext).
        $actor = $this->resolveActor(null);
        $revisionId = $driver->writeRevision($entityId, $values, $log, $langcode, $actor);

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
                $created[$langcode] = $driver->writeRevision($entityId, $values, $log, $langcode, $actor);
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

        $row = $driver->readLangcodeRevision($entityId, $langcode, $revisionId);
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
        if ($this->database === null) {
            throw new \LogicException(
                'saveTranslation requires a database connection for entity type ' . $this->entityType->id(),
            );
        }

        // Author resolved once per operation (FR-001).
        $actor = $this->resolveActor(null);

        $transaction = $this->database->transaction();
        try {
            $this->upsertLangcodePeerRow($entityId, $langcode, $values);
            $revisionId = $driver->writeRevision($entityId, $values, $log, $langcode, $actor);
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

        $row = $this->driver->read($this->entityType->id(), $entityId, $langcode);
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

        return $this->hydrate($row);
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
     */
    private function upsertLangcodePeerRow(string $entityId, string $langcode, array $values): void
    {
        \assert($this->database !== null);

        $table = $this->entityType->id();
        $keys = $this->entityType->getKeys();
        $idKey = $keys['id'] ?? 'id';
        $langKey = $keys['langcode'] ?? 'langcode';
        $labelKey = $keys['label'] ?? 'label';
        $schema = $this->database->schema();

        // Split the values into real columns vs the `_data` blob.
        $columns = [];
        $data = [];
        foreach ($values as $key => $value) {
            if ($key === $idKey || $key === $langKey || $key === '_data') {
                continue;
            }
            if ($schema->fieldExists($table, $key)) {
                $columns[$key] = $value;
            } else {
                $data[$key] = $value;
            }
        }
        if ($labelKey !== '' && isset($values[$labelKey]) && $schema->fieldExists($table, $labelKey)) {
            $columns[$labelKey] = (string) $values[$labelKey];
        }

        $row = $columns;
        if ($schema->fieldExists($table, '_data')) {
            $row['_data'] = json_encode($data, \JSON_THROW_ON_ERROR);
        }

        $exists = false;
        foreach ($this->database->query(
            'SELECT 1 FROM ' . $table . ' WHERE ' . $idKey . ' = ? AND ' . $langKey . ' = ?',
            [$entityId, $langcode],
        ) as $_) {
            $exists = true;
            break;
        }

        if ($exists) {
            $this->database->update($table)
                ->fields($row)
                ->condition($idKey, $entityId)
                ->condition($langKey, $langcode)
                ->execute();

            return;
        }

        $row[$idKey] = $entityId;
        $row[$langKey] = $langcode;
        if (isset($keys['uuid'])) {
            $defaultRow = $this->driver->read($table, $entityId);
            if ($defaultRow !== null && isset($defaultRow[$keys['uuid']])) {
                $row[$keys['uuid']] = $defaultRow[$keys['uuid']];
            }
        }

        $this->database->insert($table)
            ->fields(array_keys($row))
            ->values($row)
            ->execute();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateTranslationRow(
        RevisionableStorageDriver $driver,
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

        $entity = $this->hydrate($row);

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

    private function assertTwoAxis(string $method): RevisionableStorageDriver
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
     * revision (FR-038, enforced via {@see RevisionPruningPolicy::candidateExcluded()}).
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

        if ($policy->isNoOp()) {
            return RevisionPruningReport::disabled();
        }

        $revisionIds = array_map('intval', $this->revisionDriver->getRevisionIds($entityId));
        sort($revisionIds); // oldest -> newest
        $total = count($revisionIds);

        // The current (default) revision is immortal regardless of policy.
        $baseRow = $this->driver->read($this->entityType->id(), $entityId);
        $currentRevisionId = $baseRow !== null ? (int) ($baseRow['revision_id'] ?? 0) : 0;

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

            $values = $entity->toArray();
            $transaction = $this->database?->transaction();
            try {
                $revisionId = $this->revisionDriver->writeRevision($id, $values, $log, author: $actor);
                $values['revision_id'] = $revisionId;
                $this->driver->write($this->entityType->id(), $id, $values);
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

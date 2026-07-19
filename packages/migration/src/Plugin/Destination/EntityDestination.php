<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Plugin\Destination;

use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\RevisionableInterface;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Event\AfterDeleteEvent;
use Waaseyaa\EntityStorage\Event\AfterSaveEvent;
use Waaseyaa\EntityStorage\Event\BeforeDeleteEvent;
use Waaseyaa\EntityStorage\Event\BeforeSaveEvent;
use Waaseyaa\EntityStorage\SaveContext;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Migration\Canonical\CanonicalForm;
use Waaseyaa\Migration\Exception\DestinationWriteException;
use Waaseyaa\Migration\MigrationIdMap;
use Waaseyaa\Migration\Plugin\DestinationPluginInterface;
use Waaseyaa\Migration\Plugin\DestinationRecord;
use Waaseyaa\Migration\Plugin\WriteResult;
use Waaseyaa\Migration\SourceId;

/**
 * Canonical destination plugin: write each processed source record as a
 * Waaseyaa entity through {@see EntityRepository::save()}.
 *
 * Wires the migration platform into the canonical entity-persistence pipeline
 * (`.claude/rules/entity-storage-invariant.md`): no raw DBAL, no PDO; every
 * write flows through `EntityRepository::save()` so lifecycle hooks, validation,
 * revision creation and event dispatch happen exactly as they would for an
 * interactive request.
 *
 * ## Atomicity (FR-029)
 *
 * `write()` wraps the entity save AND the id-map upsert in a single
 * {@see MigrationIdMap::transactional()} block. On any throwable both writes
 * roll back together — the destination entity is never persisted without a
 * corresponding id-map row, and vice versa.
 *
 * ## Skip-vs-update (FR-031)
 *
 * Before persisting we consult {@see MigrationIdMap::lookupDestination()} for
 * an existing row keyed on `(migrationId, sourceId->hash())`.
 *
 * - **No row** → create a new entity, save it, upsert id-map. `WriteResult`
 *   carries the new uuid.
 * - **Row exists, hash unchanged** → return the prior `WriteResult` as-is.
 *   No save() call, no upsert() call, no events dispatched. Pure idempotent
 *   skip.
 * - **Row exists, hash changed** → load the existing entity, apply the new
 *   values, save (creates a new revision on revisionable types — FR-023),
 *   upsert id-map with the new hash.
 *
 * The `WriteResult` returned to callers always carries the latest
 * `sourceRecordHash` so downstream tooling (rollback in WP08, resume in WP07)
 * can resolve the entity without re-deriving identity.
 *
 * ## Access (FR-020)
 *
 * Each write consults the {@see GateInterface} for either `create` (new) or
 * `update` (existing) on the destination entity type. Denial raises
 * {@see DestinationWriteException} with reason code `entity_create_denied`
 * or `entity_update_denied`. Migration runs typically supply an elevated
 * system account (the runner's responsibility, not this class's) so that
 * migrations of public content do not silently get blocked by per-user
 * policies.
 *
 * ## SaveContext::isImport (FR-022, GitHub #1449)
 *
 * Each save constructs a fresh `SaveContext::default()->asImport()` and
 * threads it through `EntityRepository::save(...$context: ...)`. The
 * repository is the single dispatch site for {@see BeforeSaveEvent} /
 * {@see AfterSaveEvent}; this class no longer self-dispatches. Subscribers
 * wishing to skip non-essential work during bulk imports (cache
 * invalidation, search-index refresh) branch on
 * `$event->saveContext()->isImport`. The signal is passive — the
 * repository's own internal `EntityEvents::PRE_SAVE` / `POST_SAVE` events
 * continue to fire unchanged.
 *
 * ## Field map
 *
 * The optional `$fieldMap` translates **destination-record field names**
 * (the keys produced by process plugins) into **storage field names** on the
 * target entity. Identity is the default — a key absent from the map passes
 * through. The map is `array<string, string>` and is intentionally tiny;
 * complex transformations belong in process plugins (`packages/migration/src/Plugin/Process/`),
 * not here.
 *
 * @api
 *
 * @spec FR-018 — entity type resolution
 * @spec FR-019 — flows through EntityRepository (the storage coordinator surface)
 * @spec FR-020 — access check at write time
 * @spec FR-021 — emits BeforeSaveEvent / AfterSaveEvent carrying SaveContext
 * @spec FR-022 — SaveContext::isImport carries the migration signal
 * @spec FR-023 — revisionable entity types create a new revision on update
 * @spec FR-024 — orphaned destination entities surface as DestinationWriteException
 * @spec FR-031 — skip-vs-update by source_record_hash
 */
final class EntityDestination implements DestinationPluginInterface
{
    private const string DESTINATION_ID = 'entity';

    /**
     * Stability marker exposed via {@see stability()}. EntityDestination is
     * the canonical stable destination per `contracts/destination-plugin.md`
     * ("The framework ships exactly one stable destination — EntityDestination.")
     * and the DestinationPluginInterface::stability() PHPDoc enum
     * (`'stable'|'experimental'`).
     */
    private const string STABILITY = 'stable';

    private readonly LoggerInterface $logger;

    /**
     * Runner-supplied run id (UUIDv7) used for every {@see write()} call.
     *
     * When set (via {@see withRunId()}), {@see resolveRunId()} returns this
     * value verbatim instead of minting a per-write UUIDv7. Set by
     * {@see \Waaseyaa\Migration\Runner\MigrationRunner} so every id-map row
     * produced by one `import:run` invocation shares one run id (FR-046
     * audit trail prerequisite). `null` preserves the WP05 default (per-write
     * UUID) for callers that have not migrated to the runner surface.
     */
    private readonly ?string $runIdOverride;

    /**
     * @param string $destinationEntityTypeId Target entity type id (must be registered with EntityTypeManager).
     * @param EntityTypeManager $entityTypeManager Source of truth for entity type definitions (FR-018).
     * @param EntityRepository $entityRepository The repository for the destination entity type. The caller is responsible for binding the repository to a matching `EntityType` — `EntityDestination` does NOT load alternate repositories per type. One destination plugin = one entity type.
     * @param MigrationIdMap $idMap Stable id-map repository — owns transactional atomicity (FR-029) and skip-vs-update primitives (FR-031).
     * @param GateInterface $gate Access gate consulted on every write (FR-020).
     * @param EventDispatcherInterface $eventDispatcher Coordinator-level lifecycle event sink (FR-021, FR-022).
     * @param string $migrationId Stable id of the migration this destination services. Used as the id-map partition key.
     * @param ?object $account Account passed to the gate. Migration runs should inject an elevated system account; nullable for the rare conformance test that needs to verify denial.
     * @param array<string, string> $fieldMap Destination-record key → storage field name. Empty (default) means identity mapping.
     * @param ?LoggerInterface $logger Structured logger for diagnostics; defaults to {@see NullLogger}.
     * @param ?string $runIdOverride Runner-supplied UUIDv7 used by every write produced by this instance. `null` (default) preserves the WP05 per-write UUID behavior. Prefer {@see withRunId()} for cloning purposes.
     */
    public function __construct(
        private readonly string $destinationEntityTypeId,
        private readonly EntityTypeManager $entityTypeManager,
        private readonly EntityRepository $entityRepository,
        private readonly MigrationIdMap $idMap,
        private readonly GateInterface $gate,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly string $migrationId,
        private readonly ?object $account = null,
        private readonly array $fieldMap = [],
        ?LoggerInterface $logger = null,
        ?string $runIdOverride = null,
    ) {
        if ($destinationEntityTypeId === '') {
            throw new \InvalidArgumentException(
                'EntityDestination::__construct(): $destinationEntityTypeId must be a non-empty string.',
            );
        }
        if ($migrationId === '') {
            throw new \InvalidArgumentException(
                'EntityDestination::__construct(): $migrationId must be a non-empty string.',
            );
        }
        foreach ($fieldMap as $logicalKey => $physicalKey) {
            if ($logicalKey === '') {
                throw new \InvalidArgumentException(
                    'EntityDestination::__construct(): $fieldMap keys must be non-empty strings.',
                );
            }
            if ($physicalKey === '') {
                throw new \InvalidArgumentException(
                    'EntityDestination::__construct(): $fieldMap values must be non-empty strings.',
                );
            }
        }

        if ($runIdOverride !== null && $runIdOverride === '') {
            throw new \InvalidArgumentException(
                'EntityDestination::__construct(): $runIdOverride must be a non-empty UUIDv7 string when supplied.',
            );
        }

        $this->logger = $logger ?? new NullLogger();
        $this->runIdOverride = $runIdOverride;
    }

    /**
     * Return a cloned destination that stamps every {@see write()} call with
     * `$runId` instead of minting a per-write UUIDv7.
     *
     * {@see \Waaseyaa\Migration\Runner\MigrationRunner} invokes this once at
     * the top of every `run()` so the entire migration's id-map rows share a
     * single run id — preconditions for resume (WP07) and rollback (WP08).
     *
     * @param string $runId UUIDv7 run id minted by the runner. Non-empty.
     *
     * @throws \InvalidArgumentException When `$runId` is empty.
     *
     * @api
     */
    public function withRunId(string $runId): self
    {
        if ($runId === '') {
            throw new \InvalidArgumentException(
                'EntityDestination::withRunId(): $runId must be a non-empty string.',
            );
        }

        return new self(
            destinationEntityTypeId: $this->destinationEntityTypeId,
            entityTypeManager: $this->entityTypeManager,
            entityRepository: $this->entityRepository,
            idMap: $this->idMap,
            gate: $this->gate,
            eventDispatcher: $this->eventDispatcher,
            migrationId: $this->migrationId,
            account: $this->account,
            fieldMap: $this->fieldMap,
            logger: $this->logger,
            runIdOverride: $runId,
        );
    }

    public function id(): string
    {
        return self::DESTINATION_ID;
    }

    public function stability(): string
    {
        return self::STABILITY;
    }

    public function write(DestinationRecord $record): WriteResult
    {
        // FR-018: destination entity type must be registered.
        if (!$this->entityTypeManager->hasDefinition($this->destinationEntityTypeId)) {
            throw DestinationWriteException::entityTypeUnknown(
                $this->destinationEntityTypeId,
                $record->sourceId,
            );
        }

        // FR-031: hash the canonical form of the incoming values + bundle + langcode.
        // The single shared CanonicalForm encoder ensures `same input → same hash`
        // across PHP versions and locale settings.
        $sourceRecordHash = $this->computeSourceRecordHash($record);

        // FR-031 cheap pre-check — if the prior id-map row already records this
        // exact source_record_hash, there is nothing to persist. We skip BEFORE
        // entering the transaction so the no-op path costs one SELECT.
        $prior = $this->idMap->lookupDestination($this->migrationId, $record->sourceId);
        if ($prior !== null
            && $prior->destinationEntityType === $this->destinationEntityTypeId
            && $prior->sourceRecordHash === $sourceRecordHash
        ) {
            $this->logger->debug('EntityDestination: skip (unchanged hash)', [
                'migration_id' => $this->migrationId,
                'entity_type' => $this->destinationEntityTypeId,
                'destination_uuid' => $prior->destinationUuid,
            ]);

            return $prior;
        }

        // Atomicity (FR-029): entity save AND id-map upsert in one transaction.
        /** @var WriteResult $result */
        $result = $this->idMap->transactional(
            fn(): WriteResult => $this->writeInsideTransaction($record, $prior, $sourceRecordHash),
        );

        return $result;
    }

    /**
     * Reverse a previous {@see write()} call.
     *
     * Implements `DestinationPluginInterface::rollback()` for the entity
     * destination (FR-041). The flow mirrors {@see write()} on the
     * delete side:
     *
     *  1. Look up the entity by `destinationUuid` via
     *     {@see EntityRepository::findBy()}. If the entity is missing
     *     (already deleted by an operator or another tool), return
     *     silently — the contract is best-effort + idempotent (FR-042
     *     "missing target ... no-op + warn").
     *  2. Ask the gate for `delete` permission (FR-020 symmetry with
     *     write). A deny raises {@see DestinationWriteException} with
     *     reason `entity_delete_denied`. The id-map row is NOT removed
     *     so the operator can retry after fixing access.
     *  3. Dispatch {@see BeforeDeleteEvent} → call
     *     {@see EntityRepository::delete()} (which itself dispatches
     *     the same canonical events but also performs the storage
     *     remove) → dispatch {@see AfterDeleteEvent}. Mirrors WP05's
     *     write-side double-event pattern.
     *
     * The walker ({@see \Waaseyaa\Migration\Runner\RollbackWalker}) is
     * responsible for removing the id-map row on success — keeping the
     * id-map mutation in the orchestrator (not the plugin) preserves
     * the "destination plugins know nothing about the id-map" boundary
     * for non-entity destinations.
     *
     * @throws DestinationWriteException When the gate denies `delete`
     *         (reason `entity_delete_denied`) or the underlying
     *         {@see EntityRepository::delete()} throws (reason
     *         `entity_delete_failed`).
     *
     * @spec FR-041 — per-record rollback
     * @spec FR-042 — missing target is a no-op + warn
     * @spec FR-020 — access-check at rollback time
     */
    public function rollback(WriteResult $result): void
    {
        $definition = $this->entityTypeManager->getDefinition($this->destinationEntityTypeId);
        $uuidKey = $definition->getKeys()['uuid'] ?? 'uuid';

        $matches = $this->entityRepository->findBy([$uuidKey => $result->destinationUuid]);
        $entity = $matches[0] ?? null;

        if (!$entity instanceof EntityInterface) {
            // FR-042: idempotent — the entity is already gone. Warn so
            // operators can correlate manual cleanup with rollback
            // output, but do not raise.
            $this->logger->warning(
                'EntityDestination::rollback(): destination entity already absent — treating as no-op (FR-042).',
                [
                    'migration_id' => $this->migrationId,
                    'entity_type' => $this->destinationEntityTypeId,
                    'destination_uuid' => $result->destinationUuid,
                ],
            );

            return;
        }

        if ($this->gate->denies('delete', $entity, $this->account)) {
            throw DestinationWriteException::entityDeleteDenied(
                $this->destinationEntityTypeId,
                $result->destinationUuid,
            );
        }

        // FR-021 symmetry: dispatch the canonical lifecycle events around
        // the delete. EntityRepository::delete() ALSO dispatches these
        // events from inside its own pipeline; the explicit dispatch here
        // mirrors WP05's write-side pattern for subscribers that want a
        // signal scoped to the destination plugin (e.g. structured
        // import audit log).
        $this->eventDispatcher->dispatch(new BeforeDeleteEvent($entity));

        try {
            $this->entityRepository->delete($entity);
        } catch (\Throwable $e) {
            throw DestinationWriteException::entityDeleteFailed(
                $this->destinationEntityTypeId,
                $result->destinationUuid,
                $e,
            );
        }

        $this->eventDispatcher->dispatch(new AfterDeleteEvent($entity));

        $this->logger->debug(
            'EntityDestination: rolled back (entity deleted)',
            [
                'migration_id' => $this->migrationId,
                'entity_type' => $this->destinationEntityTypeId,
                'destination_uuid' => $result->destinationUuid,
            ],
        );
    }

    public function lookup(SourceId $sourceId): ?WriteResult
    {
        return $this->idMap->lookupDestination($this->migrationId, $sourceId);
    }

    /**
     * Heart of the write path. Runs inside {@see MigrationIdMap::transactional()}.
     */
    private function writeInsideTransaction(
        DestinationRecord $record,
        ?WriteResult $prior,
        string $sourceRecordHash,
    ): WriteResult {
        if ($prior === null) {
            $entity = $this->buildNewEntity($record);
            $isUpdate = false;
        } else {
            $entity = $this->loadExistingEntity($record, $prior);
            $isUpdate = true;
        }

        $this->applyValuesToEntity($entity, $record);
        $this->assertAccess($entity, $isUpdate, $record->sourceId);

        // FR-023: on revisionable entity types, every successful import write
        // (create OR changed-update) cuts a new revision. Force the per-entity
        // override so we do not depend on EntityType::getRevisionDefault() being
        // set on consumer-defined types. Skip path never reaches this branch.
        $isNewRevision = true;
        if ($entity instanceof RevisionableInterface && $isUpdate) {
            $entity->setNewRevision(true);
        }

        // FR-022: signal-only flag; subscribers branch on it. The repository
        // (GitHub #1449) is the single dispatch site for BeforeSaveEvent /
        // AfterSaveEvent — `$saveContext` threads through to subscribers via
        // `EntityRepository::save()`.
        $saveContext = SaveContext::default()->asImport();

        try {
            $this->entityRepository->save($entity, validate: false, context: $saveContext);
        } catch (\Throwable $e) {
            // The transactional() wrapper will roll back; surface a structured exception.
            throw DestinationWriteException::entitySaveFailed(
                $this->destinationEntityTypeId,
                $e,
                $record->sourceId,
            );
        }

        $destinationUuid = $this->extractUuid($entity);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $runId = $this->resolveRunId($prior);

        $writeResult = $this->idMap->upsert(
            migrationId: $this->migrationId,
            sourceId: $record->sourceId,
            destinationEntityType: $this->destinationEntityTypeId,
            destinationUuid: $destinationUuid,
            sourceRecordHash: $sourceRecordHash,
            runId: $runId,
            now: $now,
        );

        // GitHub #1449: BeforeSaveEvent/AfterSaveEvent are dispatched by
        // `EntityRepository::save()` above; `$isNewRevision` flows through
        // the SaveContext-aware event constructors there. `$isNewRevision`
        // is no longer used in this method.
        unset($isNewRevision);

        return $writeResult;
    }

    /**
     * Compute the canonical hash for change detection (FR-031, FR-027).
     *
     * Bundle and langcode are folded into the payload so a record that only
     * changes its bundle or language still produces a different hash.
     */
    private function computeSourceRecordHash(DestinationRecord $record): string
    {
        $payload = [
            'values' => $record->values,
            'bundle' => $record->bundle,
            'langcode' => $record->langcode,
        ];

        return \hash('sha256', CanonicalForm::encode($payload));
    }

    /**
     * Build a fresh entity instance for a never-before-imported source record.
     */
    private function buildNewEntity(DestinationRecord $record): EntityInterface
    {
        $definition = $this->entityTypeManager->getDefinition($this->destinationEntityTypeId);
        $class = $definition->getClass();

        if (!\class_exists($class)) {
            throw DestinationWriteException::entityTypeUnknown(
                $this->destinationEntityTypeId,
                $record->sourceId,
            );
        }

        $keys = $definition->getKeys();
        $uuidKey = $keys['uuid'] ?? 'uuid';

        // Structural selectors are immutable once the entity is sealed. Seed
        // every selector carried by the destination record atomically with the
        // UUID instead of constructing first and mutating bundle/language
        // afterward.
        $initialValues = [$uuidKey => Uuid::v7()->toRfc4122()];
        foreach ($record->values as $logicalKey => $value) {
            $physicalKey = $this->fieldMap[(string) $logicalKey] ?? (string) $logicalKey;
            if (in_array($physicalKey, $this->structuralFieldNames($keys), true)) {
                $initialValues[$physicalKey] = $value;
            }
        }
        if ($record->bundle !== null && isset($keys['bundle'])) {
            $initialValues[$keys['bundle']] = $record->bundle;
        }
        if ($record->langcode !== null && isset($keys['langcode'])) {
            $initialValues[$keys['langcode']] = $record->langcode;
        }

        $entity = new $class($initialValues);
        \assert($entity instanceof EntityInterface);

        return $entity;
    }

    /**
     * Load the entity referenced by an id-map row when applying an update.
     *
     * We resolve by uuid via {@see EntityRepository::findBy()} because the
     * id-map stores the destination uuid, not the auto-increment primary key.
     */
    private function loadExistingEntity(DestinationRecord $record, WriteResult $prior): EntityInterface
    {
        $definition = $this->entityTypeManager->getDefinition($this->destinationEntityTypeId);
        $uuidKey = $definition->getKeys()['uuid'] ?? 'uuid';

        $matches = $this->entityRepository->findBy([$uuidKey => $prior->destinationUuid]);
        $entity = $matches[0] ?? null;

        if (!$entity instanceof EntityInterface) {
            throw DestinationWriteException::entityLoadFailed(
                $this->destinationEntityTypeId,
                $prior->destinationUuid,
                $record->sourceId,
            );
        }

        return $entity;
    }

    /**
     * Apply destination-record values to the entity, honouring {@see $fieldMap}.
     */
    private function applyValuesToEntity(EntityInterface $entity, DestinationRecord $record): void
    {
        $definition = $this->entityTypeManager->getDefinition($this->destinationEntityTypeId);
        $keys = $definition->getKeys();
        $structuralFields = $this->structuralFieldNames($keys);

        foreach ($record->values as $logicalKey => $value) {
            // DestinationRecord constructor rejects non-string keys; the cast is
            // a static-type narrowing for PHPStan.
            $logicalKey = (string) $logicalKey;
            $physicalKey = $this->fieldMap[$logicalKey] ?? $logicalKey;
            if (in_array($physicalKey, $structuralFields, true)) {
                continue;
            }
            $entity->set($physicalKey, $value);
        }
    }

    private function assertAccess(EntityInterface $entity, bool $isUpdate, SourceId $sourceId): void
    {
        if ($isUpdate) {
            if ($this->gate->denies('update', $entity, $this->account)) {
                throw DestinationWriteException::entityUpdateDenied(
                    $this->destinationEntityTypeId,
                    $sourceId,
                );
            }

            return;
        }

        // Create access takes a bundle-aware array subject when the destination
        // entity type declares a bundle key and the entity carries a value for
        // it (i.e. DestinationRecord::$bundle was set); otherwise it falls back
        // to the bundle-less string-subject form (see Gate / EntityAccessGate).
        $createSubject = $this->buildCreateSubject($entity);

        if ($this->gate->denies('create', $createSubject, $this->account)) {
            throw DestinationWriteException::entityCreateDenied(
                $this->destinationEntityTypeId,
                $sourceId,
            );
        }
    }

    /**
     * Build the create-ability subject for the gate: a bundle-aware array
     * when the entity type has a bundle key and the entity carries a
     * non-empty value for it, otherwise the bundle-less string form.
     *
     * @return array{entity_type: string, bundle: string}|string
     */
    private function buildCreateSubject(EntityInterface $entity): array|string
    {
        $definition = $this->entityTypeManager->getDefinition($this->destinationEntityTypeId);
        $bundleKey = $definition->getKeys()['bundle'] ?? null;

        if ($bundleKey === null) {
            return $this->destinationEntityTypeId;
        }

        $bundle = $entity->bundle();

        if ($bundle === '') {
            return $this->destinationEntityTypeId;
        }

        return [
            'entity_type' => $this->destinationEntityTypeId,
            'bundle' => $bundle,
        ];
    }

    private function extractUuid(EntityInterface $entity): string
    {
        $definition = $this->entityTypeManager->getDefinition($this->destinationEntityTypeId);
        $uuidKey = $definition->getKeys()['uuid'] ?? 'uuid';

        $raw = $entity->uuid();

        if ($raw === '') {
            throw DestinationWriteException::entitySaveFailed(
                $this->destinationEntityTypeId,
                new \RuntimeException(\sprintf(
                    'Persisted entity does not expose a non-empty uuid via key "%s"; '
                    . 'EntityDestination cannot record the id-map row.',
                    $uuidKey,
                )),
            );
        }

        return $raw;
    }

    /**
     * @param array<string, string> $keys
     * @return list<string>
     */
    private function structuralFieldNames(array $keys): array
    {
        $fields = [$keys['id'] ?? 'id'];
        foreach (['uuid', 'bundle', 'langcode', 'default_langcode', 'revision'] as $kind) {
            if (isset($keys[$kind])) {
                $fields[] = $keys[$kind];
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * Resolve a run id for the id-map upsert.
     *
     * Three paths:
     *   1. {@see $runIdOverride} set (via {@see withRunId()}) — the runner
     *      has minted one UUIDv7 for the entire `import:run` invocation and
     *      every id-map row stamped here shares it. This is the canonical
     *      runtime path (WP06 forward).
     *   2. No override + no prior row — mint a fresh UUIDv7 per write. The
     *      WP05 legacy fallback for callers that bypass the runner.
     *   3. No override + prior row exists — still mint a fresh UUIDv7 per
     *      write. The `$prior` parameter is retained for the future-eyed
     *      "preserve prior run id on hash-match skip" optimization; today
     *      the skip path short-circuits in {@see write()} and never reaches
     *      this method, so the value is unused.
     */
    private function resolveRunId(?WriteResult $prior): string
    {
        if ($this->runIdOverride !== null) {
            return $this->runIdOverride;
        }

        // `$prior` reserved for future symmetry with hash-match skip semantics;
        // see the method PHPDoc.
        unset($prior);

        return Uuid::v7()->toRfc4122();
    }
}

<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage;

use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcherInterface;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeInterface;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Entity\Event\TranslationEvent;
use Waaseyaa\EntityStorage\Backend\BackendRegistrar;
use Waaseyaa\EntityStorage\Backend\FieldStorageBackendGateway;
use Waaseyaa\EntityStorage\Event\AbortOperationException;
use Waaseyaa\EntityStorage\Event\AfterDeleteEvent;
use Waaseyaa\EntityStorage\Event\AfterSaveEvent;
use Waaseyaa\EntityStorage\Event\BeforeDeleteEvent;
use Waaseyaa\EntityStorage\Event\BeforeSaveEvent;
use Waaseyaa\EntityStorage\Exception\PartialSaveException;
use Waaseyaa\EntityStorage\Exception\UnknownBackendException;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\LogLevel;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * @api
 *
 * Wraps the coordinator's backend fan-out with lifecycle event dispatch and
 * partial-save error semantics.
 *
 * ## Save flow
 * 1. Dispatch {@see BeforeSaveEvent} — abort if subscriber throws {@see AbortOperationException}.
 * 2. Execute backend writes (primary first, alternates in registration order).
 *    - Track committed backend ids as each write completes.
 *    - On any {@see \Throwable}: throw {@see PartialSaveException}; no {@see AfterSaveEvent}.
 * 3. Dispatch {@see AfterSaveEvent} only after all writes succeed.
 *
 * ## Delete flow
 * Symmetric: {@see BeforeDeleteEvent} → fan-out → {@see AfterDeleteEvent} (on success only).
 *
 * ## No dispatcher
 * When `$dispatcher` is null the fan-out executes identically to WP02 behaviour
 * (no events, no partial-save tracking beyond exception throw).
 * PartialSaveException is still thrown on backend failure regardless.
 *
 * @see EntityStorageCoordinator
 */
final class CoordinatorLifecycleDispatcher
{
    private readonly LoggerInterface $logger;

    /** @var \Closure(EntityBase): array<string, mixed> */
    private readonly \Closure $persistenceValueAuthority;

    public function __construct(
        private readonly BackendRegistrar $registrar,
        private readonly ?EventDispatcherInterface $dispatcher,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $authority = \Closure::bind(
            static fn(EntityBase $source): array => $source->valueContainer->rawValues(),
            null,
            EntityBase::class,
        );
        $this->persistenceValueAuthority = $authority;
    }

    /**
     * Execute a save fan-out with lifecycle event dispatch.
     *
     * @param array<string, list<FieldDefinition>> $groups     Backend id → fields.
     * @param string                               $primaryId  Primary backend id (written first).
     *
     * @throws AbortOperationException   When a BeforeSaveEvent subscriber aborts.
     * @throws PartialSaveException      When one or more backends fail mid-fan-out.
     * @throws UnknownBackendException   When a field references an unregistered backend.
     */
    /**
     * Execute a save fan-out with lifecycle event dispatch.
     *
     * @param array<string, list<FieldDefinition>> $groups          Backend id → fields.
     * @param string                               $primaryId       Primary backend id (written first).
     * @param array<string, string>                $translationOps  Map of langcode → operation
     *                                                              ('insert'|'update'|'delete'). Each
     *                                                              entry triggers a paired
     *                                                              PRE_TRANSLATION_* / POST_TRANSLATION_*
     *                                                              dispatch wrapped around the backend
     *                                                              fan-out, ordered as supplied.
     *
     * @throws AbortOperationException   When a BeforeSaveEvent subscriber aborts.
     * @throws PartialSaveException      When one or more backends fail mid-fan-out.
     * @throws UnknownBackendException   When a field references an unregistered backend.
     */
    public function save(
        EntityInterface $entity,
        EntityTypeInterface $entityType,
        array $groups,
        string $primaryId,
        SaveContext $saveContext,
        bool $isNewRevision,
        array $translationOps = [],
    ): void {
        $this->assertTranslationOps($translationOps);

        $startMs = (int) (microtime(true) * 1000);

        if ($this->dispatcher !== null) {
            $this->dispatcher->dispatch(new BeforeSaveEvent($entity, $saveContext, $isNewRevision));
        }

        // Pre-translation dispatches: emit before the backend fan-out so listeners
        // can observe per-language pre-state alongside the entity-level pre-save.
        $this->dispatchTranslationsPre($entity, $translationOps);

        // Materialize the exact post-callback persistence values once through a
        // closed authority. They never enter an event, extension, or backend as
        // a value bag; each gateway receives only its declared field value.
        $persistenceValues = $entity instanceof EntityBase
            ? ($this->persistenceValueAuthority)($entity)
            : $entity->toArray();

        /** @var string[] $committed */
        $committed = [];
        // Build an ordered list: primary first, then alternates in registration order.
        $ordered = $this->buildOrderedBackendIds($groups, $primaryId);

        try {
            foreach ($ordered as $backendId) {
                $backend = $this->requireBackend($backendId, $entityType);
                $fields = $groups[$backendId] ?? [];

                foreach ($fields as $field) {
                    $backend->write($entity, $field, $persistenceValues[$field->getName()] ?? null);
                }

                $committed[] = $backendId;
            }
        } catch (UnknownBackendException $e) {
            // Config error — propagate directly; do not wrap as partial save.
            throw $e;
        } catch (\Throwable $e) {
            $uncommitted = array_values(array_diff($ordered, $committed));

            $this->logPartialSave(
                entity: $entity,
                entityType: $entityType,
                committed: $committed,
                uncommitted: $uncommitted,
                cause: $e,
                startMs: $startMs,
            );

            throw new PartialSaveException(
                entity: $entity,
                causedBy: $e,
                committedBackends: $committed,
                uncommittedBackends: $uncommitted,
            );
        }

        // Post-translation dispatches: emit AFTER fan-out succeeds, in reverse
        // order so listeners see a LIFO nesting around their pre-counterpart
        // (canonical save flow: PRE_UPDATE → PRE_TRANSLATION_UPDATE('en') →
        // PRE_TRANSLATION_INSERT('fr') → persist → POST_TRANSLATION_INSERT('fr')
        // → POST_TRANSLATION_UPDATE('en') → POST_UPDATE).
        $this->dispatchTranslationsPost($entity, $translationOps);

        if ($this->dispatcher !== null) {
            // WP07 / FR-039: backfill $affectedLangcodes from the translatable
            // write path's translation ops. Keys of $translationOps are the
            // langcodes actually written in this save (insert/update). When
            // $translationOps is empty (non-translatable entity), leave null
            // so consumers fall back to $entity->activeLangcode().
            $affectedLangcodes = $this->computeAffectedLangcodes($translationOps);
            $this->dispatcher->dispatch(
                new AfterSaveEvent($entity, $saveContext, $isNewRevision, $affectedLangcodes),
            );
        }

        $this->logLifecycle(
            event: 'save',
            entity: $entity,
            entityType: $entityType,
            outcome: 'ok',
            startMs: $startMs,
        );
    }

    /**
     * Execute a delete fan-out with lifecycle event dispatch.
     *
     * @param array<string, list<FieldDefinition>> $groups Backend id → fields.
     *
     * @throws AbortOperationException  When a BeforeDeleteEvent subscriber aborts.
     * @throws PartialSaveException     When one or more backends fail mid-fan-out.
     * @throws UnknownBackendException  When a field references an unregistered backend.
     */
    /**
     * Execute a delete fan-out with lifecycle event dispatch.
     *
     * @param array<string, list<FieldDefinition>> $groups          Backend id → fields.
     * @param list<string>                         $translationLangcodes Langcodes whose translation
     *                                                              rows are being deleted as part of
     *                                                              this entity delete. Each entry
     *                                                              triggers a paired
     *                                                              PRE_TRANSLATION_DELETE /
     *                                                              POST_TRANSLATION_DELETE around
     *                                                              the backend fan-out.
     *
     * @throws AbortOperationException  When a BeforeDeleteEvent subscriber aborts.
     * @throws PartialSaveException     When one or more backends fail mid-fan-out.
     * @throws UnknownBackendException  When a field references an unregistered backend.
     */
    public function delete(
        EntityInterface $entity,
        EntityTypeInterface $entityType,
        array $groups,
        array $translationLangcodes = [],
    ): void {
        $startMs = (int) (microtime(true) * 1000);

        if ($this->dispatcher !== null) {
            $this->dispatcher->dispatch(new BeforeDeleteEvent($entity));
        }

        $this->dispatchDeleteTranslationsPre($entity, $translationLangcodes);

        /** @var string[] $committed */
        $committed = [];
        $ordered = array_keys($groups);

        try {
            foreach ($ordered as $backendId) {
                /** @var string $backendId */
                $backend = $this->requireBackend($backendId, $entityType);
                $backend->delete($entity);
                $committed[] = $backendId;
            }
        } catch (UnknownBackendException $e) {
            // Config error — propagate directly; do not wrap as partial save.
            throw $e;
        } catch (\Throwable $e) {
            $uncommitted = array_values(array_diff($ordered, $committed));

            $this->logPartialSave(
                entity: $entity,
                entityType: $entityType,
                committed: $committed,
                uncommitted: $uncommitted,
                cause: $e,
                startMs: $startMs,
            );

            throw new PartialSaveException(
                entity: $entity,
                causedBy: $e,
                committedBackends: $committed,
                uncommittedBackends: $uncommitted,
            );
        }

        $this->dispatchDeleteTranslationsPost($entity, $translationLangcodes);

        if ($this->dispatcher !== null) {
            // WP07 / FR-039: backfill $affectedLangcodes from the explicit
            // translation langcodes captured before the delete fan-out. Empty
            // list (non-translatable entity) maps to null so consumers fall
            // back to $entity->activeLangcode().
            $affectedLangcodes = $translationLangcodes === []
                ? null
                : $this->normaliseLangcodes($translationLangcodes);
            $this->dispatcher->dispatch(new AfterDeleteEvent($entity, $affectedLangcodes));
        }

        $this->logLifecycle(
            event: 'delete',
            entity: $entity,
            entityType: $entityType,
            outcome: 'ok',
            startMs: $startMs,
        );
    }

    /**
     * Validate that every entry in $translationOps is one of the supported ops.
     *
     * @param array<string, string> $translationOps
     *
     * @throws \InvalidArgumentException When an op is not 'insert'|'update'|'delete'
     *                                   or a langcode is the empty string.
     */
    private function assertTranslationOps(array $translationOps): void
    {
        foreach ($translationOps as $langcode => $op) {
            if ($langcode === '') {
                throw new \InvalidArgumentException(
                    'CoordinatorLifecycleDispatcher: translation op langcodes must be non-empty strings.',
                );
            }

            if (!in_array($op, ['insert', 'update', 'delete'], strict: true)) {
                throw new \InvalidArgumentException(sprintf(
                    'CoordinatorLifecycleDispatcher: translation op "%s" for langcode "%s" is not supported; expected insert|update|delete.',
                    $op,
                    $langcode,
                ));
            }
        }
    }

    /**
     * @param array<string, string> $translationOps
     */
    private function dispatchTranslationsPre(EntityInterface $entity, array $translationOps): void
    {
        if ($translationOps === []) {
            return;
        }

        $symfony = $this->resolveSymfonyDispatcher();
        if ($symfony === null) {
            return;
        }

        foreach ($translationOps as $langcode => $op) {
            $eventName = match ($op) {
                'insert' => EntityEvents::PRE_TRANSLATION_INSERT->value,
                'update' => EntityEvents::PRE_TRANSLATION_UPDATE->value,
                'delete' => EntityEvents::PRE_TRANSLATION_DELETE->value,
                default => throw new \LogicException(sprintf(
                    'Unexpected translation op "%s" reached dispatch site (assertTranslationOps should have rejected it).',
                    $op,
                )),
            };

            $symfony->dispatch(new TranslationEvent($entity, $langcode), $eventName);
        }
    }

    /**
     * @param array<string, string> $translationOps
     */
    private function dispatchTranslationsPost(EntityInterface $entity, array $translationOps): void
    {
        if ($translationOps === []) {
            return;
        }

        $symfony = $this->resolveSymfonyDispatcher();
        if ($symfony === null) {
            return;
        }

        // Reverse order so post events nest LIFO around their pre-counterparts.
        foreach (array_reverse($translationOps, preserve_keys: true) as $langcode => $op) {
            $eventName = match ($op) {
                'insert' => EntityEvents::POST_TRANSLATION_INSERT->value,
                'update' => EntityEvents::POST_TRANSLATION_UPDATE->value,
                'delete' => EntityEvents::POST_TRANSLATION_DELETE->value,
                default => throw new \LogicException(sprintf(
                    'Unexpected translation op "%s" reached dispatch site (assertTranslationOps should have rejected it).',
                    $op,
                )),
            };

            $symfony->dispatch(new TranslationEvent($entity, $langcode), $eventName);
        }
    }

    /**
     * @param list<string> $langcodes
     */
    private function dispatchDeleteTranslationsPre(EntityInterface $entity, array $langcodes): void
    {
        if ($langcodes === []) {
            return;
        }

        $symfony = $this->resolveSymfonyDispatcher();
        if ($symfony === null) {
            return;
        }

        foreach ($langcodes as $langcode) {
            if ($langcode === '') {
                throw new \InvalidArgumentException(
                    'CoordinatorLifecycleDispatcher: delete translation langcodes must be non-empty strings.',
                );
            }
            $symfony->dispatch(
                new TranslationEvent($entity, $langcode),
                EntityEvents::PRE_TRANSLATION_DELETE->value,
            );
        }
    }

    /**
     * @param list<string> $langcodes
     */
    private function dispatchDeleteTranslationsPost(EntityInterface $entity, array $langcodes): void
    {
        if ($langcodes === []) {
            return;
        }

        $symfony = $this->resolveSymfonyDispatcher();
        if ($symfony === null) {
            return;
        }

        foreach (array_reverse($langcodes) as $langcode) {
            $symfony->dispatch(
                new TranslationEvent($entity, $langcode),
                EntityEvents::POST_TRANSLATION_DELETE->value,
            );
        }
    }

    /**
     * Resolve the dispatcher to a Symfony contract instance for named-event
     * dispatch. Returns null when no dispatcher is configured or when the
     * injected dispatcher is PSR-14-only (which has no event-name concept).
     * In the PSR-14-only case, translation listeners would need to subscribe
     * by class name on the application side.
     */
    private function resolveSymfonyDispatcher(): ?SymfonyEventDispatcherInterface
    {
        if (!$this->dispatcher instanceof SymfonyEventDispatcherInterface) {
            return null;
        }

        return $this->dispatcher;
    }

    /**
     * Build an ordered list of backend ids for save fan-out.
     *
     * Primary backend comes first; alternates follow in the order they appear
     * in $groups (which preserves registration order per WP02 contract).
     *
     * @param array<string, list<FieldDefinition>> $groups
     * @return string[]
     */
    private function buildOrderedBackendIds(array $groups, string $primaryId): array
    {
        $ids = [];

        if (isset($groups[$primaryId])) {
            $ids[] = $primaryId;
        }

        foreach (array_keys($groups) as $id) {
            if ($id !== $primaryId) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @throws UnknownBackendException
     */
    private function requireBackend(string $backendId, EntityTypeInterface $entityType): FieldStorageBackendGateway
    {
        $backend = $this->registrar->gateway($backendId);

        if ($backend === null) {
            throw new UnknownBackendException(
                $backendId,
                sprintf('entity type "%s" coordinator fan-out', $entityType->id()),
            );
        }

        return $backend;
    }

    /**
     * @param string[] $committed
     * @param string[] $uncommitted
     */
    private function logPartialSave(
        EntityInterface $entity,
        EntityTypeInterface $entityType,
        array $committed,
        array $uncommitted,
        \Throwable $cause,
        int $startMs,
    ): void {
        $durationMs = (int) (microtime(true) * 1000) - $startMs;

        $this->logger->log(LogLevel::ERROR, 'entity.lifecycle: partial save', [
            'channel' => 'entity.lifecycle',
            'outcome' => 'partial_save',
            'entity_type_id' => $entityType->id(),
            'entity_id' => $entity->id(),
            'committed_backends' => $committed,
            'uncommitted_backends' => $uncommitted,
            'cause_class' => $cause::class,
            'cause_message' => $cause->getMessage(),
            'duration_ms' => $durationMs,
        ]);
    }

    private function logLifecycle(
        string $event,
        EntityInterface $entity,
        EntityTypeInterface $entityType,
        string $outcome,
        int $startMs,
    ): void {
        $durationMs = (int) (microtime(true) * 1000) - $startMs;

        $this->logger->log(LogLevel::INFO, 'entity.lifecycle: ' . $event, [
            'channel' => 'entity.lifecycle',
            'event' => $event,
            'outcome' => $outcome,
            'entity_type_id' => $entityType->id(),
            'entity_id' => $entity->id(),
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Compute the {@see AfterSaveEvent::affectedLangcodes()} payload from the
     * translation ops driving this save.
     *
     * WP07 / FR-039. The keys of {@see $translationOps} are the langcodes
     * actually written (insert/update) in this save. Returns a sorted, unique
     * list when at least one translation op fired; returns null when there were
     * none (non-translatable entity), so consumers fall back to
     * {@see \Waaseyaa\Entity\TranslatableInterface::activeLangcode()}.
     *
     * @param array<string, string> $translationOps
     *
     * @return list<string>|null
     */
    private function computeAffectedLangcodes(array $translationOps): ?array
    {
        if ($translationOps === []) {
            return null;
        }

        return $this->normaliseLangcodes(array_keys($translationOps));
    }

    /**
     * Normalise a list of langcodes for emission via the lifecycle event surface.
     *
     * Returns a sorted, unique list. Used by both save and delete backfill paths
     * to guarantee deterministic ordering for cache-tag invalidation consumers.
     *
     * @param list<string> $langcodes
     *
     * @return list<string>
     */
    private function normaliseLangcodes(array $langcodes): array
    {
        $unique = array_values(array_unique($langcodes));
        sort($unique);

        return $unique;
    }
}

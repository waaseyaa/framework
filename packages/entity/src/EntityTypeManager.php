<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Entity\Community\HasCommunityInterface;
use Waaseyaa\Entity\Exception\EntityTypeRegistrationCollisionException;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

/**
 * Registry-based entity type manager.
 *
 * Holds entity type definitions and provides access to storage handlers.
 * Entity types are registered explicitly by service providers; optional
 * attribute-driven registration runs when {@code entity_auto_register} is enabled
 * in application config (see {@see \Waaseyaa\Foundation\Kernel\Bootstrap\ProviderRegistry}).
 * @api
 */
class EntityTypeManager implements EntityTypeManagerInterface
{
    /**
     * Registered entity type definitions.
     *
     * @var array<string, EntityTypeInterface>
     */
    private array $definitions = [];

    /** @var array<string, class-string|null> */
    private array $definitionRegistrants = [];

    /**
     * Cached storage handler instances.
     *
     * @var array<string, Storage\EntityStorageInterface>
     */
    private array $storageInstances = [];

    /**
     * Cached repository instances.
     *
     * @var array<string, EntityRepositoryInterface>
     */
    private array $repositoryInstances = [];

    /**
     * Entity-type ids that have already triggered the HasCommunityInterface
     * deprecation warning. Memoizes one log line per type id.
     *
     * @var array<string, true>
     */
    private array $tenancyDeprecationFired = [];

    /**
     * (entityTypeId, bundle) pairs that have already triggered the
     * `[BUNDLE_SUBTABLE_MISSING]` notice via addBundleFields(). One log line
     * per pair; subsequent registrations stay silent.
     *
     * @var array<string, true>
     */
    private array $missingSubtableNoticeFired = [];

    private readonly LoggerInterface $logger;

    /**
     * @param EventDispatcherInterface $eventDispatcher The event dispatcher for entity lifecycle events.
     * @param \Closure|null $storageFactory A factory callable: fn(EntityTypeInterface): EntityStorageInterface.
     *                                     If null, getStorage() will throw when no storage class is configured.
     * @param \Closure|null $repositoryFactory A factory callable: fn(string $entityTypeId, EntityTypeInterface): EntityRepositoryInterface.
     *                                          If null, getRepository() throws.
     * @param FieldDefinitionRegistryInterface|null $fieldRegistry Registry for core and bundle-scoped field definitions.
     *                                                             If null, addBundleFields() and getFieldRegistry() throw.
     *                                                             Core fields are registered into the registry at
     *                                                             registerEntityType() time when this is provided.
     * @param LoggerInterface|null $logger Receives the once-per-type
     *                                     `HasCommunityInterface` deprecation warning (mission #1257 §C1).
     *                                     Defaults to `NullLogger` for callers that don't wire logging.
     * @param \Closure|null $bundleSubtableExistsProbe Optional probe used by
     *                                     {@see addBundleFields()} to detect whether the per-bundle
     *                                     subtable already exists on disk. Signature:
     *                                     `fn(string $entityTypeId, string $bundle): bool`.
     *                                     When provided and the probe returns false, a
     *                                     `[BUNDLE_SUBTABLE_MISSING]` notice is emitted once per
     *                                     (entity_type_id, bundle) pair (issue #1376). Defaults to
     *                                     null — the notice is silent for callers without a schema
     *                                     accessor (tests, bare bootstraps).
     */
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ?\Closure $storageFactory = null,
        private readonly ?\Closure $repositoryFactory = null,
        private readonly ?FieldDefinitionRegistryInterface $fieldRegistry = null,
        ?LoggerInterface $logger = null,
        private readonly ?\Closure $bundleSubtableExistsProbe = null,
        // Optional materializer used by addBundleFields() to auto-create and
        // migrate the per-bundle subtable when fields are registered, so the
        // values land in real typed columns rather than being skipped at save
        // time. Signature: `fn(EntityTypeInterface $type, string $bundle): void`.
        // Defaults to null for callers without a schema accessor (tests, bare
        // bootstraps); registration still succeeds, and the missing-subtable
        // notice/probe path documents that no columns were materialized.
        private readonly ?\Closure $bundleSubtableMaterializer = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Register an entity type definition.
     *
     * The `core.` namespace is reserved for built-in platform types. Attempting
     * to register a type with a `core.*` ID via this method throws NAMESPACE_RESERVED.
     * Use registerCoreEntityType() for platform-level registrations.
     *
     * @throws \DomainException        If the entity type ID uses the reserved `core.` namespace.
     * @throws \InvalidArgumentException If an entity type with the same ID is already registered.
     */
    public function registerEntityType(EntityTypeInterface $type, ?string $registrant = null): void
    {
        if (str_starts_with($type->id(), 'core.')) {
            throw new \DomainException(\sprintf(
                '[NAMESPACE_RESERVED] The "core." namespace is reserved for built-in platform types. '
                . 'Entity type "%s" cannot be registered by extensions or tenants. '
                . 'Use a custom namespace prefix instead.',
                $type->id(),
            ));
        }

        $this->persistDefinition($type, $registrant);
    }

    /**
     * Register a built-in platform entity type, bypassing the `core.` namespace guard.
     *
     * Only kernel boot code and core service providers should call this method.
     *
     * @throws \InvalidArgumentException If an entity type with the same ID is already registered.
     */
    public function registerCoreEntityType(EntityTypeInterface $type, ?string $registrant = null): void
    {
        $this->persistDefinition($type, $registrant);
    }

    private function persistDefinition(EntityTypeInterface $type, ?string $registrant = null): void
    {
        if (isset($this->definitions[$type->id()])) {
            $existingDefinition = $this->definitions[$type->id()];
            $existingRegistrant = $this->definitionRegistrants[$type->id()] ?? null;

            if ($existingDefinition->getClass() === $type->getClass()) {
                throw EntityTypeRegistrationCollisionException::duplicate(
                    $type->id(),
                    $existingRegistrant,
                    $existingDefinition->getClass(),
                    $registrant,
                    $type->getClass(),
                );
            }

            throw EntityTypeRegistrationCollisionException::shadowCollision(
                $type->id(),
                $existingRegistrant,
                $existingDefinition->getClass(),
                $registrant,
                $type->getClass(),
            );
        }

        $this->definitions[$type->id()] = $type;
        $this->definitionRegistrants[$type->id()] = $registrant;

        $this->fieldRegistry?->registerCoreFields($type->id(), $type->getFieldDefinitions());

        $this->maybeWarnLegacyTenancyMarker($type);
    }

    /**
     * Mission #1257 §C1: emit a one-time deprecation warning per entity-type id
     * when the registered class still implements the legacy
     * {@see HasCommunityInterface} marker but the type's declarative
     * `tenancy` slot is null. Apps that already declare
     * `tenancy: ['scope' => 'community']` are silent.
     *
     * Memoization is per type id (not per class), matching how the
     * EntityTypeManager treats type-id collisions elsewhere — duplicate
     * registrations under the same id are rejected upstream, so a type id
     * gets registered exactly once and the warning fires exactly once.
     */
    private function maybeWarnLegacyTenancyMarker(EntityTypeInterface $type): void
    {
        if ($type->getTenancy() !== null) {
            return;
        }

        $class = $type->getClass();
        if (!is_a($class, HasCommunityInterface::class, true)) {
            return;
        }

        if (isset($this->tenancyDeprecationFired[$type->id()])) {
            return;
        }
        $this->tenancyDeprecationFired[$type->id()] = true;

        $this->logger->warning(
            \sprintf(
                '[deprecated] HasCommunityInterface on entity-type "%s" (class %s) — '
                . 'declare tenancy: [\'scope\' => \'community\'] on the EntityType registration. '
                . 'Marker support will be removed in the next minor release. '
                . 'See mission #1257 §C1 / packages/groups/CHANGELOG.md.',
                $type->id(),
                $class,
            ),
            [
                'entity_type' => $type->id(),
                'entity_class' => $class,
                'mission' => '1257',
                'contract' => 'C1',
            ],
        );
    }

    /**
     * Register bundle-scoped fields on a multi-bundle entity type.
     *
     * See docs/specs/bundle-scoped-fields.md for the full contract.
     *
     * @param array<string|int, object> $fields FieldDefinition objects whose
     *                                          targetEntityTypeId and targetBundle
     *                                          match the arguments.
     *
     * @throws \InvalidArgumentException If the entity type is not registered, does
     *                                   not declare bundleEntityType, or any field
     *                                   fails validation (see registry).
     * @throws \RuntimeException         If no FieldDefinitionRegistry is configured.
     */
    public function addBundleFields(string $entityTypeId, string $bundle, array $fields): void
    {
        if ($this->fieldRegistry === null) {
            throw new \RuntimeException(\sprintf(
                'Cannot register bundle fields for entity type "%s" bundle "%s": no FieldDefinitionRegistry configured on EntityTypeManager.',
                $entityTypeId,
                $bundle,
            ));
        }

        $type = $this->getDefinition($entityTypeId);

        if ($type->getBundleEntityType() === null) {
            throw new \InvalidArgumentException(\sprintf(
                'Entity type "%s" does not declare bundleEntityType; cannot register bundle-scoped fields.',
                $entityTypeId,
            ));
        }

        if (str_contains($bundle, '__')) {
            throw new \InvalidArgumentException(\sprintf(
                'Bundle identifier "%s" for entity type "%s" contains the reserved separator "__"; '
                . 'it cannot be used in bundle-scoped field registration.',
                $bundle,
                $entityTypeId,
            ));
        }

        $this->fieldRegistry->registerBundleFields($entityTypeId, $bundle, $fields);

        // Auto-create/migrate the per-bundle subtable so the just-registered
        // fields persist in real typed columns. Idempotent and safe under the
        // zero-and-re-migrate loop (create-if-missing, else add missing columns).
        $this->materializeBundleSubtable($type, $bundle);

        // Advisory: fires only when no materializer is wired and the subtable is
        // still absent (e.g. a bare bootstrap). With a materializer present the
        // probe finds the freshly-created subtable and stays silent.
        $this->maybeEmitMissingSubtableNotice($entityTypeId, $bundle);
    }

    /**
     * Invoke the configured subtable materializer for a freshly-registered
     * bundle, if one is wired. Best-effort: a materialization failure is logged
     * and does not abort registration (the fields are still registered and the
     * save-time path keeps a logged _data fallback).
     */
    private function materializeBundleSubtable(EntityTypeInterface $type, string $bundle): void
    {
        if ($this->bundleSubtableMaterializer === null) {
            return;
        }

        try {
            ($this->bundleSubtableMaterializer)($type, $bundle);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf(
                'Failed to materialize bundle subtable for entity type "%s" bundle "%s": %s',
                $type->id(),
                $bundle,
                $e->getMessage(),
            ));
        }
    }

    /**
     * Emit a once-per-(entity_type_id, bundle) `[BUNDLE_SUBTABLE_MISSING]`
     * notice when a probe is configured and the per-bundle subtable does not
     * yet exist on disk. Issue #1376 (deferred WP07-A from mission #1257).
     *
     * The notice is informational. Save-time and load-time paths in
     * `EntityRepository` already handle the missing-subtable case (the values
     * are skipped silently); this notice surfaces the registration-time
     * signal so operators see the gap before any save attempts.
     *
     * Probe failures are swallowed — we never fail registration over an
     * advisory check.
     */
    private function maybeEmitMissingSubtableNotice(string $entityTypeId, string $bundle): void
    {
        if ($this->bundleSubtableExistsProbe === null) {
            return;
        }

        $cacheKey = $entityTypeId . '.' . $bundle;
        if (isset($this->missingSubtableNoticeFired[$cacheKey])) {
            return;
        }

        try {
            $exists = (bool) ($this->bundleSubtableExistsProbe)($entityTypeId, $bundle);
        } catch (\Throwable $e) {
            $this->logger->info(\sprintf(
                'Bundle-subtable existence probe failed for entity type "%s" bundle "%s": %s',
                $entityTypeId,
                $bundle,
                $e->getMessage(),
            ));
            return;
        }

        if ($exists) {
            return;
        }

        $this->missingSubtableNoticeFired[$cacheKey] = true;

        $this->logger->notice(\sprintf(
            '[BUNDLE_SUBTABLE_MISSING] Bundle-scoped fields registered for entity type "%s" bundle "%s", but subtable "%s__%s" does not exist on disk. Bundle-field saves and loads will skip the missing subtable until a schema migration materializes it.',
            $entityTypeId,
            $bundle,
            $entityTypeId,
            $bundle,
        ));
    }

    /**
     * Returns the configured FieldDefinitionRegistry.
     *
     * @throws \RuntimeException If no registry was configured.
     */
    public function getFieldRegistry(): FieldDefinitionRegistryInterface
    {
        if ($this->fieldRegistry === null) {
            throw new \RuntimeException('No FieldDefinitionRegistry configured on EntityTypeManager.');
        }

        return $this->fieldRegistry;
    }

    public function getDefinition(string $entityTypeId): EntityTypeInterface
    {
        if (!isset($this->definitions[$entityTypeId])) {
            throw new \InvalidArgumentException(\sprintf(
                'Entity type "%s" is not registered.',
                $entityTypeId,
            ));
        }

        return $this->definitions[$entityTypeId];
    }

    public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array
    {
        $type = $this->getDefinition($entityTypeId);

        // Class-declared base fields are the floor.
        $fields = $type->getFieldDefinitions();

        if ($this->fieldRegistry === null) {
            return $fields;
        }

        // Registry core fields already include the class base fields (seeded at
        // registerEntityType time) plus any registered at runtime; union keeps
        // the base fields present even if the registry was not seeded.
        foreach ($this->fieldRegistry->coreFieldsFor($entityTypeId) as $name => $definition) {
            $fields[$name] = $definition;
        }

        // A real bundle (not the bare entity-type id, not empty) contributes its
        // distinct typed fields, so page and news resolve to different sets.
        if ($bundle !== null && $bundle !== '' && $bundle !== $entityTypeId) {
            foreach ($this->fieldRegistry->bundleFieldsFor($entityTypeId, $bundle) as $name => $definition) {
                $fields[$name] = $definition;
            }
        }

        return $fields;
    }

    /** @return array<string, EntityTypeInterface> */
    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    public function hasDefinition(string $entityTypeId): bool
    {
        return isset($this->definitions[$entityTypeId]);
    }

    public function getStorage(string $entityTypeId): Storage\EntityStorageInterface
    {
        if (isset($this->storageInstances[$entityTypeId])) {
            return $this->storageInstances[$entityTypeId];
        }

        $definition = $this->getDefinition($entityTypeId);

        if ($this->storageFactory !== null) {
            $storage = ($this->storageFactory)($definition);
        } else {
            $storageClass = $definition->getStorageClass();

            if ($storageClass === '') {
                throw new \RuntimeException(\sprintf(
                    'No storage class configured for entity type "%s" and no storage factory provided.',
                    $entityTypeId,
                ));
            }

            $storage = new $storageClass();
        }

        if (!$storage instanceof Storage\EntityStorageInterface) {
            throw new \RuntimeException(\sprintf(
                'Storage for entity type "%s" must implement %s.',
                $entityTypeId,
                Storage\EntityStorageInterface::class,
            ));
        }

        $this->storageInstances[$entityTypeId] = $storage;

        return $storage;
    }

    public function getRepository(string $entityTypeId): EntityRepositoryInterface
    {
        if (isset($this->repositoryInstances[$entityTypeId])) {
            return $this->repositoryInstances[$entityTypeId];
        }

        if ($this->repositoryFactory === null) {
            throw new \RuntimeException(\sprintf(
                'No repository factory configured for EntityTypeManager; cannot build repository for entity type "%s".',
                $entityTypeId,
            ));
        }

        $definition = $this->getDefinition($entityTypeId);
        $repository = ($this->repositoryFactory)($entityTypeId, $definition);

        if (!$repository instanceof EntityRepositoryInterface) {
            throw new \RuntimeException(\sprintf(
                'Repository factory for entity type "%s" must return an instance of %s.',
                $entityTypeId,
                EntityRepositoryInterface::class,
            ));
        }

        $this->repositoryInstances[$entityTypeId] = $repository;

        return $repository;
    }

    /**
     * Get the event dispatcher.
     */
    public function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }
}

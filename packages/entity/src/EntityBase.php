<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

use Symfony\Component\Uid\Uuid;
use Waaseyaa\Access\CompiledFieldReadRule;
use Waaseyaa\Entity\Cast\ValueCaster;
use Waaseyaa\Entity\Concurrency\EntityMutationToken;

/**
 * Abstract base class for all entity types.
 *
 * Provides default implementations of EntityInterface methods.
 * Subclasses must define their entity type ID.
 *
 * Subclasses may declare a {@see $casts} map (Laravel-style). Incoming constructor
 * `$values` remain storage-canonical (no casting on construct); {@see get()} and
 * {@see set()} apply {@see ValueCaster} when a field has an entry in `$casts`.
 */
abstract class EntityBase implements EntityInterface
{
    /** Snapshot authority attached only by a repository hydration boundary. */
    private ?EntityMutationToken $mutationToken = null;
    /** Immutable non-content selectors attached by the closed hydrator. */
    private ?EntityStructure $entityStructure = null;
    /**
     * The entity type ID (e.g. 'node', 'user').
     *
     * Subclasses must set this to their entity type's machine name.
     */
    protected string $entityTypeId = '';

    /** Private authoritative value storage; subclasses never receive the container. */
    private EntityValueContainer $valueContainer;

    private bool $valueContainerInitialized = false;

    /** @var array<string, EntityValueContainer> Private sealed translation/fallback views. */
    private array $translationValueContainers = [];

    /**
     * Whether to force this entity to be treated as new.
     */
    protected bool $enforceIsNew = false;

    /**
     * Entity key mappings from the entity type definition.
     *
     * @var array<string, string>
     */
    protected array $entityKeys = [];

    /**
     * Per-field cast specifications (string token, backed enum class-string,
     * {@see \Waaseyaa\Entity\Cast\FromArrayEntityValueInterface} class-string, or `['type' => ...]`).
     *
     * @var array<string, string|array<string, mixed>>
     */
    protected array $casts = [];

    /**
     * @param array<string, mixed> $values Initial entity values.
     * @param string $entityTypeId The entity type machine name.
     * @param array<string, string> $entityKeys Entity key mappings (id, uuid, label, etc.).
     */
    public function __construct(array $values = [], string $entityTypeId = '', array $entityKeys = [])
    {
        if ($entityTypeId !== '') {
            $this->entityTypeId = $entityTypeId;
        }

        if ($entityKeys !== []) {
            $this->entityKeys = $entityKeys;
        }

        // UUID generation happens before sealing so construction never needs a
        // privileged read or a mutable compatibility bag.
        if (isset($this->entityKeys['uuid'])) {
            $uuidKey = $this->entityKeys['uuid'];
            if (!isset($values[$uuidKey]) || $values[$uuidKey] === '') {
                $values[$uuidKey] = Uuid::v4()->toRfc4122();
            }
        }

        $layout = EntityReadRuntime::layoutFor(static::class, $values, $this->entityTypeId, $this->entityKeys);
        $this->valueContainer = EntityValueContainer::seal($values, $layout, null);
        $this->valueContainerInitialized = true;
        $this->entityStructure = EntityReadRuntime::structureFor($values, $this->entityTypeId, $this->entityKeys, $layout);
    }

    /**
     * Atomic V2 initialization hook. The paired opaque identities prevent a
     * payload from another boundary from installing repository values.
     *
     * @internal EntityInitialization only.
     * @param array<string, string> $entityKeys
     */
    final public function _installSealedInitialization(
        EntityValueContainer $container,
        EntityStructure $structure,
        string $entityTypeId,
        array $entityKeys,
        EntityInitializationIdentity $presentedIdentity,
        EntityInitializationIdentity $expectedIdentity,
    ): void {
        if ($presentedIdentity !== $expectedIdentity) {
            throw new \LogicException('A matching entity initialization boundary is required.');
        }
        if ($this->valueContainerInitialized || $this->entityStructure !== null) {
            throw new \LogicException('An entity can be initialized exactly once.');
        }
        $this->valueContainer = $container;
        $this->valueContainerInitialized = true;
        $this->entityStructure = $structure;
        $this->entityTypeId = $entityTypeId;
        $this->entityKeys = $entityKeys;
    }

    public function id(): int|string|null
    {
        if ($this->entityStructure !== null) {
            return $this->entityStructure->id;
        }
        $idKey = $this->entityKeys['id'] ?? 'id';

        return $this->get($idKey);
    }

    public function uuid(): string
    {
        if ($this->entityStructure !== null) {
            return $this->entityStructure->uuid ?? '';
        }
        $uuidKey = $this->entityKeys['uuid'] ?? 'uuid';

        return (string) ($this->get($uuidKey) ?? '');
    }

    public function label(): string
    {
        $labelKey = $this->entityKeys['label'] ?? 'label';

        return (string) ($this->get($labelKey) ?? '');
    }

    public function getEntityTypeId(): string
    {
        return $this->entityTypeId;
    }

    /**
     * Return structural selectors without obtaining a field value.
     *
     * @api
     */
    final public function entityStructure(): EntityStructure
    {
        return $this->entityStructure ?? throw new \LogicException('Entity structure has not been attached.');
    }

    /** @internal bootstrap probe; reports metadata attachment only. */
    final public function _hasEntityStructure(): bool
    {
        return $this->entityStructure !== null;
    }

    /** @internal repository identity backfill only. */
    final public function _hydrateStructuralId(int|string $id): void
    {
        $current = $this->entityStructure();
        $this->valueContainer->write($this, $this->entityKeys['id'] ?? 'id', $id);
        $this->entityStructure = new EntityStructure(
            $current->entityTypeId,
            $current->bundleId,
            $id,
            $current->uuid,
            $current->activeLanguageId,
            $current->defaultLanguageId,
            $current->knownTranslationIds,
            $current->revisionId,
            $current->revisionTip,
            $current->defaultRevision,
            $current->fieldNames,
        );
    }

    /** @internal repository revision hydration only. */
    final public function _hydrateStructuralRevision(int|string $revisionId, bool $tip = true, bool $default = true): void
    {
        $current = $this->entityStructure();
        $this->valueContainer->write($this, $this->entityKeys['revision'] ?? 'revision_id', $revisionId);
        $this->entityStructure = new EntityStructure(
            $current->entityTypeId,
            $current->bundleId,
            $current->id,
            $current->uuid,
            $current->activeLanguageId,
            $current->defaultLanguageId,
            $current->knownTranslationIds,
            $revisionId,
            $tip,
            $default,
            $current->fieldNames,
        );
    }

    /** @internal repository/translation hydrator structural selectors only. @param list<string> $known */
    final public function _hydrateStructuralLanguages(string $active, string $default, array $known): void
    {
        $current = $this->entityStructure();
        $this->valueContainer->write($this, $this->entityKeys['langcode'] ?? 'langcode', $active);
        $this->valueContainer->write($this, $this->entityKeys['default_langcode'] ?? 'default_langcode', $default);
        $known = array_values(array_unique($known));
        sort($known);
        $this->entityStructure = new EntityStructure(
            $current->entityTypeId,
            $current->bundleId,
            $current->id,
            $current->uuid,
            $active,
            $default,
            $known,
            $current->revisionId,
            $current->revisionTip,
            $current->defaultRevision,
            $current->fieldNames,
        );
    }

    /** The opaque expectation for this loaded aggregate snapshot. */
    final public function mutationToken(): ?EntityMutationToken
    {
        return $this->mutationToken;
    }

    /** @internal Repository hydration/successor installation only. */
    final public function _hydrateMutationToken(EntityMutationToken $token): void
    {
        $id = $this->id();
        if ($id === null || $token->entityTypeId !== $this->entityTypeId || $token->entityId !== (string) $id) {
            throw new \LogicException('Mutation token does not match the entity snapshot identity.');
        }
        $this->mutationToken = $token;
    }

    public function bundle(): string
    {
        if ($this->entityStructure !== null) {
            return $this->entityStructure->bundleId;
        }
        $bundleKey = $this->entityKeys['bundle'] ?? 'bundle';

        // Default bundle is the entity type ID itself when no bundle key exists.
        return (string) ($this->get($bundleKey) ?? $this->entityTypeId);
    }

    public function isNew(): bool
    {
        return $this->enforceIsNew || $this->id() === null;
    }

    /**
     * Force the entity to be considered new (or not).
     */
    public function enforceIsNew(bool $value = true): static
    {
        $this->enforceIsNew = $value;

        return $this;
    }

    /**
     * Resolves the caster used by {@see get()} / {@see set()}. Override in tests to inject a custom caster.
     */
    protected function valueCaster(): ValueCaster
    {
        return new ValueCaster();
    }

    /**
     * Cast specification for a field, if any (used by storage / tooling; #1183).
     *
     * @return string|array<string, mixed>|null
     */
    public function getCastSpecForField(string $field): string|array|null
    {
        return $this->casts[$field] ?? null;
    }

    public function get(string $name): mixed
    {
        $raw = $this->valueContainer->read($this, $name);

        if (isset($this->casts[$name])) {
            return $this->valueCaster()->castIn($name, $raw, $this->casts[$name]);
        }

        return $raw;
    }

    public function set(string $name, mixed $value): static
    {
        if ($this->entityStructure !== null && in_array($name, $this->structuralFieldNames(), true)) {
            $idField = $this->entityKeys['id'] ?? 'id';
            if ($name === $idField && $this->entityStructure->id === null && (is_int($value) || is_string($value))) {
                $this->_hydrateStructuralId($value);

                return $this;
            }
            throw new \LogicException(sprintf('Structural field %s.%s is immutable after construction.', $this->entityTypeId, $name));
        }
        $stored = isset($this->casts[$name])
            ? $this->valueCaster()->castOut($name, $value, $this->casts[$name])
            : $value;
        $this->valueContainer->write($this, $name, $stored);

        return $this;
    }

    /** @return list<string> */
    private function structuralFieldNames(): array
    {
        $fields = [
            $this->entityKeys['id'] ?? 'id',
            $this->entityKeys['bundle'] ?? 'bundle',
            $this->entityKeys['langcode'] ?? 'langcode',
            $this->entityKeys['default_langcode'] ?? 'default_langcode',
        ];
        foreach (['uuid', 'revision'] as $kind) {
            if (isset($this->entityKeys[$kind])) {
                $fields[] = $this->entityKeys[$kind];
            }
        }

        return array_values(array_unique($fields));
    }

    public function toArray(): array
    {
        return $this->valueContainer->publicArray($this);
    }

    /** Non-value-bearing field/layout enumeration. @api @return list<string> */
    final public function fieldNames(): array
    {
        return $this->valueContainer->fieldNames();
    }

    /** @internal Validation planning and semantic gates only. */
    final public function fieldReadLevel(string $field): FieldReadLevel
    {
        return $this->valueContainer->level($field);
    }

    /** @internal Guard hot path over the layout's stable compiled rule. */
    final public function compiledFieldReadRule(string $field): CompiledFieldReadRule
    {
        return $this->valueContainer->rule($field);
    }

    /**
     * Every object view reissues restricted cells and its policy-cache identity.
     */
    final public function __clone(): void
    {
        $this->valueContainer = $this->valueContainer->reissue();
        foreach ($this->translationValueContainers as $langcode => $container) {
            $this->translationValueContainers[$langcode] = $container->reissue();
        }
    }

    /** @internal Reviewed translation implementation only. @param array<string, array<string, mixed>> $data */
    final protected function replaceTranslationValues(array $data): void
    {
        $containers = [];
        foreach ($data as $langcode => $values) {
            $containers[$langcode] = $this->valueContainer->relatedView($values);
        }
        $this->translationValueContainers = $containers;
    }

    /** @internal Reviewed translation implementation only. */
    final protected function hasTranslationValues(string $langcode): bool
    {
        return isset($this->translationValueContainers[$langcode]);
    }

    /** @internal Reviewed translation implementation only. */
    final protected function readTranslationValue(string $langcode, string $field): mixed
    {
        return $this->translationValueContainers[$langcode]->read($this, $field);
    }

    /** @internal Reviewed translation implementation only. @return list<string> */
    final protected function translationValueLanguages(): array
    {
        return array_keys($this->translationValueContainers);
    }

    /** @internal Reviewed translation implementation only. */
    final protected function addTranslationValues(string $langcode): void
    {
        $this->translationValueContainers[$langcode] = $this->valueContainer->relatedView([]);
    }

    /** @internal Reviewed translation implementation only. */
    final protected function removeTranslationValues(string $langcode): void
    {
        unset($this->translationValueContainers[$langcode]);
    }

    /** @internal Reviewed translation implementation only. */
    final protected function translationValueHasField(string $langcode, string $field): bool
    {
        return isset($this->translationValueContainers[$langcode])
            && $this->translationValueContainers[$langcode]->has($field);
    }

    /**
     * Shallow copy with the same identity keys and {@see $enforceIsNew} flag.
     *
     * Sealed entities clone their opaque container so raw values never cross a virtual reconstruction hook.
     */
    public function duplicate(): static
    {
        $copy = clone $this;
        $copy->enforceIsNew($this->enforceIsNew);

        return $copy;
    }

    /**
     * Reconstruct {@see static} from a shallow-copied value bag. Subclasses with extra constructor
     * parameters MUST override this so {@see duplicate()} re-enters their constructor chain (P3).
     *
     * @param array<string, mixed> $values
     */
    protected function duplicateInstance(array $values): static
    {
        $class = static::class;

        return new $class($values, $this->entityTypeId, $this->entityKeys);
    }

    /**
     * Immutable-style update: {@see duplicate()} then {@see set()}. Throws the same exceptions as {@see set()}.
     */
    public function with(string $name, mixed $value): static
    {
        return $this->duplicate()->set($name, $value);
    }

    /**
     * @param array<string, mixed> $overrides Field name => value (domain-shaped; same as {@see set()}).
     *
     * @return static Later keys in the array win if the same field appears twice (PHP array semantics).
     */
    public function withValues(array $overrides): static
    {
        $copy = $this->duplicate();
        foreach ($overrides as $key => $value) {
            $copy->set($key, $value);
        }

        return $copy;
    }

    public function language(): string
    {
        if ($this->entityStructure !== null) {
            return $this->entityStructure->activeLanguageId;
        }
        $langcodeKey = $this->entityKeys['langcode'] ?? 'langcode';

        return (string) ($this->get($langcodeKey) ?? 'en');
    }

    /**
     * Called before the entity is persisted. Override for custom logic.
     */
    public function preSave(bool $isNew): void {}

    /**
     * Called after the entity is successfully persisted. Override for custom logic.
     */
    public function postSave(bool $isNew): void {}

    /**
     * Called before the entity is deleted. Override for custom logic.
     */
    public function preDelete(): void {}

    /**
     * Called after the entity is successfully deleted. Override for custom logic.
     */
    public function postDelete(): void {}
}

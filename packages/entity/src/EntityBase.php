<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

use Symfony\Component\Uid\Uuid;
use Waaseyaa\Entity\Cast\ValueCaster;

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

        $this->valueContainer = EntityValueContainer::compatibility($values);
        $this->valueContainerInitialized = true;

        // Auto-generate UUID only when the entity type defines a uuid key.
        if (isset($this->entityKeys['uuid'])) {
            $uuidKey = $this->entityKeys['uuid'];
            if (!$this->valueContainer->has($uuidKey) || $this->valueContainer->read($this, $uuidKey) === '') {
                $this->valueContainer->write($this, $uuidKey, Uuid::v4()->toRfc4122());
            }
        }
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

    /**
     * Repository hydration hook. Application code must not call this method.
     * A structure is write-once so a returned entity cannot be retargeted.
     *
     * @internal EntityInstantiator only.
     */
    final public function _attachEntityStructure(EntityStructure $structure): void
    {
        if ($this->entityStructure !== null) {
            throw new \LogicException('Entity structure is immutable after hydration.');
        }
        $this->entityStructure = $structure;
    }

    /** @internal repository identity backfill only. */
    final public function _hydrateStructuralId(int|string $id): void
    {
        $current = $this->entityStructure();
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
        $stored = isset($this->casts[$name])
            ? $this->valueCaster()->castOut($name, $value, $this->casts[$name])
            : $value;
        $this->valueContainer->write($this, $name, $stored);

        return $this;
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

    /** @internal Closed reader authority; callers are private bound closures only. @return array<string, mixed> */
    private function rawValuesForClosedAuthority(): array
    {
        return $this->valueContainer->rawValues();
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
     * Sealed entities clone their opaque container so raw values never cross a virtual reconstruction
     * hook. Compatibility entities retain constructor re-entry through {@see duplicateInstance()}.
     */
    public function duplicate(): static
    {
        if ($this->valueContainer->isSealed()) {
            $copy = clone $this;
            $copy->enforceIsNew($this->enforceIsNew);

            return $copy;
        }

        $shallowValues = [];
        foreach ($this->rawValuesForClosedAuthority() as $key => $value) {
            $shallowValues[$key] = $value;
        }

        $copy = $this->duplicateInstance($shallowValues);
        $copy->enforceIsNew($this->enforceIsNew);
        if ($this->entityStructure !== null) {
            $copy->_attachEntityStructure($this->entityStructure);
        }

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

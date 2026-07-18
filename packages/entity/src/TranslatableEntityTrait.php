<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

use Waaseyaa\Entity\Exception\EntityTranslationException;
use Waaseyaa\Entity\Hydration\FallbackChainResolver;

/**
 * Default implementation of TranslatableInterface for content entities.
 *
 * ContentEntityBase uses this trait. Future implementors that need translation
 * support may also use it provided they also implement TranslatableInterface and
 * satisfy the abstract contract declared below.
 *
 * State:
 *   $activeLangcode           — the langcode this entity object represents (null = use default).
 *   $translationData          — langcode → field-values map; populated by storage during hydration.
 *   $fieldLangcodes           — field-name → resolved-langcode (null when fallback exhausted); set by WP06.
 *   $pendingTranslationDeletions — langcodes to delete on next save; drained by WP07 coordinator.
 *
 * @api
 */
trait TranslatableEntityTrait
{
    // -------------------------------------------------------------------------
    // Abstract contract — using class must satisfy these
    // -------------------------------------------------------------------------

    /**
     * Returns the EntityType definition for this entity.
     *
     * ContentEntityBase implements this via a static EntityTypeManager registry
     * wired at kernel boot. Other implementors must supply their own implementation.
     */
    abstract public function getEntityType(): EntityTypeInterface;

    // -------------------------------------------------------------------------
    // Trait state
    // -------------------------------------------------------------------------

    /**
     * The langcode this entity object is currently active in.
     *
     * Null means "use defaultLangcode()".
     */
    protected ?string $activeLangcode = null;

    /**
     * Langcode → field-values map. Populated by storage backends during hydration (WP04/WP05).
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $translationData = [];

    /**
     * Field-name → resolved-langcode (null when fallback exhausted). Populated by FallbackChainResolver (WP06).
     *
     * @var array<string, ?string>
     */
    protected array $fieldLangcodes = [];

    /**
     * Langcodes marked for deletion on the next save. Drained by the translation coordinator (WP07).
     *
     * @var array<string>
     */
    protected array $pendingTranslationDeletions = [];

    /**
     * Optional fallback resolver consulted during translatable-field reads (WP06).
     *
     * Wired post-hydration by storage / EntityRepository via {@see _setFallbackResolver()}.
     * Null means "no resolver configured" — reads then return only the active langcode's
     * raw stored value (parent EntityBase::get() behaviour).
     */
    protected ?FallbackChainResolver $fallbackResolver = null;

    // -------------------------------------------------------------------------
    // TranslatableInterface implementation
    // -------------------------------------------------------------------------

    public function defaultLangcode(): string
    {
        $defaultLc = $this->values['default_langcode'] ?? null;

        if ($defaultLc === null || $defaultLc === '') {
            throw EntityTranslationException::langcodeRequired();
        }

        return (string) $defaultLc;
    }

    public function activeLangcode(): string
    {
        return $this->activeLangcode ?? $this->defaultLangcode();
    }

    /**
     * {@inheritDoc}
     *
     * Backward-compatibility behaviour (pre-M-006): when the entity has no
     * `default_langcode` set (e.g., a non-translatable entity that inherits this
     * trait via ContentEntityBase), fall back to the `langcode` value or `'en'`
     * instead of throwing — unlike activeLangcode(), which throws. Both are
     * supported, distinct accessors; pick by whether a missing default should be
     * an error. Translatable entity types should still set `default_langcode`
     * explicitly per FR-034.
     */
    public function language(): string
    {
        $defaultLc = $this->values['default_langcode'] ?? null;
        if ($defaultLc === null || $defaultLc === '') {
            $langcodeKey = $this->entityKeys['langcode'] ?? 'langcode';

            return (string) ($this->values[$langcodeKey] ?? $this->values['langcode'] ?? 'en');
        }

        return $this->activeLangcode();
    }

    public function hasTranslation(string $langcode): bool
    {
        $this->assertTranslatable();

        return \array_key_exists($langcode, $this->translationData);
    }

    public function getTranslation(string $langcode): static
    {
        $this->assertTranslatable();

        if (!$this->hasTranslation($langcode)) {
            throw EntityTranslationException::translationNotFound($langcode);
        }

        if ($langcode === $this->activeLangcode()) {
            return $this;
        }

        $clone = clone $this;
        $clone->activeLangcode = $langcode;
        $clone->syncStructuralLanguages($langcode, $clone->defaultLangcode(), $clone->getTranslationLanguages());

        return $clone;
    }

    public function addTranslation(string $langcode): static
    {
        $this->assertTranslatable();

        if ($this->hasTranslation($langcode)) {
            throw EntityTranslationException::translationAlreadyExists($langcode);
        }

        $this->translationData[$langcode] = [];

        $clone = clone $this;
        $clone->activeLangcode = $langcode;
        $clone->syncStructuralLanguages($langcode, $clone->defaultLangcode(), $clone->getTranslationLanguages());

        return $clone;
    }

    public function removeTranslation(string $langcode): void
    {
        $this->assertTranslatable();

        if ($langcode === $this->defaultLangcode()) {
            throw EntityTranslationException::cannotRemoveDefault($langcode);
        }

        if ($this->hasTranslation($langcode)) {
            unset($this->translationData[$langcode]);
            $this->pendingTranslationDeletions[] = $langcode;
            $this->syncStructuralLanguages($this->activeLangcode(), $this->defaultLangcode(), $this->getTranslationLanguages());
        }
    }

    /**
     * {@inheritDoc}
     *
     * @return iterable<string>
     */
    public function translations(): iterable
    {
        $this->assertTranslatable();

        $defaultLc = $this->defaultLangcode();
        $others = \array_keys($this->translationData);
        \sort($others);

        yield $defaultLc;

        foreach ($others as $lc) {
            if ($lc !== $defaultLc) {
                yield $lc;
            }
        }
    }

    /**
     * {@inheritDoc}
     *
     * @return string[]
     */
    public function getTranslationLanguages(): array
    {
        return \iterator_to_array($this->translations(), false);
    }

    // -------------------------------------------------------------------------
    // @internal helpers for storage backends and the translation coordinator
    // -------------------------------------------------------------------------

    /**
     * Hydration entry-point: storage backends call this after loading a row.
     *
     * @internal WP04/WP05 storage backends only.
     *
     * @param array<string, array<string, mixed>> $data   langcode → field-values map
     * @param string                              $defaultLc the default langcode to store in values
     */
    public function _setTranslationData(array $data, string $defaultLc): void
    {
        $this->translationData = $data;
        $this->values['default_langcode'] = $defaultLc;
        $this->activeLangcode = null;
        $known = array_keys($data);
        if (!in_array($defaultLc, $known, true)) {
            $known[] = $defaultLc;
        }
        $this->syncStructuralLanguages($defaultLc, $defaultLc, $known);
    }

    /** @param list<string> $known */
    private function syncStructuralLanguages(string $active, string $default, array $known): void
    {
        EntityStructureSynchronizer::languages($this, $active, $default, $known);
    }

    /**
     * Returns and clears the pending deletion list.
     *
     * @internal WP07 translation coordinator only.
     *
     * @return array<string>
     */
    public function _takePendingTranslationDeletions(): array
    {
        $taken = $this->pendingTranslationDeletions;
        $this->pendingTranslationDeletions = [];

        return $taken;
    }

    /**
     * Wire a fallback-chain resolver into this entity instance.
     *
     * Typically called by storage / `EntityRepository` (WP10) immediately
     * after hydration so that field reads consult the configured chain.
     *
     * @internal WP06+ — storage, repository, and the translation coordinator only.
     */
    public function _setFallbackResolver(FallbackChainResolver $resolver): void
    {
        $this->fallbackResolver = $resolver;
    }

    /**
     * Returns the langcode that last produced a value for `$fieldName`, or
     * `null` if the field is unknown or every chain entry was exhausted.
     *
     * Populated by {@see get()} during translatable-field reads (FR-039).
     */
    public function fieldLangcode(string $fieldName): ?string
    {
        return $this->fieldLangcodes[$fieldName] ?? null;
    }

    // -------------------------------------------------------------------------
    // get() override — translatable-aware field reads (FR-037 / FR-038)
    // -------------------------------------------------------------------------

    /**
     * Read a field value, walking the fallback chain for translatable fields.
     *
     * Behaviour matrix:
     *   - Entity type is **not** translatable → defer to `parent::get()` unchanged (NFR-001).
     *   - Field is **not** translatable → defer to `parent::get()` unchanged.
     *   - No resolver wired → return the active-langcode value directly; cache the resolved
     *     langcode in `$fieldLangcodes` (or null when missing).
     *   - Resolver wired → iterate the chain lazily; first non-null hit wins; cache the
     *     resolved langcode. Exhaustion caches `null` and returns `null`.
     *
     * The cache short-circuits repeat reads of the same field: when an entry already exists
     * in `$fieldLangcodes`, we read directly from the cached langcode without re-invoking the
     * resolver (FR-039 / Definition-of-Done: "repeat-read short-circuit").
     */
    public function get(string $name): mixed
    {
        if (!$this->getEntityType()->isTranslatable()) {
            return parent::get($name);
        }

        if (!$this->isTranslatableField($name)) {
            return parent::get($name);
        }

        if (\array_key_exists($name, $this->fieldLangcodes)) {
            $cached = $this->fieldLangcodes[$name];

            return $cached === null ? null : $this->loadFieldValue($name, $cached);
        }

        $active = $this->activeLangcode();

        if ($this->fallbackResolver === null) {
            $value = $this->loadFieldValue($name, $active);
            $this->fieldLangcodes[$name] = $value !== null ? $active : null;

            return $value;
        }

        foreach ($this->fallbackResolver->resolve($active, $this) as $lc) {
            $value = $this->loadFieldValue($name, $lc);
            if ($value !== null) {
                $this->fieldLangcodes[$name] = $lc;

                return $value;
            }
        }

        $this->fieldLangcodes[$name] = null;

        return null;
    }

    // -------------------------------------------------------------------------
    // Private translatable-read helpers
    // -------------------------------------------------------------------------

    /**
     * Load the raw stored value for `$fieldName` at `$langcode`.
     *
     * Searches the per-langcode `$translationData` bag first; falls back to
     * `parent::get()` when the requested langcode coincides with the entity's
     * default — that's where storage stamps "default-langcode values" during
     * hydration.
     */
    private function loadFieldValue(string $fieldName, string $langcode): mixed
    {
        if (isset($this->translationData[$langcode]) && \array_key_exists($fieldName, $this->translationData[$langcode])) {
            return $this->translationData[$langcode][$fieldName];
        }

        if ($langcode === $this->defaultLangcode()) {
            return parent::get($fieldName);
        }

        return null;
    }

    /**
     * Decide whether `$fieldName` is a translatable field on this entity type.
     *
     * The entity-storage trait must not import from the higher-layer `waaseyaa/field`
     * package (FieldDefinition lives there). Instead we duck-type the field metadata:
     * any value returned from `EntityTypeInterface::getFieldDefinitions()` that exposes
     * a `isTranslatable(): bool` method is consulted; missing definitions, missing
     * methods, or absent entries all resolve to "not translatable" and the read flows
     * through `parent::get()` unchanged (NFR-001 invariant).
     */
    private function isTranslatableField(string $fieldName): bool
    {
        $definitions = $this->getEntityType()->getFieldDefinitions();
        $candidate = $definitions[$fieldName] ?? null;

        // FieldDefinitionInterface lives alongside us in `waaseyaa/field` (layer-1
        // sibling). The PHPDoc return type of `getFieldDefinitions()` already
        // guarantees the value (when present) implements `isTranslatable(): bool`,
        // so we read it directly without a `method_exists` narrowing.
        return $candidate !== null && $candidate->isTranslatable();
    }

    // -------------------------------------------------------------------------
    // Private guard
    // -------------------------------------------------------------------------

    private function assertTranslatable(): void
    {
        if (!$this->getEntityType()->isTranslatable()) {
            throw EntityTranslationException::notTranslatable(
                $this->getEntityType()->id(),
            );
        }
    }
}

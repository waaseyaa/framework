<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

/**
 * Entities implementing this support multiple languages.
 *
 * Unlike Drupal, a Waaseyaa entity object represents ONE language at a time.
 * getTranslation() returns a separate entity object for the requested language.
 * This simplification removes hidden state and makes field values unambiguous.
 *
 * Invariants:
 * - activeLangcode() MUST equal defaultLangcode() for an entity loaded via find($id) without getTranslation() chained.
 * - removeTranslation($lc) MUST throw EntityTranslationException::cannotRemoveDefault() when $lc === defaultLangcode().
 * - addTranslation($lc) MUST throw EntityTranslationException::translationAlreadyExists() when hasTranslation($lc) === true.
 * - getTranslation($lc) MUST throw EntityTranslationException::translationNotFound() when hasTranslation($lc) === false.
 * - translations() MUST yield the default langcode first, then remaining langcodes in ascending lexicographic order.
 * - All methods MUST throw EntityTranslationException::notTranslatable() when called on an entity whose type has translatable: false.
 *
 * @see \Waaseyaa\Entity\Exception\EntityTranslationException
 * @api
 */
interface TranslatableInterface
{
    /**
     * Returns the default langcode for this entity (the canonical source language).
     *
     * Corresponds to the `default_langcode` entity key value.
     *
     * @throws \Waaseyaa\Entity\Exception\EntityTranslationException If no default_langcode is set.
     */
    public function defaultLangcode(): string;

    /**
     * Returns the currently active langcode (the language this entity object represents).
     *
     * Equals defaultLangcode() unless this object was produced by getTranslation() or addTranslation().
     */
    public function activeLangcode(): string;

    /**
     * The active language code, with a lenient backward-compatibility fallback.
     *
     * Unlike {@see activeLangcode()} — which throws when an entity has no
     * `default_langcode` — this returns the `langcode` value (or `'en'`) for
     * entities that never set a default. The two are supported, distinct
     * accessors: use activeLangcode() when a missing default should be an error,
     * and language() when a lenient fallback is wanted (e.g. reading the langcode
     * of a non-translatable entity that inherits this contract via
     * ContentEntityBase).
     */
    public function language(): string;

    /**
     * Returns true when a translation exists for the given langcode.
     */
    public function hasTranslation(string $langcode): bool;

    /**
     * Returns an entity object representing the requested translation.
     *
     * Returns $this when $langcode === activeLangcode(). Otherwise returns a clone
     * with activeLangcode set to $langcode.
     *
     * @throws \Waaseyaa\Entity\Exception\EntityTranslationException If the translation does not exist.
     */
    public function getTranslation(string $langcode): static;

    /**
     * Registers a new translation langcode and returns a clone active in that language.
     *
     * The new translation starts with empty field values; the caller populates them via set().
     *
     * @throws \Waaseyaa\Entity\Exception\EntityTranslationException If the langcode already exists.
     */
    public function addTranslation(string $langcode): static;

    /**
     * Marks a translation for deletion on next save.
     *
     * @throws \Waaseyaa\Entity\Exception\EntityTranslationException If $langcode equals defaultLangcode().
     */
    public function removeTranslation(string $langcode): void;

    /**
     * Yields all langcodes for which a translation exists.
     *
     * Default langcode is yielded first; remaining langcodes follow in ascending lexicographic order.
     *
     * @return iterable<string>
     */
    public function translations(): iterable;

    /**
     * Returns all translation langcodes as an array.
     *
     * This is an alias of translations() materialized as an array.
     *
     * @return string[]
     */
    public function getTranslationLanguages(): array;

    /**
     * Returns the stored langcode for a specific field on this translation.
     *
     * Returns `null` when the field has no per-language override and falls back
     * to the entity's default langcode, or when the field name is not recognized.
     *
     * @param string $fieldName The field machine name.
     */
    public function fieldLangcode(string $fieldName): ?string;
}

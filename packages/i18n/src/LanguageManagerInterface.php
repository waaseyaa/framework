<?php

declare(strict_types=1);

namespace Waaseyaa\I18n;

/**
 * Manages the set of available languages in the system.
 * @api
 */
interface LanguageManagerInterface
{
    /**
     * Returns the default language.
     */
    public function getDefaultLanguage(): Language;

    /**
     * Returns a language by its ID, or null if not found.
     */
    public function getLanguage(string $id): ?Language;

    /**
     * Returns all available languages, keyed by language ID.
     *
     * @return array<string, Language>
     */
    public function getLanguages(): array;

    /**
     * Returns the current language (used as a general default).
     */
    public function getCurrentLanguage(): Language;

    /**
     * Sets the current language. Must be a language known to this manager.
     */
    public function setCurrentLanguage(Language $language): void;

    /**
     * Resets the current language to the system default.
     *
     * **Worker mode (FrankenPHP, Swoole, RoadRunner):** Call this at the start
     * of each request to prevent the previous request's language from bleeding
     * into the next request when the manager is shared across requests.
     */
    public function resetToDefault(): void;

    /**
     * Returns the fallback chain for a given langcode.
     *
     * Example: getFallbackChain('fr-CA') might return ['fr-CA', 'fr', 'en'].
     * The chain always ends with the default language.
     *
     * @return string[]
     */
    public function getFallbackChain(string $langcode): array;

    /**
     * Returns true if more than one language is configured.
     */
    public function isMultilingual(): bool;
}

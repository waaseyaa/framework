<?php

declare(strict_types=1);

namespace Waaseyaa\I18n;

final class Translator implements TranslatorInterface
{
    /**
     * Locale codes that may be used to build a translation file path.
     *
     * Restricted to BCP-47-safe characters (ASCII letters, digits, hyphen) so a
     * caller- or negotiator-supplied locale — and any langcode the fallback chain
     * derives from it by string-splitting — can never introduce a directory
     * separator, "..", a null byte, or any other path-traversal payload into the
     * `require` in {@see loadTranslations()}. Legitimate locales such as "en",
     * "fr-CA", and "oj-x-sagamok" pass; anything else is treated as a missing
     * translation (handled by the fallback chain), never a fatal error.
     */
    private const LOCALE_PATTERN = '/^[A-Za-z0-9-]+$/D';

    /** @var array<string, array<string, string>> Loaded translations keyed by locale */
    private array $loaded = [];

    /**
     * @param string $translationsPath Directory containing lang files (e.g., /path/to/resources/lang)
     */
    public function __construct(
        private readonly string $translationsPath,
        private readonly LanguageManagerInterface $languageManager,
    ) {}

    public function trans(string $key, array $params = [], ?string $locale = null): string
    {
        $locale ??= $this->getLocale();
        $chain = $this->languageManager->getFallbackChain($locale);

        foreach ($chain as $langcode) {
            $translations = $this->loadTranslations($langcode);
            if (isset($translations[$key]) && $translations[$key] !== '') {
                return $this->replaceParams($translations[$key], $params);
            }
        }

        // Key not found in any language — return the key itself
        return $this->replaceParams($key, $params);
    }

    public function has(string $key, ?string $locale = null): bool
    {
        $locale ??= $this->getLocale();
        $translations = $this->loadTranslations($locale);

        return isset($translations[$key]);
    }

    public function getLocale(): string
    {
        return $this->languageManager->getCurrentLanguage()->id;
    }

    /**
     * @return array<string, string>
     */
    private function loadTranslations(string $locale): array
    {
        if (isset($this->loaded[$locale])) {
            return $this->loaded[$locale];
        }

        // Security guard: never let an unvalidated locale reach `require`. The
        // locale (caller-supplied via trans(), or derived by the fallback chain)
        // flows straight into the file path below, so a value like
        // "../../etc/passwd" would `require` — i.e. execute — an arbitrary PHP
        // file. Reject anything that is not BCP-47-safe and treat it as a
        // cache-miss so the fallback chain continues to a legitimate language.
        if (preg_match(self::LOCALE_PATTERN, $locale) !== 1) {
            $this->loaded[$locale] = [];
            return [];
        }

        $file = $this->translationsPath . '/' . $locale . '.php';

        if (!is_file($file)) {
            $this->loaded[$locale] = [];
            return [];
        }

        // Defense-in-depth: confine the resolved file to the translations dir so
        // a symlink inside the dir cannot redirect `require` to a file outside it.
        $resolved = realpath($file);
        $base = realpath($this->translationsPath);
        if ($resolved === false || $base === false
            || !str_starts_with($resolved, $base . \DIRECTORY_SEPARATOR)) {
            $this->loaded[$locale] = [];
            return [];
        }

        /** @var array<string, string> $translations */
        $translations = require $resolved;

        if (!is_array($translations)) {
            $this->loaded[$locale] = [];
            return [];
        }

        $this->loaded[$locale] = $translations;

        return $translations;
    }

    /**
     * @param array<string, string> $params
     */
    private function replaceParams(string $message, array $params): string
    {
        if ($params === []) {
            return $message;
        }

        $replacements = [];
        foreach ($params as $paramKey => $value) {
            $replacements['{' . $paramKey . '}'] = $value;
        }

        return strtr($message, $replacements);
    }
}

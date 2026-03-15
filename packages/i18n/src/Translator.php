<?php

declare(strict_types=1);

namespace Waaseyaa\I18n;

final class Translator implements TranslatorInterface
{
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
            if (isset($translations[$key])) {
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

        $file = $this->translationsPath . '/' . $locale . '.php';

        if (!is_file($file)) {
            $this->loaded[$locale] = [];
            return [];
        }

        /** @var array<string, string> $translations */
        $translations = require $file;

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

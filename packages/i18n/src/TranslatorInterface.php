<?php

declare(strict_types=1);

namespace Waaseyaa\I18n;

interface TranslatorInterface
{
    /**
     * Translate a key, with optional parameter substitution.
     *
     * @param string $key Translation key (e.g., 'nav.communities')
     * @param array<string, string> $params Replacement parameters (e.g., ['name' => 'John'])
     * @param string|null $locale Override locale (null = use current language)
     * @return string Translated string, or the key itself if not found
     */
    public function trans(string $key, array $params = [], ?string $locale = null): string;

    /**
     * Check if a translation key exists for a locale.
     */
    public function has(string $key, ?string $locale = null): bool;

    /**
     * Get the current locale code.
     */
    public function getLocale(): string;
}

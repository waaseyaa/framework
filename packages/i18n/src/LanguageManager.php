<?php

declare(strict_types=1);

namespace Aurora\I18n;

/**
 * Default implementation of LanguageManagerInterface.
 *
 * Holds a static collection of Language objects. The current language
 * defaults to the system default language.
 */
final class LanguageManager implements LanguageManagerInterface
{
    /** @var array<string, Language> */
    private array $languages;

    private Language $defaultLanguage;

    private Language $currentLanguage;

    /** @var array<string, string[]> Custom fallback mappings (langcode => list of fallback langcodes) */
    private array $fallbackMap;

    /**
     * @param Language[] $languages    List of available languages. Exactly one must have isDefault=true.
     * @param array<string, string[]> $fallbackMap  Optional custom fallback mappings.
     */
    public function __construct(array $languages, array $fallbackMap = [])
    {
        if ($languages === []) {
            throw new \InvalidArgumentException('At least one language must be provided.');
        }

        $this->languages = [];
        $default = null;

        foreach ($languages as $language) {
            $this->languages[$language->id] = $language;
            if ($language->isDefault) {
                if ($default !== null) {
                    throw new \InvalidArgumentException(
                        sprintf('Multiple default languages found: "%s" and "%s".', $default->id, $language->id),
                    );
                }
                $default = $language;
            }
        }

        if ($default === null) {
            throw new \InvalidArgumentException('Exactly one language must be marked as default (isDefault: true).');
        }

        $this->defaultLanguage = $default;
        $this->currentLanguage = $default;
        $this->fallbackMap = $fallbackMap;
    }

    public function getDefaultLanguage(): Language
    {
        return $this->defaultLanguage;
    }

    public function getLanguage(string $id): ?Language
    {
        return $this->languages[$id] ?? null;
    }

    /**
     * @return array<string, Language>
     */
    public function getLanguages(): array
    {
        return $this->languages;
    }

    public function getCurrentLanguage(): Language
    {
        return $this->currentLanguage;
    }

    /**
     * Sets the current language. Must be a language known to this manager.
     */
    public function setCurrentLanguage(Language $language): void
    {
        if (!isset($this->languages[$language->id])) {
            throw new \InvalidArgumentException(
                sprintf('Language "%s" is not registered in this manager.', $language->id),
            );
        }
        $this->currentLanguage = $language;
    }

    /**
     * @return string[]
     */
    public function getFallbackChain(string $langcode): array
    {
        $chain = [$langcode];

        // Check custom fallback map first.
        if (isset($this->fallbackMap[$langcode])) {
            foreach ($this->fallbackMap[$langcode] as $fallback) {
                if (!\in_array($fallback, $chain, true)) {
                    $chain[] = $fallback;
                }
            }
        } else {
            // Auto-derive parent from regional variant: "fr-CA" -> "fr".
            $parts = explode('-', $langcode);
            if (\count($parts) > 1) {
                array_pop($parts);
                $parent = implode('-', $parts);
                if (!\in_array($parent, $chain, true)) {
                    $chain[] = $parent;
                }
            }
        }

        // Always end with the default language.
        if (!\in_array($this->defaultLanguage->id, $chain, true)) {
            $chain[] = $this->defaultLanguage->id;
        }

        return $chain;
    }

    public function isMultilingual(): bool
    {
        return \count($this->languages) > 1;
    }
}

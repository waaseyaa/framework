<?php

declare(strict_types=1);

namespace Aurora\Routing\Language;

use Aurora\I18n\LanguageContext;
use Aurora\I18n\LanguageManagerInterface;

/**
 * Composite language negotiator that runs strategies in priority order.
 *
 * Strategies are tried in the order provided to the constructor. The first
 * strategy that returns a valid, available language wins. If no strategy
 * matches, the default language from LanguageManager is used.
 *
 * Typical strategy priority:
 *   1. URL prefix (explicit user choice)
 *   2. Accept-Language header (client preference)
 *   3. Default language (fallback)
 *
 * The negotiator produces a LanguageContext that downstream services consume.
 */
final class LanguageNegotiator
{
    /** @var LanguageNegotiatorInterface[] */
    private readonly array $negotiators;

    /**
     * @param LanguageNegotiatorInterface[] $negotiators Strategies in priority order.
     * @param LanguageManagerInterface $languageManager Provides available languages and default.
     */
    public function __construct(
        array $negotiators,
        private readonly LanguageManagerInterface $languageManager,
    ) {
        $this->negotiators = array_values($negotiators);
    }

    /**
     * Negotiate the language for the given request data.
     *
     * Runs each strategy in priority order. Returns a LanguageContext using
     * the first matched language. Falls back to the default language if no
     * strategy produces a match.
     *
     * @param string $pathInfo The URL path (e.g., "/fr/content/about").
     * @param array<string, string> $headers HTTP headers with lowercased keys.
     */
    public function negotiate(string $pathInfo, array $headers = []): LanguageContext
    {
        $availableLanguages = array_keys($this->languageManager->getLanguages());
        $defaultLanguage = $this->languageManager->getDefaultLanguage();

        foreach ($this->negotiators as $negotiator) {
            $langcode = $negotiator->negotiate($pathInfo, $headers, $availableLanguages);

            if ($langcode !== null) {
                $language = $this->languageManager->getLanguage($langcode);
                if ($language !== null) {
                    return new LanguageContext(
                        contentLanguage: $language,
                        interfaceLanguage: $language,
                    );
                }
            }
        }

        // No strategy matched -- use the default language.
        return new LanguageContext(
            contentLanguage: $defaultLanguage,
            interfaceLanguage: $defaultLanguage,
        );
    }
}

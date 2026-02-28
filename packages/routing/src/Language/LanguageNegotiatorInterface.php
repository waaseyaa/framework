<?php

declare(strict_types=1);

namespace Aurora\Routing\Language;

/**
 * Strategy interface for language negotiation.
 *
 * Each implementation detects a language from a different source
 * (URL prefix, Accept-Language header, etc.). Returns null if the
 * strategy cannot determine a language from the request.
 */
interface LanguageNegotiatorInterface
{
    /**
     * Attempt to resolve a language code from request data.
     *
     * @param string $pathInfo The URL path (e.g., "/fr/content/about").
     * @param array<string, string> $headers HTTP headers, lowercased keys.
     * @param string[] $availableLanguages List of valid language codes.
     * @return string|null The resolved language code, or null if undetermined.
     */
    public function negotiate(string $pathInfo, array $headers, array $availableLanguages): ?string;
}

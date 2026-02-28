<?php

declare(strict_types=1);

namespace Aurora\Routing\Language;

/**
 * Resolves language from URL path prefix.
 *
 * Looks for a language code as the first path segment:
 *   /fr/content/about  -> "fr"
 *   /en-US/api/node/42 -> "en-US"
 *   /content/about     -> null (no language prefix)
 *
 * Only matches against the provided list of available language codes.
 */
final class UrlPrefixNegotiator implements LanguageNegotiatorInterface
{
    public function negotiate(string $pathInfo, array $headers, array $availableLanguages): ?string
    {
        $segments = explode('/', ltrim($pathInfo, '/'));

        if ($segments === [] || $segments[0] === '') {
            return null;
        }

        $prefix = $segments[0];

        if (in_array($prefix, $availableLanguages, true)) {
            return $prefix;
        }

        return null;
    }
}

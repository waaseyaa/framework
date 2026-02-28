<?php

declare(strict_types=1);

namespace Aurora\Routing\Language;

/**
 * Resolves language from the Accept-Language HTTP header.
 *
 * Parses the Accept-Language header per RFC 7231, respecting quality values.
 * Example: "Accept-Language: fr-FR,fr;q=0.9,en;q=0.8"
 *
 * The algorithm tries each preferred language (sorted by quality, descending):
 *   1. Exact match against available languages (e.g., "fr-FR").
 *   2. Base language match (e.g., "fr-FR" -> try "fr").
 *
 * Returns the first match found, or null if none match.
 */
final class AcceptHeaderNegotiator implements LanguageNegotiatorInterface
{
    public function negotiate(string $pathInfo, array $headers, array $availableLanguages): ?string
    {
        $header = $headers['accept-language'] ?? '';

        if ($header === '') {
            return null;
        }

        $parsed = $this->parseAcceptLanguageHeader($header);

        if ($parsed === []) {
            return null;
        }

        // Sort by quality descending, preserving order for equal quality.
        usort($parsed, static function (array $a, array $b): int {
            $cmp = $b['quality'] <=> $a['quality'];
            if ($cmp !== 0) {
                return $cmp;
            }
            return $a['order'] <=> $b['order'];
        });

        foreach ($parsed as $entry) {
            $langcode = $entry['langcode'];

            // Wildcard means "any language" -- skip, let default handle it.
            if ($langcode === '*') {
                continue;
            }

            // Try exact match first.
            if (in_array($langcode, $availableLanguages, true)) {
                return $langcode;
            }

            // Try base language (e.g., "fr-FR" -> "fr").
            $baseLang = $this->getBaseLanguage($langcode);
            if ($baseLang !== null && in_array($baseLang, $availableLanguages, true)) {
                return $baseLang;
            }
        }

        return null;
    }

    /**
     * Parse the Accept-Language header into an array of langcode + quality pairs.
     *
     * @return array<int, array{langcode: string, quality: float, order: int}>
     */
    private function parseAcceptLanguageHeader(string $header): array
    {
        $result = [];
        $parts = explode(',', $header);

        foreach ($parts as $order => $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $segments = explode(';', $part);
            $langcode = trim($segments[0]);

            if ($langcode === '') {
                continue;
            }

            $quality = 1.0;

            for ($i = 1, $count = count($segments); $i < $count; $i++) {
                $param = trim($segments[$i]);
                if (str_starts_with($param, 'q=')) {
                    $qValue = substr($param, 2);
                    if (is_numeric($qValue)) {
                        $quality = (float) $qValue;
                        $quality = max(0.0, min(1.0, $quality));
                    }
                }
            }

            $result[] = [
                'langcode' => $langcode,
                'quality' => $quality,
                'order' => $order,
            ];
        }

        return $result;
    }

    /**
     * Extract the base language from a regional variant.
     *
     * "fr-FR" -> "fr", "en-US" -> "en", "fr" -> null
     */
    private function getBaseLanguage(string $langcode): ?string
    {
        $pos = strpos($langcode, '-');
        if ($pos === false) {
            return null;
        }

        return substr($langcode, 0, $pos);
    }
}

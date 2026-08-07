<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Projection;

/**
 * Normalizes untrusted CMS field values into inert, plain searchable text.
 *
 * Search text is data, never instructions: script/style/template payloads and
 * HTML comments are dropped with their content, remaining markup is stripped
 * with word boundaries preserved, entities are decoded, and any angle
 * brackets that survive (or are re-introduced by entity decoding) are
 * scrubbed so the output cannot round-trip back into markup. Invalid UTF-8
 * is repaired and whitespace is collapsed.
 *
 * @api
 */
final class SearchTextNormalizer
{
    private function __construct() {}

    public static function normalize(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface || is_array($value) || is_object($value) && !$value instanceof \Stringable) {
            return '';
        }
        if (is_bool($value) || $value === null) {
            return '';
        }
        $text = (string) $value;
        if ($text === '') {
            return '';
        }

        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        // Drop executable/style payloads and comments WITH their content.
        $text = (string) preg_replace(
            '#<(script|style|template)\b[^>]*>(?:.*?</\1\s*>|.*\z)#is',
            ' ',
            $text,
        );
        $text = (string) preg_replace('#<!--.*?-->#s', ' ', $text);
        // Strip syntactically complete tags while preserving literal less-than
        // prose such as "5 < 10" and word boundaries between elements.
        $text = (string) preg_replace('#</?[a-z][^>]*>#is', ' ', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Entity decoding can re-introduce brackets; keep the output inert.
        $text = str_replace(['<', '>'], ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}

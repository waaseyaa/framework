<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation;

/**
 * Unicode-preserving slug generator.
 *
 * Waaseyaa is an Indigenous-language CMS: slugs MUST preserve Unicode
 * letters (long-vowel diacritics, the glottal ʼ U+02BC, Canadian
 * syllabics) and MUST NOT transliterate to ASCII — transliteration
 * destroys meaning in Anishinaabemowin orthography. Unicode slugs are
 * percent-encoded on the wire and decoded by the router before matching.
 *
 * @api
 */
final class SlugGenerator
{
    public static function generate(string $value): string
    {
        $value = trim($value);

        // NFC-normalize so decomposed input (base letter + combining mark)
        // produces the same slug as its precomposed form.
        $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);
        if (is_string($normalized)) {
            $value = $normalized;
        }

        $slug = mb_strtolower($value, 'UTF-8');
        $replaced = preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug);
        if (!is_string($replaced)) {
            // Invalid UTF-8: degrade to the historical byte-wise ASCII
            // slugging rather than failing the caller.
            $replaced = (string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value));
        }

        return trim($replaced, '-');
    }
}

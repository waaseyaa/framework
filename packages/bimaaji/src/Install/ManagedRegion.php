<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * The delimited region of a generated file that `bimaaji:install` owns.
 *
 * Every {@see TargetFile} the framework transformers emit frames its
 * generated payload between two marker lines:
 *
 * ```
 * <!-- waaseyaa:bimaaji:install BEGIN -->
 * …generated…
 * <!-- waaseyaa:bimaaji:install END -->
 * ```
 *
 * On a re-run the install command replaces **only** the text between
 * those markers in an existing file and preserves every byte outside
 * them verbatim. That is what makes the install marker-bounded rather
 * than merely idempotent: a consumer may write their own notes above or
 * below the block (and, for a Claude skill file, retitle the YAML
 * frontmatter that sits above the opening marker) and keep those edits
 * across upgrades.
 *
 * A file with no markers is treated as wholly hand-authored. The command
 * falls back to the pre-existing overwrite contract there — an
 * interactive `Overwrite <path>?` prompt, or `--force` — so this class
 * never silently rewrites a file it does not recognise.
 *
 * @api
 */
final class ManagedRegion
{
    public const string BEGIN = '<!-- waaseyaa:bimaaji:install BEGIN -->';
    public const string END = '<!-- waaseyaa:bimaaji:install END -->';

    /**
     * Wrap generated content in the marker pair.
     */
    public static function wrap(string $generated): string
    {
        return self::BEGIN . "\n\n" . trim($generated) . "\n\n" . self::END . "\n";
    }

    /**
     * Extract the text between the markers, markers excluded.
     *
     * Returns null when the document does not carry exactly one
     * well-ordered marker pair — an ambiguous document is never spliced.
     */
    public static function extract(string $document): ?string
    {
        $bounds = self::bounds($document);
        if ($bounds === null) {
            return null;
        }

        [$beginAt, $endAt] = $bounds;
        $innerFrom = $beginAt + \strlen(self::BEGIN);

        return substr($document, $innerFrom, $endAt - $innerFrom);
    }

    /**
     * Replace `$existing`'s managed region with the one carried by
     * `$generated`, preserving everything outside the markers.
     *
     * Returns null when either document lacks a usable marker pair; the
     * caller then falls back to whole-file overwrite semantics.
     */
    public static function splice(string $existing, string $generated): ?string
    {
        $existingBounds = self::bounds($existing);
        $incoming = self::extract($generated);
        if ($existingBounds === null || $incoming === null) {
            return null;
        }

        [$beginAt, $endAt] = $existingBounds;
        $innerFrom = $beginAt + \strlen(self::BEGIN);

        return substr($existing, 0, $innerFrom) . $incoming . substr($existing, $endAt);
    }

    /**
     * Byte offsets of a single well-formed marker pair, or null.
     *
     * @return array{int, int}|null [offset of BEGIN, offset of END]
     */
    private static function bounds(string $document): ?array
    {
        if (substr_count($document, self::BEGIN) !== 1 || substr_count($document, self::END) !== 1) {
            return null;
        }

        $beginAt = strpos($document, self::BEGIN);
        $endAt = strpos($document, self::END);
        if ($beginAt === false || $endAt === false || $endAt <= $beginAt) {
            return null;
        }

        return [$beginAt, $endAt];
    }
}

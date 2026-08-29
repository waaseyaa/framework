<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site;

/**
 * Containment test for generated-artifact targets.
 *
 * Extracted from `SiteInitializationService` so the Windows-shaped comparison
 * is unit-testable on a Linux runner. The containment check appended a forward
 * slash to both operands, but `realpath()` returns backslash-separated paths on
 * Windows, so `C:\proj\tests` was not recognized as inside `C:\proj` and every
 * newly created target directory was rejected.
 *
 * @internal
 */
final class SitePathContainment
{
    /**
     * Whether `$path` is `$root` itself or lies beneath it.
     *
     * Both operands are compared with normalized separators. This is a
     * lexical test on already-resolved paths; callers resolve symlinks with
     * `realpath()` before calling, because normalization alone cannot make a
     * containment claim safe.
     */
    public static function contains(string $root, string $path): bool
    {
        $root = self::normalize($root);
        $path = self::normalize($path);

        return $path === $root || str_starts_with($path . '/', $root . '/');
    }

    private static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}

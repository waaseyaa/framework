<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Support;

/**
 * Refuse to run the test suite under a temp directory that would make every
 * `sys_get_temp_dir() . '/waaseyaa_*_' . uniqid()` helper write into the
 * repository root (#2927).
 *
 * PHP returns `TMPDIR` verbatim: `TMPDIR=.` yields `sys_get_temp_dir() === '.'`,
 * so every scratch path resolves against the process working directory —
 * the checkout — and a killed or timed-out run leaves its scratch behind as
 * empty directories `git status` never reports. The producer trace for #2927
 * could not recover the invocation that set such a value, so the bootstrap
 * fails closed on the mechanism instead of on one guessed trigger.
 *
 * A repository-internal but non-root temp directory (for example `<root>/tmp`)
 * is deliberately accepted: it is gitignored, allowlisted by
 * `bin/check-repo-root-hygiene`, and a legitimate local choice.
 */
final class TempDirGuard
{
    /**
     * @return string|null a human-readable violation, or null when the temp
     *                     directory is safe to use for scratch files
     */
    public static function violation(string $tempDir, string $repositoryRoot, ?bool $windows = null): ?string
    {
        $windows ??= \DIRECTORY_SEPARATOR === '\\';

        if ($tempDir === '' || !self::isFullyQualified($tempDir, $windows)) {
            return sprintf(
                'sys_get_temp_dir() resolved to the non-absolute path %s — every test scratch path would land in the process working directory (the repository root). Unset TMPDIR or point it at an absolute directory (#2927).',
                json_encode($tempDir, JSON_UNESCAPED_SLASHES),
            );
        }

        if (self::normalize($tempDir) === self::normalize($repositoryRoot)) {
            return sprintf(
                'sys_get_temp_dir() resolved to the repository root %s — every test scratch path would land in the checkout. Unset TMPDIR or point it at a directory outside the repository root (#2927).',
                json_encode($tempDir, JSON_UNESCAPED_SLASHES),
            );
        }

        return null;
    }

    /**
     * Platform-aware: the answer depends on the OS the process runs on, not on
     * what the string looks like.
     *
     * POSIX: only a leading "/" is absolute. "C:\Temp", "\\server\share" and
     * "\Temp" are ordinary relative names there — `TMPDIR=C:\Temp` on Linux/WSL
     * makes PHP return that literal, `realpath()` fails, and every scratch path
     * resolves against the cwd, i.e. the repository root.
     *
     * Windows: fully qualified means drive-rooted ("C:\", "C:/") or UNC
     * ("\\server\share", "//server/share"). Drive-relative ("C:Temp") and
     * current-drive-rooted ("\Temp", "/Temp") names depend on per-drive process
     * state and are rejected too.
     */
    private static function isFullyQualified(string $path, bool $windows): bool
    {
        if (!$windows) {
            return str_starts_with($path, '/');
        }

        if (preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1) {
            return true;
        }

        return preg_match('~^[\\\\/]{2}[^\\\\/]+[\\\\/][^\\\\/]+~', $path) === 1;
    }

    private static function normalize(string $path): string
    {
        $resolved = realpath($path);
        $path = $resolved === false ? $path : $resolved;
        $path = str_replace('\\', '/', $path);

        return $path === '/' ? $path : rtrim($path, '/');
    }
}

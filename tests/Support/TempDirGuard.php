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
    public static function violation(string $tempDir, string $repositoryRoot): ?string
    {
        if ($tempDir === '' || !self::isAbsolute($tempDir)) {
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

    private static function isAbsolute(string $path): bool
    {
        return preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/])~', $path) === 1;
    }

    private static function normalize(string $path): string
    {
        $resolved = realpath($path);
        $path = $resolved === false ? $path : $resolved;
        $path = str_replace('\\', '/', $path);

        return $path === '/' ? $path : rtrim($path, '/');
    }
}

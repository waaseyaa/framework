<?php

declare(strict_types=1);

namespace Waaseyaa\FrankenPhp\Binary;

/**
 * Resolves the FrankenPHP binary to an absolute path WITHOUT ever putting the
 * FrankenPHP install directory on PATH.
 *
 * Why never PATH: the official FrankenPHP Windows release bundles its own
 * `php.exe` with OpenSSL/cURL disabled. Putting that directory on PATH shadows
 * the system PHP and breaks Composer. Resolving an absolute path and exec-ing it
 * directly means the directory is never needed on PATH.
 *
 * Resolution order (first hit wins):
 *   1. `FRANKENPHP_BIN` env var — explicit override (must exist, or it throws so
 *      a typo fails loudly instead of silently falling through).
 *   2. The managed install path written by `frankenphp:install` (package-owned,
 *      e.g. `<project>/vendor/bin/frankenphp[.exe]`).
 *   3. Known per-OS install locations.
 *   4. `frankenphp` discoverable on PATH (resolved to its absolute path).
 *   5. Not found → null. The caller surfaces the one actionable fix
 *      ({@see installHint()} / {@see notFoundMessage()}).
 *
 * Pure (`resolve()` takes injected probes); the I/O wiring lives in the command.
 *
 * @api
 */
final class BinaryResolver
{
    /**
     * @param string|null            $envBin     value of FRANKENPHP_BIN (null/'' when unset)
     * @param string|null            $managedBin absolute managed-install path, or null when not applicable
     * @param string                 $home       user home (USERPROFILE on Windows, HOME on POSIX)
     * @param bool                   $isWindows  target OS family
     * @param callable(string): bool $fileExists probe: does this absolute path exist as a file?
     * @param callable(): ?string    $pathLookup absolute path of `frankenphp` on PATH, or null
     *
     * @return string|null absolute binary path, or null when nothing is found
     *
     * @throws \RuntimeException only when FRANKENPHP_BIN is set but points at a missing file
     */
    public static function resolve(
        ?string $envBin,
        ?string $managedBin,
        string $home,
        bool $isWindows,
        callable $fileExists,
        callable $pathLookup,
    ): ?string {
        // 1. Explicit override always wins — but must exist (typo fails loudly).
        if ($envBin !== null && $envBin !== '') {
            if (!$fileExists($envBin)) {
                throw new \RuntimeException(sprintf(
                    'FRANKENPHP_BIN is set to "%s" but no file exists there. '
                    . 'Point it at the absolute path of the frankenphp binary, or unset it to use the managed install.',
                    $envBin,
                ));
            }

            return $envBin;
        }

        // 2. Managed install path (frankenphp:install).
        if ($managedBin !== null && $managedBin !== '' && $fileExists($managedBin)) {
            return $managedBin;
        }

        // 3. Known per-OS install locations.
        foreach (self::knownLocations($home, $isWindows) as $candidate) {
            if ($fileExists($candidate)) {
                return $candidate;
            }
        }

        // 4. On PATH — resolved to an absolute path (so we still exec by full path).
        $onPath = $pathLookup();
        if ($onPath !== null && $onPath !== '') {
            return $onPath;
        }

        // 5. Not found.
        return null;
    }

    /**
     * Known absolute install locations, in priority order, per OS. Pure.
     *
     * @return list<string>
     */
    public static function knownLocations(string $home, bool $isWindows): array
    {
        if ($isWindows) {
            $home = rtrim($home, '\\/');

            return $home === '' ? [] : [$home . '\\.frankenphp\\frankenphp.exe'];
        }

        $locations = [
            '/usr/local/bin/frankenphp',
            '/usr/bin/frankenphp',
            '/opt/homebrew/bin/frankenphp',
        ];

        $home = rtrim($home, '/');
        if ($home !== '') {
            $locations[] = $home . '/.frankenphp/frankenphp';
        }

        return $locations;
    }

    /**
     * Subdir (under vendor/bin) holding the fully-extracted Windows SDK
     * (frankenphp.exe + its DLLs). POSIX installs a bare binary directly.
     */
    public const string WINDOWS_DIST_DIR = 'frankenphp-dist';

    /**
     * The base managed dir: a project's `vendor/bin`, where `frankenphp:install`
     * writes the binary (POSIX) or the dist subdir (Windows). Pure. Uses `/`
     * throughout — Windows PHP accepts forward slashes in all path APIs.
     */
    public static function managedBaseDir(string $projectRoot): string
    {
        return rtrim($projectRoot, '\\/') . '/vendor/bin';
    }

    /**
     * The managed binary path written by `frankenphp:install`. On Windows it
     * sits inside the extracted SDK dir (alongside its DLLs); on POSIX it is the
     * bare binary directly under vendor/bin. Pure.
     */
    public static function managedPath(string $projectRoot, bool $isWindows): string
    {
        $base = self::managedBaseDir($projectRoot);

        return $isWindows
            ? $base . '/' . self::WINDOWS_DIST_DIR . '/frankenphp.exe'
            : $base . '/frankenphp';
    }

    /**
     * The one command that fixes a missing binary.
     */
    public static function installHint(): string
    {
        return 'php vendor/bin/waaseyaa frankenphp:install';
    }

    /**
     * The actionable not-found message shown to the operator.
     */
    public static function notFoundMessage(): string
    {
        return 'FrankenPHP binary not found. Run `' . self::installHint() . '` to download it '
            . 'automatically, or set FRANKENPHP_BIN to an absolute frankenphp path. '
            . 'Do NOT add the FrankenPHP directory to PATH — its bundled php.exe would shadow your system PHP.';
    }

    /**
     * Resolve `frankenphp` on PATH to an absolute path, shell-free where possible.
     * Used as the default `$pathLookup` probe. (I/O — not covered by the pure unit test.)
     */
    public static function lookupOnPath(bool $isWindows): ?string
    {
        $command = $isWindows ? 'where frankenphp 2>NUL' : 'command -v frankenphp 2>/dev/null';
        $lines = [];
        $exit = 0;
        @exec($command, $lines, $exit);
        $first = $exit === 0 && isset($lines[0]) ? trim($lines[0]) : '';

        return $first !== '' && is_file($first) ? $first : null;
    }
}

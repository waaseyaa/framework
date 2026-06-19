<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Runtime;

/**
 * Resolves the FrankenPHP binary to an absolute path for the `composer dev`
 * launcher — WITHOUT ever adding the FrankenPHP install directory to PATH.
 *
 * Why this matters: the official FrankenPHP Windows release is a full PHP-for-
 * Windows SDK that ships its own `php.exe` with OpenSSL/cURL disabled. Putting
 * that directory on PATH (the old "put frankenphp on PATH" guidance) makes its
 * crippled `php.exe` shadow the system PHP and breaks Composer. By resolving an
 * absolute path here and exec-ing it directly, the launcher never needs the
 * directory on PATH, so nothing is shadowed.
 *
 * Resolution order: `FRANKENPHP_BIN` env var → known per-OS install locations →
 * `frankenphp` discoverable on PATH (resolved to its absolute path) → otherwise
 * a clear, actionable error naming `FRANKENPHP_BIN`.
 *
 * @api
 */
final class FrankenPhpLocator
{
    /**
     * Pure resolution. Returns an absolute path to the frankenphp binary.
     *
     * @param string|null            $envBin     value of FRANKENPHP_BIN (null or '' when unset)
     * @param string                 $home       user home dir (USERPROFILE on Windows, HOME on POSIX)
     * @param bool                   $isWindows  target OS family
     * @param callable(string): bool $fileExists probe: does this absolute path exist as a file?
     * @param callable(): ?string    $pathLookup absolute path of `frankenphp` on PATH, or null
     *
     * @throws \RuntimeException with an actionable message when nothing resolves
     */
    public static function locate(
        ?string $envBin,
        string $home,
        bool $isWindows,
        callable $fileExists,
        callable $pathLookup,
    ): string {
        // 1. Explicit override always wins — but must actually exist, so a typo
        //    fails loudly instead of silently falling through.
        if ($envBin !== null && $envBin !== '') {
            if (!$fileExists($envBin)) {
                throw new \RuntimeException(sprintf(
                    'FRANKENPHP_BIN is set to "%s" but no file exists there. '
                    . 'Point it at the absolute path of the frankenphp binary.',
                    $envBin,
                ));
            }

            return $envBin;
        }

        // 2. Known per-OS install locations (absolute paths).
        foreach (self::knownLocations($home, $isWindows) as $candidate) {
            if ($fileExists($candidate)) {
                return $candidate;
            }
        }

        // 3. On PATH — resolved to an absolute path so we still exec by full path
        //    (and never have to add the install dir to PATH ourselves).
        $onPath = $pathLookup();
        if ($onPath !== null && $onPath !== '') {
            return $onPath;
        }

        // 4. Nothing found — actionable error.
        throw new \RuntimeException(
            'FrankenPHP binary not found. Install FrankenPHP from https://frankenphp.dev, then either '
            . 'set FRANKENPHP_BIN to its absolute path (e.g. FRANKENPHP_BIN="' . self::primaryHint($home, $isWindows) . '"), '
            . 'or place it in a standard location. Do NOT add the FrankenPHP directory to PATH — '
            . 'its bundled php.exe would shadow your system PHP and break Composer.',
        );
    }

    /**
     * Known absolute install locations, in priority order, per OS. Pure.
     *
     * @return list<string>
     */
    public static function knownLocations(string $home, bool $isWindows): array
    {
        $locations = [];

        if ($isWindows) {
            $home = rtrim($home, '\\/');
            if ($home !== '') {
                $locations[] = $home . '\\.frankenphp\\frankenphp.exe';
            }

            return $locations;
        }

        $locations[] = '/usr/local/bin/frankenphp';
        $locations[] = '/usr/bin/frankenphp';
        $locations[] = '/opt/homebrew/bin/frankenphp';

        $home = rtrim($home, '/');
        if ($home !== '') {
            $locations[] = $home . '/.frankenphp/frankenphp';
        }

        return $locations;
    }

    /**
     * Resolve using the real process environment. Wires getenv/PHP_OS_FAMILY/
     * is_file and a `where`/`command -v` PATH lookup. (I/O — covered by the
     * end-to-end acceptance, not the unit test; the pure logic is in locate().)
     */
    public static function fromEnvironment(): string
    {
        $isWindows = \PHP_OS_FAMILY === 'Windows';
        $envBin = getenv('FRANKENPHP_BIN');
        $home = (string) getenv($isWindows ? 'USERPROFILE' : 'HOME');

        return self::locate(
            $envBin === false || $envBin === '' ? null : $envBin,
            $home,
            $isWindows,
            static fn(string $path): bool => is_file($path),
            static function () use ($isWindows): ?string {
                $command = $isWindows ? 'where frankenphp 2>NUL' : 'command -v frankenphp 2>/dev/null';
                $lines = [];
                $exit = 0;
                @exec($command, $lines, $exit);
                $first = $exit === 0 && isset($lines[0]) ? trim($lines[0]) : '';

                return $first !== '' && is_file($first) ? $first : null;
            },
        );
    }

    private static function primaryHint(string $home, bool $isWindows): string
    {
        $locations = self::knownLocations($home, $isWindows);

        return $locations[0] ?? ($isWindows ? 'C:\\path\\to\\frankenphp.exe' : '/path/to/frankenphp');
    }
}

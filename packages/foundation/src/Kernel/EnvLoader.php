<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

/**
 * Loads a .env file into the process environment.
 *
 * Resolution rules:
 * - Missing file is silently ignored — no error.
 * - Lines starting with # and blank lines are skipped.
 * - Only lines containing = are parsed.
 * - Values wrapped in matching " or ' quotes have the quotes stripped.
 * - Existing process env vars are never overwritten — PHP-FPM pool vars win.
 */
final class EnvLoader
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            $value = self::stripQuotes($value);

            // Never overwrite an already-set process env var.
            if (getenv($key) === false) {
                putenv("{$key}={$value}");
            }
        }
    }

    private static function stripQuotes(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[strlen($value) - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}

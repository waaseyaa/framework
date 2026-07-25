<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Maintenance;

/**
 * Single source of truth for maintenance-mode configuration, resolved from the
 * project root plus environment. Deliberately environment-only (no config store,
 * no database) so it resolves identically pre-boot in {@see \Waaseyaa\Foundation\Kernel\HttpKernel}
 * and in the CLI, and stays available while the database is mid-swap (#2122).
 *
 * Environment knobs:
 *  - `WAASEYAA_MAINTENANCE_FLAG` — flag file path (default `storage/maintenance.flag`).
 *  - `WAASEYAA_MAINTENANCE_HEALTH_PATH` — comma-separated exempt paths (default `/health`);
 *    an empty value disables the health exemption.
 *  - `WAASEYAA_MAINTENANCE_TRUST_LOCALHOST` — exempt loopback clients (default `true`).
 *    Set to `false` behind a SAME-HOST reverse proxy (nginx/Apache ProxyPass to
 *    127.0.0.1, a documented FrankenPHP topology), where every external request
 *    arrives with `REMOTE_ADDR` = 127.0.0.1 and would otherwise be exempt —
 *    silently defeating maintenance mode for the entire internet.
 *  - `WAASEYAA_MAINTENANCE_PAGE` — path to a consumer-supplied branded HTML page.
 *
 * @api
 */
final class MaintenanceSettings
{
    private const int DEFAULT_RETRY_AFTER = 120;

    /**
     * @param list<string> $healthPaths
     */
    public function __construct(
        public readonly string $flagPath,
        public readonly array $healthPaths,
        public readonly bool $trustLocalhost,
        public readonly int $defaultRetryAfter,
        public readonly ?string $customPagePath,
    ) {}

    public static function fromEnvironment(string $projectRoot): self
    {
        $root = rtrim($projectRoot, '/');

        $flag = self::env('WAASEYAA_MAINTENANCE_FLAG');
        $flagPath = $flag !== null ? self::absolutize($flag, $root) : $root . '/storage/maintenance.flag';

        $healthRaw = self::env('WAASEYAA_MAINTENANCE_HEALTH_PATH') ?? '/health';
        $healthPaths = self::normalizePaths($healthRaw);

        $trust = self::env('WAASEYAA_MAINTENANCE_TRUST_LOCALHOST');
        $trustLocalhost = $trust === null ? true : filter_var($trust, FILTER_VALIDATE_BOOLEAN);

        $page = self::env('WAASEYAA_MAINTENANCE_PAGE');
        $customPagePath = $page !== null ? self::absolutize($page, $root) : null;

        return new self(
            flagPath: $flagPath,
            healthPaths: $healthPaths,
            trustLocalhost: $trustLocalhost,
            defaultRetryAfter: self::DEFAULT_RETRY_AFTER,
            customPagePath: $customPagePath,
        );
    }

    private static function env(string $key): ?string
    {
        $value = getenv($key);
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? '' : $trimmed;
    }

    /**
     * @return list<string>
     */
    private static function normalizePaths(string $raw): array
    {
        $paths = [];
        foreach (explode(',', $raw) as $candidate) {
            $trimmed = trim($candidate);
            if ($trimmed === '') {
                continue;
            }
            $normalized = '/' . trim($trimmed, '/');
            if ($normalized !== '/') {
                $paths[] = $normalized;
            }
        }

        return array_values(array_unique($paths));
    }

    private static function absolutize(string $path, string $root): string
    {
        if ($path === '') {
            return $root;
        }
        // Absolute POSIX path or Windows drive path — leave as-is.
        if ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return $path;
        }

        return $root . '/' . ltrim($path, '/');
    }
}

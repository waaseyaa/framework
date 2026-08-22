<?php

declare(strict_types=1);

namespace Waaseyaa\FrankenPhp;

/**
 * Smallest production-safe FrankenPHP worker-lane probe.
 *
 * Armed only by the exact process-environment token {@see PROCESS_ENV} =
 * {@see TOKEN} and SAPI {@see SAPI}. Request headers cannot activate it.
 * Environment-supplied filesystem paths are ignored. Missing test extras do
 * not throw: identity headers are emitted when armed, and repository-owned
 * extras load only when that exact file exists.
 *
 * Idle by default. The Framework repo front controller invokes this class via
 * a string `class_exists` so a core-only or packaged install without
 * waaseyaa/frankenphp stays inert.
 *
 * @api
 */
final class WorkerAcceptance
{
    public const string PROCESS_ENV = 'WAASEYAA_FRANKENPHP_ACCEPTANCE';

    public const string TOKEN = 'worker-lane-v1';

    public const string SAPI = 'frankenphp';

    private const string EXTRAS = '/tests/Acceptance/FrankenPhpWorker/probe.php';

    /**
     * @param array<string, mixed>|null $env
     * @param array<string, mixed>|null $server
     */
    public static function processToken(?array $env = null, ?array $server = null): string|false
    {
        $env ??= $_ENV;
        $server ??= $_SERVER;
        $candidates = [
            getenv(self::PROCESS_ENV),
            $env[self::PROCESS_ENV] ?? null,
            $server[self::PROCESS_ENV] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if ($candidate === self::TOKEN) {
                return $candidate;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $server
     */
    public static function apply(
        string $projectRoot,
        string|false|null $token = null,
        ?string $sapi = null,
        array $server = [],
        string|false|null $pathOverride = null,
    ): void {
        unset($pathOverride);
        $token ??= self::processToken(null, $server === [] ? null : $server);
        $sapi ??= \PHP_SAPI;
        if ($token !== self::TOKEN || $sapi !== self::SAPI) {
            return;
        }

        if (\PHP_SAPI !== 'cli') {
            header('X-Waaseyaa-Worker-Pid: ' . (string) getmypid());
            header('X-Waaseyaa-Acceptance-Sapi: ' . \PHP_SAPI);
        }

        $extras = $projectRoot . self::EXTRAS;
        if (is_file($extras)) {
            require $extras;
        }
    }
}

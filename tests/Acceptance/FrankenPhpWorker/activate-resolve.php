<?php

declare(strict_types=1);

/**
 * Pure resolution for the FrankenPHP worker-lane probe.
 * Request headers and environment-supplied paths cannot choose the fixture.
 */

if (!defined('WAASEYAA_FRANKENPHP_ACCEPTANCE_TOKEN')) {
    define('WAASEYAA_FRANKENPHP_ACCEPTANCE_TOKEN', 'worker-lane-v1');
}
if (!defined('WAASEYAA_FRANKENPHP_ACCEPTANCE_SAPI')) {
    define('WAASEYAA_FRANKENPHP_ACCEPTANCE_SAPI', 'frankenphp');
}

if (!function_exists('waaseyaa_frankenphp_acceptance_process_token')) {
    /**
     * @return string|false
     */
    function waaseyaa_frankenphp_acceptance_process_token(): string|false
    {
        $candidates = [
            getenv('WAASEYAA_FRANKENPHP_ACCEPTANCE'),
            $_ENV['WAASEYAA_FRANKENPHP_ACCEPTANCE'] ?? null,
            $_SERVER['WAASEYAA_FRANKENPHP_ACCEPTANCE'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if ($candidate === WAASEYAA_FRANKENPHP_ACCEPTANCE_TOKEN) {
                return $candidate;
            }
        }

        return false;
    }
}

if (!function_exists('waaseyaa_frankenphp_acceptance_resolve')) {
    /**
     * @param array<string, mixed> $server
     *
     * @return string Absolute path of the repository probe to require.
     */
    function waaseyaa_frankenphp_acceptance_resolve(
        string $projectRoot,
        string|false $token,
        string $sapi,
        array $server,
        string|false $pathOverride,
    ): string {
        unset($server, $pathOverride);

        if ($token !== WAASEYAA_FRANKENPHP_ACCEPTANCE_TOKEN) {
            throw new RuntimeException(
                'FrankenPHP acceptance activator invoked without the exact worker-lane token.',
            );
        }

        if ($sapi !== WAASEYAA_FRANKENPHP_ACCEPTANCE_SAPI) {
            throw new RuntimeException(
                'FrankenPHP acceptance probe requires SAPI ' . WAASEYAA_FRANKENPHP_ACCEPTANCE_SAPI . ', got ' . $sapi . '.',
            );
        }

        $probe = $projectRoot . '/tests/Acceptance/FrankenPhpWorker/probe.php';
        if (!is_file($probe)) {
            throw new RuntimeException('FrankenPHP acceptance probe fixture is missing.');
        }

        return $probe;
    }
}

<?php

declare(strict_types=1);

/**
 * Test-only FrankenPHP worker-lane activator.
 *
 * The repo front controller requires this file only when
 * WAASEYAA_FRANKENPHP_ACCEPTANCE is exactly worker-lane-v1. The path is
 * repository-owned. Request headers cannot activate the lane.
 */

require_once __DIR__ . '/activate-resolve.php';

if (!isset($projectRoot) || !is_string($projectRoot) || $projectRoot === '') {
    $projectRoot = dirname(__DIR__, 3);
}
$probe = waaseyaa_frankenphp_acceptance_resolve(
    $projectRoot,
    waaseyaa_frankenphp_acceptance_process_token(),
    PHP_SAPI,
    $_SERVER,
    getenv('WAASEYAA_FRANKENPHP_ACCEPTANCE_PROBE'),
);
require $probe;

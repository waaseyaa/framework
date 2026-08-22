<?php

declare(strict_types=1);

/**
 * Test-only FrankenPHP acceptance prepend.
 *
 * Loaded solely via WAASEYAA_FRANKENPHP_ACCEPTANCE_PROBE (per worker request)
 * or the acceptance php.ini auto_prepend_file. Production autoload never
 * references this file.
 */

header('X-Waaseyaa-Worker-Pid: ' . (string) getmypid());
header('X-Waaseyaa-Acceptance-Sapi: ' . PHP_SAPI);

$community = $_SERVER['HTTP_X_WAASEYAA_ACCEPTANCE_COMMUNITY'] ?? '';
if (is_string($community) && $community !== '') {
    if (session_status() !== \PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['waaseyaa_community_id'] = $community;
    header('X-Waaseyaa-Community-Seen: ' . $community);
}

$injectLeak = getenv('WAASEYAA_FRANKENPHP_LEAK_PROOF');
if ($injectLeak === '1' || $injectLeak === 'true') {
    require __DIR__ . '/leak.php';
}

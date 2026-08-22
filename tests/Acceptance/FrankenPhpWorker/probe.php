<?php

declare(strict_types=1);

/**
 * Test-only FrankenPHP acceptance extras.
 *
 * Loaded by Waaseyaa\FrankenPhp\WorkerAcceptance only when this exact
 * repository path exists. Production package installs do not ship it.
 * Identity PID/SAPI headers live on the Framework-owned probe class.
 */

$community = $_SERVER['HTTP_X_WAASEYAA_ACCEPTANCE_COMMUNITY'] ?? '';
if (is_string($community) && $community !== '') {
    if (session_status() !== \PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['waaseyaa_community_id'] = $community;
    if (\PHP_SAPI !== 'cli') {
        header('X-Waaseyaa-Community-Seen: ' . $community);
    }
}

$injectLeak = getenv('WAASEYAA_FRANKENPHP_LEAK_PROOF');
if ($injectLeak === '1' || $injectLeak === 'true') {
    require __DIR__ . '/leak.php';
}

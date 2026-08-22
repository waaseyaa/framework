<?php

declare(strict_types=1);

/**
 * FrankenPHP-SAPI helper: write the field-access preflight artifact with the
 * same embedded PHP that later serves public/index.php.
 */
$projectRoot = dirname(__DIR__, 3);
$argv = [__FILE__, $projectRoot];
require __DIR__ . '/prepare-preflight.php';

if (!function_exists('frankenphp_handle_request')) {
    return;
}

$ok = static function (): void {
    http_response_code(200);
    echo "preflight-ready\n";
};

try {
    while (\frankenphp_handle_request($ok)) {
        gc_collect_cycles();
    }
} catch (\Throwable $e) {
    if (!str_contains($e->getMessage(), 'not in worker mode')) {
        throw $e;
    }
}

<?php

declare(strict_types=1);

// Front controller. Works in three modes:
//   1. FrankenPHP worker mode  — boots once, then loops via
//      frankenphp_handle_request() so the app stays warm and requests are served
//      concurrently across threads (a long-lived SSE /api/broadcast stream pins
//      one thread while the rest stay responsive). This is the production runtime
//      (local demo, Web Networks, Waaseyaa Cloud).
//   2. FrankenPHP / FPM classic — single request per invocation.
//   3. php -S (cli-server)      — single request, with static-file passthrough.

if (PHP_SAPI === 'cli-server') {
    $path = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($path)) {
        return false;
    }
}

require __DIR__ . '/../vendor/autoload.php';

$projectRoot = dirname(__DIR__);
if (is_file($projectRoot . '/.env')) {
    try {
        (new \Symfony\Component\Dotenv\Dotenv())->loadEnv($projectRoot . '/.env', 'APP_ENV', 'production');
    } catch (\Symfony\Component\Dotenv\Exception\FormatException|\Symfony\Component\Dotenv\Exception\PathException $e) {
        http_response_code(500);
        error_log('Waaseyaa: Failed to load .env: ' . $e->getMessage());
        echo 'Application configuration error. Check server logs.';
        exit(1);
    }
}

// A fresh kernel is built per request so no container/entity state bleeds across
// requests handled by the same long-lived worker.
$handler = static function () use ($projectRoot): void {
    $kernel = new \Waaseyaa\Foundation\Kernel\HttpKernel($projectRoot);
    $response = $kernel->handle();
    $response->send();
};

if (function_exists('frankenphp_handle_request')) {
    ignore_user_abort(true);

    // Optional recycle bound; 0 = unlimited.
    $maxRequests = (int) (getenv('FRANKENPHP_WORKER_MAX_REQUESTS') ?: 0);

    for ($handled = 0; !$maxRequests || $handled < $maxRequests; ++$handled) {
        $keepRunning = \frankenphp_handle_request($handler);
        gc_collect_cycles();
        if (!$keepRunning) {
            break;
        }
    }

    return;
}

$handler();

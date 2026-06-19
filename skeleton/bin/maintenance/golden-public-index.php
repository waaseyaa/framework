<?php

declare(strict_types=1);

// Front controller and runtime adapter — the single source of runtime awareness
// for the app. The SAME file boots correctly under three runtimes; only the
// request-loop wrapper differs:
//
//   1. FrankenPHP worker mode — boots the handler once, then loops via
//      frankenphp_handle_request() so the app stays warm and requests are served
//      concurrently across threads (a long-lived SSE /api/broadcast stream pins
//      one thread while the rest stay responsive). Launched by the NATIVE command
//      `frankenphp run` against config/frankenphp/Caddyfile (worker mode).
//   2. FrankenPHP / FPM classic — one request per invocation.
//   3. php -S (cli-server) — one request per invocation, with static-file
//      passthrough. This is what `waaseyaa serve` runs (single-worker dev only).
//
// Requires symfony/dotenv in composer.json (the waaseyaa skeleton includes it).

use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Kernel\HttpKernel;

if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

$projectRoot = dirname(__DIR__);

require $projectRoot . '/vendor/autoload.php';

if (is_file($projectRoot . '/.env')) {
    // Default a missing APP_ENV to production (not Symfony's implicit "dev").
    (new \Symfony\Component\Dotenv\Dotenv())->loadEnv($projectRoot . '/.env', 'APP_ENV', 'production');
}

// A fresh kernel is built per request so no container/entity state bleeds across
// requests handled by the same long-lived FrankenPHP worker.
$handler = static function () use ($projectRoot): void {
    try {
        $kernel = new HttpKernel($projectRoot);
        $response = $kernel->handle();
    } catch (\Throwable $e) {
        $payload = json_encode([
            'jsonapi' => ['version' => '1.1'],
            'errors' => [['status' => '500', 'title' => 'Internal Server Error', 'detail' => $e->getMessage()]],
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $response = new Response($payload, 500, ['Content-Type' => 'application/vnd.api+json']);
    }

    $response->send();
};

// FrankenPHP worker mode: boot once, then loop. Without this function we are
// under php -S or FPM — handle a single request and return.
if (function_exists('frankenphp_handle_request')) {
    ignore_user_abort(true);

    // Optional recycle bound; 0 = unlimited.
    $maxRequestsRaw = getenv('FRANKENPHP_WORKER_MAX_REQUESTS');
    $maxRequests = $maxRequestsRaw === false ? 0 : (int) $maxRequestsRaw;

    for ($handled = 0; $maxRequests === 0 || $handled < $maxRequests; ++$handled) {
        $keepRunning = \frankenphp_handle_request($handler);
        gc_collect_cycles();
        if (!$keepRunning) {
            break;
        }
    }

    return;
}

$handler();

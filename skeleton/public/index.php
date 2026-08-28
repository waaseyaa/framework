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
use Waaseyaa\Foundation\Kernel\EnvLoader;
use Waaseyaa\Foundation\Kernel\HttpKernel;

if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

$projectRoot = dirname(__DIR__);

require $projectRoot . '/vendor/autoload.php';

EnvLoader::load($projectRoot . '/.env');

// A fresh kernel is built per request so no container/entity state bleeds across
// requests handled by the same long-lived FrankenPHP worker.
$handler = static function () use ($projectRoot): void {
    try {
        $kernel = new HttpKernel($projectRoot);
        $response = $kernel->handle();
    } catch (\Throwable $e) {
        // Never leak the raw boot-failure message to the client outside debug —
        // it can carry a DB DSN, file paths, or other internals. Mirrors the
        // framework HttpKernel's debug-gated boot-failure rendering.
        $payload = json_encode([
            'jsonapi' => ['version' => '1.1'],
            'errors' => [[
                'status' => '500',
                'title' => 'Internal Server Error',
                'detail' => \App\Http\BootFailureResponder::detail($e, getenv('APP_DEBUG')),
            ]],
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $response = new Response($payload, 500, ['Content-Type' => 'application/vnd.api+json']);
    }

    $response->send();
};

// The shipped Caddy worker block sets this marker inside the worker process.
// Classic FrankenPHP also defines frankenphp_handle_request(); depending on the
// runtime build, calling it outside worker mode either throws or returns false.
// Function existence is therefore never a runtime-mode signal.
$workerMode = ($_SERVER['WAASEYAA_FRANKENPHP_WORKER'] ?? getenv('WAASEYAA_FRANKENPHP_WORKER')) === '1';
if ($workerMode) {
    if (!function_exists('frankenphp_handle_request')) {
        throw new \RuntimeException('FrankenPHP worker mode is enabled but the worker API is unavailable.');
    }

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

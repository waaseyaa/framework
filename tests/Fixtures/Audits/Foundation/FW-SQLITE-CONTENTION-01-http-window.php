<?php

declare(strict_types=1);

// Synthetic loopback HTTP fixture, not FETDER authentication or production media.
// Environment: FW3183_ARTIFACT_ROOT, FW3183_DATABASE (caller-owned disposable file).
// Run `php THIS_SCRIPT prepare`, then serve with `php -S 127.0.0.1:PORT THIS_SCRIPT`.
// Each server must use the same database and its artifact's own autoloader.
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\RateLimitMiddleware;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Foundation\RateLimit\DatabaseRateLimiter;

if (PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_URI'] ?? '') === '/ready') {
    echo 'ready';
    return;
}
$artifact = getenv('FW3183_ARTIFACT_ROOT');
$path = getenv('FW3183_DATABASE');
if (!is_string($artifact) || !is_string($path) || $artifact === '' || $path === '') {
    throw new RuntimeException('Set fixture artifact root and disposable database path.');
}
require $artifact . '/vendor/autoload.php';
$database = DBALDatabase::createSqlite($path, 'production');
try {
    if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'prepare') {
        $source = new ReflectionClass(DatabaseRateLimiter::class)->getFileName();
        $migration = require dirname($source, 3) . '/migrations/2026_08_12_000001_rate_limit_window_schema.php';
        $migration->up(new SchemaBuilder($database->getConnection()));
        echo "prepared\n";
        return;
    }
    $request = Request::createFromGlobals();
    // Public fixture label only; no real credential or account is used.
    if ($request->headers->get('Authorization') !== 'Bearer synthetic-fixture') {
        new Response('Fixture access refused.', 403)->send();
        return;
    }
    $handler = new class implements HttpHandlerInterface {
        public function handle(Request $request): Response
        {
            return new Response('synthetic fixture bytes', 200, ['Cache-Control' => 'private, no-store']);
        }
    };
    new RateLimitMiddleware(new DatabaseRateLimiter($database), 3, 600)->process($request, $handler)->send();
} finally {
    $database->getConnection()->close();
}

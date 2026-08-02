<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Http\RequestContext;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;
use Waaseyaa\Foundation\Kernel\HttpKernel;

/**
 * Boots a real kernel with a real `$_GET` and reports the `RequestContext` a
 * provider actually resolves (#2167).
 *
 * A subprocess per probe, so query state cannot leak between cases even in
 * principle.
 */

$projectRoot = $argv[1] ?? throw new RuntimeException('Missing project root.');
$query = json_decode($argv[2] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
$kind = $argv[3] ?? 'http';

require $projectRoot . '/vendor/autoload.php';

$_GET = is_array($query) ? $query : [];
$_SERVER = [
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/probe' . ($_GET === [] ? '' : '?' . http_build_query($_GET)),
    'QUERY_STRING' => http_build_query($_GET),
    'HTTP_HOST' => 'localhost',
    'SERVER_NAME' => 'localhost',
    'SERVER_PORT' => '80',
    'REMOTE_ADDR' => '127.0.0.1',
    'SCRIPT_NAME' => '/index.php',
    'SCRIPT_FILENAME' => $projectRoot . '/public/index.php',
];

$kernel = $kind === 'console' ? new ConsoleKernel($projectRoot) : new HttpKernel($projectRoot);
$kernel->bootForCli();

$resolved = false;
$params = [];

foreach ($kernel->getProviders() as $provider) {
    if (!method_exists($provider, 'resolve')) {
        continue;
    }

    try {
        $context = new ReflectionMethod($provider, 'resolve')->invoke($provider, RequestContext::class);
    } catch (Throwable) {
        continue;
    }

    if ($context instanceof RequestContext) {
        $resolved = true;
        $params = $context->getQueryParams();
        break;
    }
}

echo json_encode(['resolved' => $resolved, 'query' => $params], JSON_THROW_ON_ERROR), "\n";

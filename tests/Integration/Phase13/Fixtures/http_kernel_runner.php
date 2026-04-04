<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Kernel\HttpKernel;

if ($argc < 5) {
    fwrite(STDERR, "Usage: php http_kernel_runner.php <repo_root> <project_root> <method> <uri>\n");
    exit(1);
}

$repoRoot = (string) $argv[1];
$projectRoot = (string) $argv[2];
$method = strtoupper((string) $argv[3]);
$uri = (string) $argv[4];

require $repoRoot . '/vendor/autoload.php';

$parts = parse_url($uri);
$path = is_string($parts['path'] ?? null) ? $parts['path'] : '/';
$query = is_string($parts['query'] ?? null) ? $parts['query'] : '';

$_GET = [];
if ($query !== '') {
    parse_str($query, $_GET);
}
$_POST = [];
$_COOKIE = [];
$_FILES = [];
$_REQUEST = $_GET;
$_SERVER = [
    'REQUEST_METHOD' => $method,
    'REQUEST_URI' => $path . ($query !== '' ? '?' . $query : ''),
    'QUERY_STRING' => $query,
    'HTTP_HOST' => 'localhost',
    'SERVER_NAME' => 'localhost',
    'SERVER_PORT' => '80',
    'HTTPS' => 'off',
];

ob_start();
register_shutdown_function(static function (): void {
    $body = (string) ob_get_clean();
    $payload = [
        'status' => http_response_code(),
        'headers' => headers_list(),
        'body' => $body,
    ];

    echo json_encode($payload, JSON_THROW_ON_ERROR);
});

$kernel = new HttpKernel($projectRoot);
$response = $kernel->handle();
$response->send();

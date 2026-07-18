<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Kernel\HttpKernel;

if ($argc < 4) {
    fwrite(STDERR, "Usage: php consecutive_http_kernel_runner.php <repo_root> <project_root> <uri>\n");
    exit(1);
}

$repoRoot = (string) $argv[1];
$projectRoot = (string) $argv[2];
$uri = (string) $argv[3];

require $repoRoot . '/vendor/autoload.php';

$responses = [];
for ($request = 0; $request < 2; ++$request) {
    $_GET = [];
    $_POST = [];
    $_COOKIE = [];
    $_FILES = [];
    $_REQUEST = [];
    $_SERVER = [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => $uri,
        'QUERY_STRING' => '',
        'HTTP_HOST' => 'localhost',
        'SERVER_NAME' => 'localhost',
        'SERVER_PORT' => '80',
        'HTTPS' => 'off',
    ];

    $response = new HttpKernel($projectRoot)->handle();
    $responses[] = [
        'status' => $response->getStatusCode(),
        'body' => (string) $response->getContent(),
    ];
}

echo json_encode($responses, JSON_THROW_ON_ERROR);

<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Kernel\HttpKernel;

if ($argc < 4) {
    fwrite(STDERR, "Usage: authenticated_http_runner.php <project-root> <uri> <uid>\n");
    exit(2);
}

$projectRoot = (string) $argv[1];
$uri = (string) $argv[2];
$uid = (int) $argv[3];

require $projectRoot . '/vendor/autoload.php';

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
$sessionPath = $projectRoot . '/storage/sessions';
if (!is_dir($sessionPath) && !mkdir($sessionPath, 0o700, true)) {
    throw new RuntimeException('Could not create regression session directory.');
}
session_save_path($sessionPath);
session_id('media-field-read-regression');
session_start();
$_SESSION = [
    \Waaseyaa\User\Session\AuthenticatedSession::USER_ID_KEY => $uid,
    \Waaseyaa\User\Session\AuthenticatedSession::GENERATION_KEY => 0,
];
$_SERVER = [
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => $path . ($query !== '' ? '?' . $query : ''),
    'QUERY_STRING' => $query,
    'HTTP_HOST' => 'localhost',
    'HTTP_ACCEPT' => str_starts_with($path, '/api/') || str_starts_with($path, '/admin/_surface/')
        ? 'application/vnd.api+json'
        : '*/*',
    'SERVER_NAME' => 'localhost',
    'SERVER_PORT' => '80',
    'HTTPS' => 'off',
];
if (str_ends_with($path, '/view')) {
    $_SERVER['HTTP_SEC_FETCH_DEST'] = 'iframe';
    $_SERVER['HTTP_SEC_FETCH_MODE'] = 'navigate';
    $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
}

$response = new HttpKernel($projectRoot)->handle();
echo json_encode([
    'status' => $response->getStatusCode(),
    'headers' => $response->headers->all(),
    'body_base64' => base64_encode((string) $response->getContent()),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";

<?php

declare(strict_types=1);

// Route PHP display output to stderr immediately — this subprocess's stdout is a
// structured JSON/headers protocol channel. Any notice or deprecation that leaks
// to stdout (e.g. the PHP 8.5 deprecation for session.use_only_cookies) causes
// headers_sent() to return true, which makes session_start() fail and prevents
// CsrfMiddleware from emitting the XSRF-TOKEN Set-Cookie header. Sending display
// output to stderr keeps stdout clean regardless of the ambient php.ini setting,
// making local behaviour identical to CI (where display_errors=0 by default).
ini_set('display_errors', 'stderr');

/**
 * CSRF integration test runner.
 *
 * Accepts a JSON-encoded request descriptor via --json <value> or
 * --json-file <path>. The file form is preferred by integration tests because
 * it avoids platform-specific shell quoting limits for request bodies.
 * Supports session persistence across subprocess calls via a shared session
 * save path, enabling multi-request CSRF token round-trips in tests.
 *
 * Input JSON shape:
 * {
 *   "repo_root":     "/path/to/waaseyaa",
 *   "project_root":  "/path/to/temp/project",
 *   "session_path":  "/path/to/session/dir",
 *   "session_id":    "abc123",          // optional; reuse across requests
 *   "provider_file": "/path/to/AppServiceProvider.php", // optional require
 *   "method":        "POST",
 *   "uri":           "/test/protected",
 *   "headers":       {"X-XSRF-TOKEN": "..."}, // optional
 *   "body":          "raw body string",         // optional
 *   "content_type":  "multipart/form-data; boundary=---TestBoundary" // optional
 * }
 *
 * Output JSON (last line of stdout):
 * {
 *   "status":     200,
 *   "headers":    ["set-cookie: XSRF-TOKEN=...; Path=/; SameSite=Lax", ...],
 *   "body":       "...",
 *   "session_id": "abc123"
 * }
 */

use Waaseyaa\Foundation\Kernel\HttpKernel;

if (!isset($argv[1], $argv[2]) || !in_array($argv[1], ['--json', '--json-file'], true)) {
    fwrite(STDERR, "Usage: php csrf_kernel_runner.php --json '<json>'|--json-file <path>\n");
    exit(1);
}

$json = (string) $argv[2];
if ($argv[1] === '--json-file') {
    if (!is_file($json)) {
        fwrite(STDERR, "csrf_kernel_runner: JSON file does not exist\n");
        exit(1);
    }
    $contents = file_get_contents($json);
    if ($contents === false) {
        fwrite(STDERR, "csrf_kernel_runner: failed to read JSON file\n");
        exit(1);
    }
    $json = $contents;
}

$desc = json_decode($json, true);
if (!is_array($desc)) {
    fwrite(STDERR, "csrf_kernel_runner: invalid JSON input\n");
    exit(1);
}

$repoRoot      = (string) ($desc['repo_root'] ?? '');
$projectRoot   = (string) ($desc['project_root'] ?? '');
$sessionPath   = (string) ($desc['session_path'] ?? sys_get_temp_dir());
$sessionId     = (string) ($desc['session_id'] ?? '');
$providerFile  = (string) ($desc['provider_file'] ?? '');
$method        = strtoupper((string) ($desc['method'] ?? 'GET'));
$uri           = (string) ($desc['uri'] ?? '/');
$extraHeaders  = is_array($desc['headers'] ?? null) ? $desc['headers'] : [];
$body          = (string) ($desc['body'] ?? '');
$contentType   = (string) ($desc['content_type'] ?? '');

if ($repoRoot === '' || $projectRoot === '') {
    fwrite(STDERR, "csrf_kernel_runner: repo_root and project_root are required\n");
    exit(1);
}

// Prefer the project's own vendor/autoload.php when it exists (e.g. a
// custom wrapper that prepends worktree package sources). Fall back to
// the repo autoloader for plain setups.
$projectAutoload = $projectRoot . '/vendor/autoload.php';
$loaderPath = is_file($projectAutoload) ? $projectAutoload : $repoRoot . '/vendor/autoload.php';
require $loaderPath;

// Optionally require a fixture provider class before the kernel boots so that
// class_exists() succeeds when ProviderRegistry instantiates it.
if ($providerFile !== '' && is_file($providerFile)) {
    require_once $providerFile;
}

// Configure session to use a shared file-based store so session state
// persists across subprocess calls within the same test case.
session_save_path($sessionPath);
ini_set('session.use_cookies', '0');

// Parse URI.
$parts = parse_url($uri);
$path  = is_string($parts['path'] ?? null) ? $parts['path'] : '/';
$query = is_string($parts['query'] ?? null) ? $parts['query'] : '';

// Populate superglobals that HttpKernel and SessionMiddleware read.
$_GET    = [];
$_POST   = [];
$_COOKIE = [];
$_FILES  = [];

if ($query !== '') {
    parse_str($query, $_GET);
}

$_SERVER = [
    'REQUEST_METHOD'     => $method,
    'REQUEST_URI'        => $path . ($query !== '' ? '?' . $query : ''),
    'QUERY_STRING'       => $query,
    'HTTP_HOST'          => 'localhost',
    'SERVER_NAME'        => 'localhost',
    'SERVER_PORT'        => '80',
    'HTTPS'              => 'off',
    'REMOTE_ADDR'        => '127.0.0.1',
    'REQUEST_TIME_FLOAT' => microtime(true),
];

// Inject custom headers as HTTP_* superglobals (Symfony reads these).
foreach ($extraHeaders as $name => $value) {
    $key            = 'HTTP_' . strtoupper(str_replace('-', '_', (string) $name));
    $_SERVER[$key]  = (string) $value;
}

// Content-Type (Symfony reads CONTENT_TYPE, not HTTP_CONTENT_TYPE).
if ($contentType !== '') {
    $_SERVER['CONTENT_TYPE'] = $contentType;
}

// Content-Length for POST bodies.
if ($body !== '') {
    $_SERVER['CONTENT_LENGTH'] = (string) strlen($body);
}

// For multipart/form-data POSTs, parse the body into $_POST and $_FILES.
// PHP only auto-parses multipart in FPM/Apache; CLI needs manual parsing.
if ($method === 'POST' && str_contains($contentType, 'multipart/form-data') && $body !== '') {
    $boundaryMatch = [];
    if (preg_match('/boundary=([^\s;]+)/', $contentType, $boundaryMatch)) {
        $boundary = trim($boundaryMatch[1]);
        $segments = explode('--' . $boundary, $body);
        foreach ($segments as $segment) {
            $segment = ltrim($segment, "\r\n");
            if ($segment === '' || rtrim($segment) === '--') {
                continue;
            }
            $sepPos = strpos($segment, "\r\n\r\n");
            if ($sepPos === false) {
                continue;
            }
            $partHeaders = substr($segment, 0, $sepPos);
            $partBody    = rtrim(substr($segment, $sepPos + 4), "\r\n");

            // Parse Content-Disposition for field name and optional filename.
            $dispMatch = [];
            if (!preg_match('/Content-Disposition:[^\r\n]*name="([^"]+)"/i', $partHeaders, $dispMatch)) {
                continue;
            }
            $fieldName     = $dispMatch[1];
            $filenameMatch = [];

            if (preg_match('/filename="([^"]*)"/i', $partHeaders, $filenameMatch)) {
                // File upload part.
                $tmpFile = tempnam(sys_get_temp_dir(), 'csrf_upload_');
                file_put_contents((string) $tmpFile, $partBody);

                $ctMatch = [];
                $fileCt  = preg_match('/Content-Type:\s*([^\r\n]+)/i', $partHeaders, $ctMatch)
                    ? trim($ctMatch[1])
                    : 'application/octet-stream';

                $_FILES[$fieldName] = [
                    'name'     => $filenameMatch[1],
                    'type'     => $fileCt,
                    'tmp_name' => (string) $tmpFile,
                    'error'    => UPLOAD_ERR_OK,
                    'size'     => strlen($partBody),
                ];
            } else {
                // Regular POST field.
                $_POST[$fieldName] = $partBody;
            }
        }
    }
}

// Restore or start session.
if ($sessionId !== '') {
    session_id($sessionId);
}

// Boot the kernel and handle the request.
$kernel   = new HttpKernel($projectRoot);
$response = $kernel->handle();

// Capture session ID (may be newly generated on the first request).
$activeSessionId = session_id();

// Collect response headers from the Symfony response object (not PHP's
// headers_list(), which is unreliable in CLI subprocesses).
$responseHeaders = [];
foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
    foreach ($values as $value) {
        $responseHeaders[] = strtolower($name) . ': ' . $value;
    }
}
foreach ($response->headers->getCookies() as $cookie) {
    $responseHeaders[] = 'set-cookie: ' . $cookie->__toString();
}

$responseBody = $response->getContent();

echo json_encode([
    'status'     => $response->getStatusCode(),
    'headers'    => $responseHeaders,
    'body'       => $responseBody !== false ? $responseBody : '',
    'session_id' => $activeSessionId,
], JSON_THROW_ON_ERROR);

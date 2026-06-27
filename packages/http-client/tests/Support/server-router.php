<?php

declare(strict_types=1);

/**
 * Router for the `php -S` transport-test harness ({@see LocalHttpServer}).
 *
 * Logs every received request (method, uri, Authorization header) to the file
 * named in WAASEYAA_TEST_LOG so tests can assert what each host actually saw —
 * e.g. that a redirect target is NEVER contacted (credentials not re-sent
 * cross-host). Behaviour is keyed by path.
 */

$logFile = getenv('WAASEYAA_TEST_LOG');
if (is_string($logFile) && $logFile !== '') {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $auth = '';
    foreach ($headers as $k => $v) {
        if (strtolower((string) $k) === 'authorization') {
            $auth = (string) $v;
            break;
        }
    }
    if ($auth === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = (string) $_SERVER['HTTP_AUTHORIZATION'];
    }

    file_put_contents(
        $logFile,
        json_encode([
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'authorization' => $auth,
        ], JSON_THROW_ON_ERROR) . "\n",
        FILE_APPEND | LOCK_EX,
    );
}

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

switch ($path) {
    case '/redirect':
        $to = isset($_GET['to']) ? (string) $_GET['to'] : '/';
        header('Location: ' . $to, true, 302);
        echo 'REDIRECT-PAGE';
        break;

    case '/secret':
        echo 'LEAKED-SECRET';
        break;

    case '/big':
        $n = (int) ($_GET['n'] ?? 1_000_000);
        echo str_repeat('x', max(0, $n));
        break;

    default:
        echo 'OK';
}
